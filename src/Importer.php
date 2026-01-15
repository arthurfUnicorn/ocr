<?php
declare(strict_types=1);

final class Importer {
  private PDO $db;
  private array $cfg;
  private DocParserInvoiceParser $parser;

  public function __construct(PDO $db, array $cfg) {
    $this->db = $db;
    $this->cfg = $cfg;
    $this->parser = new DocParserInvoiceParser();
  }

  public function run(string $dir, string $runId, bool $writeDb): array {
    $jsonFiles = Util::listFiles($dir, ['json']);
    $exportDir = $this->cfg['paths']['exports'] . "/$runId";
    Util::ensureDir($exportDir);

    $manifest = [];
    $failed = [];

    $suppliersCsv = [];
    $productsCsv = [];
    $purchasesCsv = [];
    $ppSqlRows = [];

    $seenSupplier = [];
    $seenProduct = [];

    $this->db->beginTransaction();
    try {
      foreach ($jsonFiles as $jf) {
        $baseName = basename($jf);
        $doc = Util::readJson($jf);

        $inv = $this->parser->parse($doc);

        if (empty($inv['items'])) {
          $failed[] = ['pdf_name'=>$baseName,'temp_doc_id'=>null,'declared_grand_total'=>$inv['declared_total'] ?? '', 'reason'=>'NO_ITEMS_FOUND'];
          continue;
        }

        $decl = $inv['declared_total'];
        $calc = (float)$inv['calc_total'];

        if ($decl !== null) {
          $diff = abs((float)$decl - $calc);
          $rel = ((float)$decl !== 0.0) ? $diff / abs((float)$decl) : $diff;
          if ($diff > $this->cfg['tolerance']['abs'] && $rel > $this->cfg['tolerance']['rel']) {
            $failed[] = [
              'pdf_name'=>$baseName,'temp_doc_id'=>null,'declared_grand_total'=>(string)$decl,
              'reason'=>"TOTAL_MISMATCH declared={$decl} calc={$calc} diff={$diff} rel={$rel} method=doc_parser_table"
            ];
            continue;
          }
        }

        $supplierName = $inv['supplier_name'] ?: 'UNKNOWN_SUPPLIER';

        // Upsert supplier
        $supplierId = $writeDb ? $this->upsertSupplier($supplierName) : null;

        // csv suppliers (dedup)
        $sKey = Util::slug($supplierName);
        if (!isset($seenSupplier[$sKey])) {
          $seenSupplier[$sKey] = 1;
          $suppliersCsv[] = $this->buildSupplierCsvRow($supplierName);
        }

        // Purchase reference
        $referenceNo = 'pr-' . date('Ymd-His') . '-' . substr(md5($baseName), 0, 6);

        // insert purchase
        $purchaseId = $writeDb ? $this->insertPurchase($referenceNo, $supplierId ?? $this->cfg['defaults']['user_id'], $baseName, $inv) : null;

        // items
        $totalQty = 0.0;
        $grand = ($decl !== null) ? (float)$decl : $calc;

        foreach ($inv['items'] as $it) {
          $code = trim((string)($it['code'] ?? ''));
          if ($code === '') $code = $this->genCode($it['name'] ?? 'ITEM');

          $name = (string)($it['name'] ?? $code);
          $qty = (float)($it['qty'] ?? 1);
          $unit = (float)($it['unit_price'] ?? 0);
          $total = (float)($it['total'] ?? ($qty*$unit));

          $totalQty += $qty;

          // Upsert product
          $productId = $writeDb ? $this->upsertProduct($code, $name, $unit) : null;

          // csv products (dedup)
          if (!isset($seenProduct[$code])) {
            $seenProduct[$code] = 1;
            $productsCsv[] = $this->buildProductCsvRow($code, $name, $unit);
          }

          if ($writeDb && $purchaseId && $productId) {
            $this->insertProductPurchase($purchaseId, $productId, $qty, $unit, $total);
          }

          // for SQL export (optional)
          $ppSqlRows[] = $this->buildProductPurchaseSqlRow($referenceNo, $code, $qty, $unit, $total);
        }

        $purchasesCsv[] = $this->buildPurchaseCsvRow($referenceNo, $supplierId ?? 1, count($inv['items']), $totalQty, $grand, $baseName);

        $manifest[] = [
          'pdf_name'=>$baseName,
          'reference_no'=>$referenceNo,
          'supplier_name'=>$supplierName,
          'supplier_id'=> (string)($supplierId ?? 1),
          'items'=> (string)count($inv['items']),
          'declared_total'=> ($decl!==null ? (string)$decl : ''),
          'calc_total'=> (string)$calc,
          'used_ocr'=> '1'
        ];
      }

      if ($writeDb) $this->db->commit();
      else $this->db->rollBack();

    } catch (Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }

    // Write export files
    $this->writeExports($exportDir, $purchasesCsv, $productsCsv, $suppliersCsv, $manifest, $failed, $ppSqlRows);

    return [
      'ok' => true,
      'run_id' => $runId,
      'json_files' => count($jsonFiles),
      'success' => count($manifest),
      'failed' => count($failed),
      'exports_dir' => $exportDir,
      'write_db' => $writeDb,
    ];
  }

  public function importDraft(array $draft, string $runId, bool $writeDb): array {
    $exportDir = $this->cfg['paths']['exports'] . "/$runId";
    Util::ensureDir($exportDir);

    $manifest = [];
    $failed = [];

    $suppliersCsv = [];
    $productsCsv = [];
    $purchasesCsv = [];
    $ppSqlRows = [];

    $seenSupplier = [];
    $seenProduct = [];

    if ($writeDb) $this->db->beginTransaction();

    try {
        foreach (($draft['invoices'] ?? []) as $inv) {
        $baseName = (string)($inv['source_file'] ?? 'unknown.json');
        $supplierName = trim((string)($inv['supplier_name'] ?? ''));
        if ($supplierName === '') $supplierName = 'UNKNOWN_SUPPLIER';

        $items = $inv['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $failed[] = ['pdf_name'=>$baseName,'temp_doc_id'=>null,'declared_grand_total'=>$inv['declared_total'] ?? '', 'reason'=>'NO_ITEMS_AFTER_EDIT'];
            continue;
        }

        // totals
        $decl = $inv['declared_total'];
        $decl = ($decl === '' || $decl === null) ? null : (float)$decl;

        $calc = 0.0;
        foreach ($items as $it) $calc += (float)($it['total'] ?? 0);

        // tolerance check（可保留；也可放宽）
        if ($decl !== null) {
            $diff = abs($decl - $calc);
            $rel = ($decl != 0.0) ? ($diff / abs($decl)) : $diff;
            if ($diff > $this->cfg['tolerance']['abs'] && $rel > $this->cfg['tolerance']['rel']) {
            $failed[] = [
                'pdf_name'=>$baseName,'temp_doc_id'=>null,'declared_grand_total'=>(string)$decl,
                'reason'=>"TOTAL_MISMATCH declared={$decl} calc={$calc} diff={$diff} rel={$rel} method=preview_edit"
            ];
            continue;
            }
        }

        $supplierId = $writeDb ? $this->upsertSupplier($supplierName) : null;

        // suppliers csv
        $sKey = Util::slug($supplierName);
        if (!isset($seenSupplier[$sKey])) {
            $seenSupplier[$sKey] = 1;
            $suppliersCsv[] = $this->buildSupplierCsvRow($supplierName);
        }

        $referenceNo = 'pr-' . date('Ymd-His') . '-' . substr(md5($baseName . $supplierName), 0, 6);

        $purchaseId = $writeDb
            ? $this->insertPurchase($referenceNo, (int)($supplierId ?? 1), $baseName, [
                'items'=>$items,
                'declared_total'=>$decl,
                'calc_total'=>$calc
            ])
            : null;

        $totalQty = 0.0;
        $grand = ($decl !== null) ? $decl : $calc;

        foreach ($items as $it) {
            $code = trim((string)($it['code'] ?? ''));
            $name = trim((string)($it['name'] ?? ''));
            $qty  = (float)($it['qty'] ?? 1);
            $unit = (float)($it['unit_price'] ?? 0);
            $total= (float)($it['total'] ?? ($qty*$unit));

            if ($code === '') $code = $this->genCode($name !== '' ? $name : 'ITEM');
            if ($name === '') $name = $code;
            if ($qty <= 0) $qty = 1;

            $totalQty += $qty;

            $productId = $writeDb ? $this->upsertProduct($code, $name, $unit) : null;

            if (!isset($seenProduct[$code])) {
            $seenProduct[$code] = 1;
            $productsCsv[] = $this->buildProductCsvRow($code, $name, $unit);
            }

            if ($writeDb && $purchaseId && $productId) {
            $this->insertProductPurchase($purchaseId, $productId, $qty, $unit, $total);
            }

            $ppSqlRows[] = $this->buildProductPurchaseSqlRow($referenceNo, $code, $qty, $unit, $total);
        }

        $purchasesCsv[] = $this->buildPurchaseCsvRow($referenceNo, (int)($supplierId ?? 1), count($items), $totalQty, $grand, $baseName);

        $manifest[] = [
            'pdf_name'=>$baseName,
            'reference_no'=>$referenceNo,
            'supplier_name'=>$supplierName,
            'supplier_id'=> (string)($supplierId ?? 1),
            'items'=> (string)count($items),
            'declared_total'=> ($decl!==null ? (string)$decl : ''),
            'calc_total'=> (string)round($calc,2),
            'used_ocr'=> '1'
        ];
        }

        if ($writeDb) $this->db->commit();

    } catch (Throwable $e) {
        if ($writeDb) $this->db->rollBack();
        throw $e;
    }

    // exports
    $this->writeExports($exportDir, $purchasesCsv, $productsCsv, $suppliersCsv, $manifest, $failed, $ppSqlRows);

    return [
        'ok' => true,
        'run_id' => $runId,
        'success' => count($manifest),
        'failed' => count($failed),
        'exports_dir' => $exportDir,
        'write_db' => $writeDb,
    ];
    }

  private function writeExports(string $dir, array $purchases, array $products, array $suppliers, array $manifest, array $failed, array $ppSqlRows): void {
    // headers (align to your existing format)
    $pCols = ['reference_no','user_id','warehouse_id','supplier_id','item','total_qty','grand_total','payment_status','status','document','created_at','updated_at'];
    $prCols= ['type','name','code','unit','cost','price','is_active','created_at','updated_at'];
    $sCols = ['name','company_name','email','phone_number','address','city','state','postal_code','country','is_active','created_at','updated_at'];

    CsvWriter::append("$dir/purchases_import.csv", $pCols, $purchases);
    CsvWriter::append("$dir/products_import.csv", $prCols, $products);
    CsvWriter::append("$dir/suppliers_import.csv", $sCols, $suppliers);

    CsvWriter::append("$dir/manifest.csv", ['pdf_name','reference_no','supplier_name','supplier_id','items','declared_total','calc_total','used_ocr'], $manifest);
    CsvWriter::append("$dir/failed_pdfs.csv", ['pdf_name','temp_doc_id','declared_grand_total','reason'], $failed);

    // product_purchases_import.sql（可选导出）
    $sql = "START TRANSACTION;\n";
    foreach ($ppSqlRows as $r) $sql .= $r . "\n";
    $sql .= "COMMIT;\n";
    file_put_contents("$dir/product_purchases_import.sql", $sql);
  }

  /******** DB Ops (你表名/字段不同就改这里) ********/

  private function upsertSupplier(string $name): int {
    // 以 name 唯一；你可改成 company_name 或其他 key
    $sel = $this->db->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
    $sel->execute([$name]);
    $row = $sel->fetch();
    if ($row) return (int)$row['id'];

    $now = Util::nowSql();
    $email = 'unknown+' . Util::slug($name) . '@example.com';

    $ins = $this->db->prepare("
      INSERT INTO suppliers (name, company_name, email, phone_number, address, city, state, postal_code, country, is_active, created_at, updated_at)
      VALUES (?, ?, ?, '0000000000', '-', '-', NULL, NULL, NULL, 1, ?, ?)
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
      INSERT INTO products (type, name, code, unit, cost, price, is_active, created_at, updated_at)
      VALUES ('standard', ?, ?, 'unit', ?, ?, 1, ?, ?)
    ");
    $ins->execute([$name, $code, $cost, $cost, $now, $now]);
    return (int)$this->db->lastInsertId();
  }

  private function insertPurchase(string $referenceNo, int $supplierId, string $document, array $inv): int {
    $now = Util::nowSql();
    $items = count($inv['items'] ?? []);
    $totalQty = 0.0;
    foreach ($inv['items'] as $it) $totalQty += (float)($it['qty'] ?? 1);
    $grand = ($inv['declared_total'] !== null) ? (float)$inv['declared_total'] : (float)$inv['calc_total'];

    $ins = $this->db->prepare("
      INSERT INTO purchases (reference_no, user_id, warehouse_id, supplier_id, item, total_qty, grand_total, payment_status, status, document, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $referenceNo,
      $this->cfg['defaults']['user_id'],
      $this->cfg['defaults']['warehouse_id'],
      $supplierId,
      $items,
      $totalQty,
      $grand,
      $this->cfg['defaults']['payment_status'],
      $this->cfg['defaults']['status'],
      $document,
      $now,
      $now
    ]);
    return (int)$this->db->lastInsertId();
  }

  private function insertProductPurchase(int $purchaseId, int $productId, float $qty, float $unitCost, float $total): void {
    $now = Util::nowSql();
    $ins = $this->db->prepare("
      INSERT INTO product_purchases (purchase_id, product_id, qty, recieved, purchase_unit_id, net_unit_cost, total, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $purchaseId,
      $productId,
      $qty,
      $qty,
      $this->cfg['defaults']['purchase_unit_id'],
      $unitCost,
      $total,
      $now,
      $now
    ]);
  }

  /******** CSV Row Builders ********/

  private function buildSupplierCsvRow(string $name): array {
    $now = Util::nowSql();
    return [
      'name'=>$name,
      'company_name'=>$name,
      'email'=>'unknown+'.Util::slug($name).'@example.com',
      'phone_number'=>'0000000000',
      'address'=>'-',
      'city'=>'-',
      'state'=>null,
      'postal_code'=>null,
      'country'=>null,
      'is_active'=>1,
      'created_at'=>$now,
      'updated_at'=>$now,
    ];
  }

  private function buildProductCsvRow(string $code, string $name, float $cost): array {
    $now = Util::nowSql();
    return [
      'type'=>'standard',
      'name'=>$name,
      'code'=>$code,
      'unit'=>'unit',
      'cost'=>$cost,
      'price'=>$cost,
      'is_active'=>1,
      'created_at'=>$now,
      'updated_at'=>$now,
    ];
  }

  private function buildPurchaseCsvRow(string $ref, int $supplierId, int $itemCount, float $qty, float $grand, string $doc): array {
    $now = Util::nowSql();
    return [
      'reference_no'=>$ref,
      'user_id'=>$this->cfg['defaults']['user_id'],
      'warehouse_id'=>$this->cfg['defaults']['warehouse_id'],
      'supplier_id'=>$supplierId,
      'item'=>$itemCount,
      'total_qty'=>$qty,
      'grand_total'=>$grand,
      'payment_status'=>$this->cfg['defaults']['payment_status'],
      'status'=>$this->cfg['defaults']['status'],
      'document'=>$doc,
      'created_at'=>$now,
      'updated_at'=>$now,
    ];
  }

  private function buildProductPurchaseSqlRow(string $purchaseRef, string $productCode, float $qty, float $unit, float $total): string {
    // 如果你要导出的 SQL 也能靠 reference_no + code join，这里就输出 join 版
    $qty = round($qty, 4);
    $unit= round($unit, 4);
    $total=round($total, 2);
    $now = Util::nowSql();
    $purchaseRef = addslashes($purchaseRef);
    $productCode = addslashes($productCode);

    return "INSERT INTO product_purchases (purchase_id, product_id, qty, recieved, purchase_unit_id, net_unit_cost, total, created_at, updated_at)
SELECT p.id, pr.id, {$qty}, {$qty}, {$this->cfg['defaults']['purchase_unit_id']}, {$unit}, {$total}, '{$now}', '{$now}'
FROM purchases p JOIN products pr ON pr.code = '{$productCode}'
WHERE p.reference_no = '{$purchaseRef}' LIMIT 1;";
  }

  private function genCode(string $name): string {
    $base = strtoupper(preg_replace('/[^A-Z0-9\x{4e00}-\x{9fff}]+/u', '_', $name));
    $base = substr($base, 0, 24) ?: 'ITEM';
    return $base . '_' . substr(md5($name), 0, 6);
  }
}
