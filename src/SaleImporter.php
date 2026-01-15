<?php
// src/SaleImporter.php
declare(strict_types=1);

final class SaleImporter {
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
        $customerName = trim((string)($inv['customer_name'] ?? ''));
        if ($customerName === '') $customerName = 'UNKNOWN_CUSTOMER';

        $items = $inv['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
          $failed[] = [
            'source_file' => $baseName,
            'customer_name' => $customerName,
            'declared_total' => $inv['declared_total'] ?? '',
            'reason' => 'NO_ITEMS_FOUND'
          ];
          continue;
        }

        // Get invoice date from draft (already extracted by parser/preview)
        $invoiceDate = $inv['invoice_date'] ?? date('Y-m-d');
        
        // Generate reference_no using invoice date (not current date)
        // Format: sr-YYYYMMDD-HHMMSS (random work time 09:00-18:00)
        $hour = str_pad((string)rand(9, 17), 2, '0', STR_PAD_LEFT);
        $min = str_pad((string)rand(0, 59), 2, '0', STR_PAD_LEFT);
        $sec = str_pad((string)rand(0, 59), 2, '0', STR_PAD_LEFT);
        $dateStr = str_replace('-', '', $invoiceDate);
        $referenceNo = "sr-{$dateStr}-{$hour}{$min}{$sec}";

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
              'customer_name' => $customerName,
              'declared_total' => (string)$decl,
              'reason' => "TOTAL_MISMATCH declared={$decl} calc={$calc}"
            ];
            continue;
          }
        }

        // Check if customer exists, if not create it
        $customerId = $writeDb ? $this->getOrCreateCustomer($customerName) : 1;

        // Insert sale
        $saleId = $writeDb 
          ? $this->insertSale($referenceNo, $customerId, $baseName, count($items), $items, $decl, $calc, $invoiceDate)
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

          // Check if product exists, if not create it (using sale price)
          $productId = $writeDb ? $this->getOrCreateProduct($code, $name, $unit) : null;

          if ($writeDb && $saleId && $productId) {
            $this->insertProductSale($saleId, $productId, $qty, $unit, $total, $invoiceDate);
          }
        }

        $manifest[] = [
          'source_file' => $baseName,
          'reference_no' => $referenceNo,
          'customer_name' => $customerName,
          'customer_id' => (string)$customerId,
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
   * Check if customer exists by name, return ID if exists, otherwise create and return new ID
   */
  private function getOrCreateCustomer(string $name): int {
    // First check if exists
    $sel = $this->db->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
    $sel->execute([$name]);
    $row = $sel->fetch();
    
    if ($row) {
      return (int)$row['id'];
    }

    // Create new customer
    $now = Util::nowSql();
    $email = 'unknown+' . Util::slug($name) . '@example.com';

    $ins = $this->db->prepare("
      INSERT INTO customers (customer_group_id, user_id, name, company_name, email, phone_number, address, city, state, postal_code, country, is_active, created_at, updated_at)
      VALUES (1, NULL, ?, ?, ?, '0000000000', '-', '-', NULL, NULL, 'Hong Kong', 1, ?, ?)
    ");
    $ins->execute([$name, $name, $email, $now, $now]);
    
    return (int)$this->db->lastInsertId();
  }

  /**
   * Check if product exists by code, return ID if exists, otherwise create and return new ID
   */
  private function getOrCreateProduct(string $code, string $name, float $price): int {
    // First check if exists
    $sel = $this->db->prepare("SELECT id FROM products WHERE code = ? LIMIT 1");
    $sel->execute([$code]);
    $row = $sel->fetch();
    
    if ($row) {
      return (int)$row['id'];
    }

    // Create new product (estimate cost as 70% of sale price)
    $now = Util::nowSql();
    $cost = round($price * 0.7, 2);
    
    $ins = $this->db->prepare("
      INSERT INTO products (name, code, type, barcode_symbology, brand_id, category_id, unit_id, purchase_unit_id, sale_unit_id, cost, price, is_active, created_at, updated_at)
      VALUES (?, ?, 'standard', 'C128', NULL, 1, 1, 1, 1, ?, ?, 1, ?, ?)
    ");
    $ins->execute([$name, $code, $cost, $price, $now, $now]);
    
    return (int)$this->db->lastInsertId();
  }

  private function insertSale(string $refNo, int $customerId, string $doc, int $itemCount, array $items, ?float $decl, float $calc, string $date): int {
    $totalQty = 0.0;
    foreach ($items as $it) $totalQty += (float)($it['qty'] ?? 1);
    $grand = ($decl !== null) ? $decl : $calc;
    
    // Use invoice date for timestamp
    $timestamp = $date . ' ' . date('H:i:s');

    $ins = $this->db->prepare("
      INSERT INTO sales (
        reference_no, user_id, cash_register_id, table_id, queue,
        customer_id, warehouse_id, biller_id, item, total_qty,
        total_discount, total_tax, total_price, grand_total, currency_id,
        exchange_rate, order_tax_rate, order_tax, order_discount_type, order_discount_value,
        order_discount, coupon_id, coupon_discount, shipping_cost,
        sale_status, payment_status, document, paid_amount, sale_note, staff_note,
        created_at, updated_at
      ) VALUES (?, 1, NULL, NULL, NULL, ?, 2, 1, ?, ?, 0, 0, ?, ?, 1, 1, 0, 0, 'Flat', 0, 0, NULL, NULL, 0, 1, 4, ?, ?, NULL, NULL, ?, ?)
    ");
    $ins->execute([$refNo, $customerId, $itemCount, $totalQty, $grand, $grand, $doc, $grand, $timestamp, $timestamp]);
    return (int)$this->db->lastInsertId();
  }

  private function insertProductSale(int $saleId, int $productId, float $qty, float $unitPrice, float $total, string $date): void {
    $timestamp = $date . ' 12:00:00';
    
    $ins = $this->db->prepare("
      INSERT INTO product_sales (
        sale_id, product_id, product_batch_id, variant_id, imei_number,
        qty, return_qty, sale_unit_id, net_unit_price,
        discount, tax_rate, tax, total, created_at, updated_at,
        is_delivered, is_packing
      ) VALUES (?, ?, NULL, NULL, NULL, ?, 0, 1, ?, 0, 0, 0, ?, ?, ?, NULL, NULL)
    ");
    $ins->execute([$saleId, $productId, $qty, $unitPrice, $total, $timestamp, $timestamp]);
  }

  private function genCode(string $name): string {
    $base = strtoupper(preg_replace('/[^A-Z0-9\x{4e00}-\x{9fff}]+/u', '_', $name));
    $base = substr($base, 0, 24) ?: 'ITEM';
    return $base . '_' . substr(md5($name), 0, 6);
  }
}
