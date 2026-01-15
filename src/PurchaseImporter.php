<?php
// src/PurchaseImporter.php
// 修復版本 - 解決了 Column count doesn't match value count 錯誤
declare(strict_types=1);

final class PurchaseImporter {
  private PDO $db;
  private array $cfg;

  public function __construct(PDO $db, array $cfg) {
    $this->db = $db;
    $this->cfg = $cfg;
  }

  public function importDraft(array $draft, string $runId, bool $writeDb): array {
    $exportDir = $this->cfg['paths']['exports'] . "/$runId";
    Util::ensureDir($exportDir);

    $manifest = [];
    $failed = [];

    if ($writeDb) $this->db->beginTransaction();

    try {
      foreach (($draft['invoices'] ?? []) as $inv) {
        $baseName = (string)($inv['source_file'] ?? 'unknown.json');
        $supplierName = trim((string)($inv['supplier_name'] ?? ''));
        if ($supplierName === '') $supplierName = 'UNKNOWN_SUPPLIER';

        $items = $inv['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
          $failed[] = [
            'source_file' => $baseName,
            'supplier_name' => $supplierName,
            'declared_total' => $inv['declared_total'] ?? '',
            'reason' => 'NO_ITEMS_FOUND'
          ];
          continue;
        }

        // Get invoice date from draft (already extracted by parser/preview)
        $invoiceDate = $inv['invoice_date'] ?? date('Y-m-d');
        
        // Generate reference_no using invoice date (not current date)
        // Format: pr-YYYYMMDD-HHMMSS (random work time 09:00-18:00)
        $hour = str_pad((string)rand(9, 17), 2, '0', STR_PAD_LEFT);
        $min = str_pad((string)rand(0, 59), 2, '0', STR_PAD_LEFT);
        $sec = str_pad((string)rand(0, 59), 2, '0', STR_PAD_LEFT);
        $dateStr = str_replace('-', '', $invoiceDate);
        $referenceNo = "pr-{$dateStr}-{$hour}{$min}{$sec}";

        // Totals
        $decl = $inv['declared_total'];
        $decl = ($decl === '' || $decl === null) ? null : (float)$decl;

        $calc = 0.0;
        foreach ($items as $it) $calc += (float)($it['total'] ?? 0);

        // Tolerance check
        if ($decl !== null) {
          $diff = abs($decl - $calc);
          $rel = ($decl != 0.0) ? ($diff / abs($decl)) : $diff;
          if ($diff > $this->cfg['tolerance']['abs'] && $rel > $this->cfg['tolerance']['rel']) {
            $failed[] = [
              'source_file' => $baseName,
              'supplier_name' => $supplierName,
              'declared_total' => (string)$decl,
              'reason' => "TOTAL_MISMATCH declared={$decl} calc={$calc}"
            ];
            continue;
          }
        }

        // Check if supplier exists, if not create it
        $supplierId = $writeDb ? $this->getOrCreateSupplier($supplierName) : 1;

        // Insert purchase
        $purchaseId = $writeDb 
          ? $this->insertPurchase($referenceNo, $supplierId, $baseName, count($items), $items, $decl, $calc, $invoiceDate)
          : null;

        // Items
        foreach ($items as $it) {
          $code = trim((string)($it['code'] ?? ''));
          $name = trim((string)($it['name'] ?? ''));
          $qty  = (float)($it['qty'] ?? 1);
          $unit = (float)($it['unit_price'] ?? 0);
          $total= (float)($it['total'] ?? ($qty*$unit));

          if ($code === '') $code = $this->genCode($name !== '' ? $name : 'ITEM');
          if ($name === '') $name = $code;
          if ($qty <= 0) $qty = 1;

          // Check if product exists, if not create it
          $productId = $writeDb ? $this->getOrCreateProduct($code, $name, $unit) : null;

          if ($writeDb && $purchaseId && $productId) {
            $this->insertProductPurchase($purchaseId, $productId, $qty, $unit, $total, $invoiceDate);
          }
        }

        $manifest[] = [
          'source_file' => $baseName,
          'reference_no' => $referenceNo,
          'supplier_name' => $supplierName,
          'supplier_id' => (string)$supplierId,
          'items' => (string)count($items),
          'grand_total' => (string)($decl ?? $calc),
          'date' => $invoiceDate,
        ];
      }

      if ($writeDb) $this->db->commit();

    } catch (Throwable $e) {
      if ($writeDb) $this->db->rollBack();
      throw $e;
    }

    return [
      'ok' => true,
      'run_id' => $runId,
      'success' => count($manifest),
      'failed' => count($failed),
      'exports_dir' => $exportDir,
      'write_db' => $writeDb,
    ];
  }

  /**
   * Check if supplier exists by name, return ID if exists, otherwise create and return new ID
   */
  private function getOrCreateSupplier(string $name): int {
    // First check if exists
    $sel = $this->db->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
    $sel->execute([$name]);
    $row = $sel->fetch();
    
    if ($row) {
      return (int)$row['id'];
    }

    // Create new supplier
    // 修復: suppliers 表有 image 和 vat_number 欄位，需要包含在 INSERT 中或使用 DEFAULT
    $now = Util::nowSql();
    $email = 'unknown+' . Util::slug($name) . '@example.com';

    $ins = $this->db->prepare("
      INSERT INTO suppliers (name, image, company_name, vat_number, email, phone_number, address, city, state, postal_code, country, is_active, created_at, updated_at)
      VALUES (?, NULL, ?, NULL, ?, '0000000000', '-', '-', NULL, NULL, 'Hong Kong', 1, ?, ?)
    ");
    $ins->execute([$name, $name, $email, $now, $now]);
    
    return (int)$this->db->lastInsertId();
  }

  /**
   * Check if product exists by code, return ID if exists, otherwise create and return new ID
   */
  private function getOrCreateProduct(string $code, string $name, float $cost): int {
    // First check if exists
    $sel = $this->db->prepare("SELECT id FROM products WHERE code = ? LIMIT 1");
    $sel->execute([$code]);
    $row = $sel->fetch();
    
    if ($row) {
      return (int)$row['id'];
    }

    // Create new product
    $now = Util::nowSql();
    $ins = $this->db->prepare("
      INSERT INTO products (name, code, type, barcode_symbology, brand_id, category_id, unit_id, purchase_unit_id, sale_unit_id, cost, price, is_active, created_at, updated_at)
      VALUES (?, ?, 'standard', 'C128', NULL, 1, 1, 1, 1, ?, ?, 1, ?, ?)
    ");
    $ins->execute([$name, $code, $cost, $cost, $now, $now]);
    
    return (int)$this->db->lastInsertId();
  }

  /**
   * 修復: 原本的 INSERT 語句有 23 個欄位但只有 21 個值
   * 問題1: status 和 payment_status 被設為 ? 和 NULL，但這兩個欄位是 NOT NULL
   * 問題2: 缺少 created_at 和 updated_at 的值
   */
  private function insertPurchase(string $refNo, int $supplierId, string $doc, int $itemCount, array $items, ?float $decl, float $calc, string $date): int {
    $totalQty = 0.0;
    foreach ($items as $it) $totalQty += (float)($it['qty'] ?? 1);
    $grand = ($decl !== null) ? $decl : $calc;
    
    // Use invoice date for timestamp
    $timestamp = $date . ' ' . date('H:i:s');

    // 修復後的 INSERT 語句
    // - 23 個欄位對應 23 個值
    // - status = 1 (received/已收貨)
    // - payment_status = 2 (paid/已付款)
    // - note = NULL
    $ins = $this->db->prepare("
      INSERT INTO purchases (
        reference_no, user_id, warehouse_id, supplier_id, currency_id, exchange_rate,
        item, total_qty, total_discount, total_tax, total_cost,
        order_tax_rate, order_tax, order_discount, shipping_cost,
        grand_total, paid_amount, status, payment_status, document, note,
        created_at, updated_at
      ) VALUES (?, 1, 2, ?, 1, 1, ?, ?, 0, 0, ?, 0, 0, 0, 0, ?, ?, 1, 2, ?, NULL, ?, ?)
    ");
    // 10 個參數: refNo, supplierId, itemCount, totalQty, grand(total_cost), grand(grand_total), grand(paid_amount), doc, timestamp, timestamp
    $ins->execute([$refNo, $supplierId, $itemCount, $totalQty, $grand, $grand, $grand, $doc, $timestamp, $timestamp]);
    return (int)$this->db->lastInsertId();
  }

  private function insertProductPurchase(int $purchaseId, int $productId, float $qty, float $unitCost, float $total, string $date): void {
    $timestamp = $date . ' 12:00:00';
    
    $ins = $this->db->prepare("
      INSERT INTO product_purchases (
        purchase_id, product_id, product_batch_id, variant_id, imei_number,
        qty, recieved, return_qty, purchase_unit_id, net_unit_cost,
        discount, tax_rate, tax, total, created_at, updated_at
      ) VALUES (?, ?, NULL, NULL, NULL, ?, ?, 0, 1, ?, 0, 0, 0, ?, ?, ?)
    ");
    $ins->execute([$purchaseId, $productId, $qty, $qty, $unitCost, $total, $timestamp, $timestamp]);
  }

  private function genCode(string $name): string {
    $base = strtoupper(preg_replace('/[^A-Z0-9\x{4e00}-\x{9fff}]+/u', '_', $name));
    $base = substr($base, 0, 24) ?: 'ITEM';
    return $base . '_' . substr(md5($name), 0, 6);
  }
}


