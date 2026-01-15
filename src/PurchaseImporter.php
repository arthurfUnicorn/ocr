<?php
// src/PurchaseImporter.php
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

        // Extract date from JSON file
        $invoiceDate = $this->extractDateFromJson($draft, $baseName);
        
        // Generate work time reference (09:00-18:00)
        $timestamp = strtotime($invoiceDate) + rand(9*3600, 18*3600);
        $referenceNo = 'pr-' . date('Ymd-His', $timestamp);

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

        // Upsert supplier
        $supplierId = $writeDb ? $this->upsertSupplier($supplierName) : 1;

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

          // Upsert product
          $productId = $writeDb ? $this->upsertProduct($code, $name, $unit) : null;

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

  private function extractDateFromJson(array $draft, string $filename): string {
    // Try to find date in original JSON
    $runId = $draft['run_id'] ?? '';
    $extractDir = $this->cfg['paths']['uploads'] . "/{$runId}_unzipped";
    $jsonPath = $extractDir . '/' . $filename;
    
    if (file_exists($jsonPath)) {
      $content = file_get_contents($jsonPath);
      // Match Chinese date pattern: 日期：2025-01-10
      if (preg_match('/æ—¥æœŸ[ï¼š:]\s*(\d{4}-\d{2}-\d{2})/', $content, $m)) {
        return $m[1];
      }
      // Match English date pattern
      if (preg_match('/date[:\s]+(\d{4}-\d{2}-\d{2})/i', $content, $m)) {
        return $m[1];
      }
    }
    
    // Fallback to today
    return date('Y-m-d');
  }

  private function upsertSupplier(string $name): int {
    $sel = $this->db->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
    $sel->execute([$name]);
    $row = $sel->fetch();
    if ($row) return (int)$row['id'];

    $now = Util::nowSql();
    $email = 'unknown+' . Util::slug($name) . '@example.com';

    $ins = $this->db->prepare("
      INSERT INTO suppliers (name, company_name, email, phone_number, address, city, state, postal_code, country, is_active, created_at, updated_at)
      VALUES (?, ?, ?, '0000000000', '-', '-', NULL, NULL, 'Hong Kong', 1, ?, ?)
    ");
    $ins->execute([$name, $name, $email, $now, $now]);
    return (int)$this->db->lastInsertId();
  }

  private function upsertProduct(string $code, string $name, float $cost): int {
    $sel = $this->db->prepare("SELECT id FROM products WHERE code = ? LIMIT 1");
    $sel->execute([$code]);
    $row = $sel->fetch();
    if ($row) return (int)$row['id'];

    $now = Util::nowSql();
    $ins = $this->db->prepare("
      INSERT INTO products (name, code, type, barcode_symbology, brand_id, category_id, unit_id, purchase_unit_id, sale_unit_id, cost, price, is_active, created_at, updated_at)
      VALUES (?, ?, 'standard', 'C128', NULL, 1, 1, 1, 1, ?, ?, 1, ?, ?)
    ");
    $ins->execute([$name, $code, $cost, $cost, $now, $now]);
    return (int)$this->db->lastInsertId();
  }

  private function insertPurchase(string $refNo, int $supplierId, string $doc, int $itemCount, array $items, ?float $decl, float $calc, string $date): int {
    $totalQty = 0.0;
    foreach ($items as $it) $totalQty += (float)($it['qty'] ?? 1);
    $grand = ($decl !== null) ? $decl : $calc;
    
    $timestamp = date('Y-m-d H:i:s', strtotime($date . ' ' . substr($refNo, -6, 2) . ':' . substr($refNo, -4, 2) . ':' . substr($refNo, -2)));

    $ins = $this->db->prepare("
      INSERT INTO purchases (
        reference_no, user_id, warehouse_id, supplier_id, currency_id, exchange_rate,
        item, total_qty, total_discount, total_tax, total_cost,
        order_tax_rate, order_tax, order_discount, shipping_cost,
        grand_total, paid_amount, status, payment_status, document, note,
        created_at, updated_at
      ) VALUES (?, 1, 2, ?, 1, 1, ?, ?, 0, 0, ?, 0, 0, 0, 0, ?, ?, 1, 2, ?, NULL, ?, ?)
    ");
    $ins->execute([$refNo, $supplierId, $itemCount, $totalQty, $grand, $grand, $grand, $doc, $timestamp, $timestamp]);
    return (int)$this->db->lastInsertId();
  }

  private function insertProductPurchase(int $purchaseId, int $productId, float $qty, float $unitCost, float $total, string $date): void {
    $timestamp = date('Y-m-d H:i:s', strtotime($date . ' 12:00:00'));
    
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