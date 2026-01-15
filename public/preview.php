<?php
// public/preview.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/RunStore.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PurchaseImporter.php';
require_once __DIR__ . '/../src/SaleImporter.php';

session_start();

$config = require __DIR__ . '/../config.php';
$store = new RunStore($config['paths']['uploads']);

function csrfField(): string {
  return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'] ?? '').'">';
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** ROUTE: confirm import **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'confirm') {
  try {
    $sent = $_POST['csrf'] ?? '';
    if (!$sent || !hash_equals($_SESSION['csrf'], $sent)) {
      throw new RuntimeException('CSRF validation failed');
    }

    $runId = (string)($_GET['run'] ?? '');
    if (!$runId) throw new RuntimeException('Missing run id');

    $draft = $store->loadDraft($runId);
    $type = $draft['type'] ?? 'purchase';

    // Import
    $db = Db::pdo($config['db']);
    
    if ($type === 'purchase') {
      $importer = new PurchaseImporter($db, $config);
      $result = $importer->importDraft($draft, $runId, true);
    } else {
      $importer = new SaleImporter($db, $config);
      $result = $importer->importDraft($draft, $runId, true);
    }

    // Success page
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8" />
      <title>Import Success</title>
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
          background: #f5f5f5;
          padding: 40px 20px;
        }
        .container { 
          max-width: 680px; 
          margin: 0 auto;
          background: white;
          border-radius: 12px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
          padding: 40px;
          text-align: center;
        }
        .success-icon {
          font-size: 64px;
          margin-bottom: 20px;
        }
        h1 {
          color: #059669;
          margin-bottom: 12px;
          font-size: 28px;
        }
        .stats {
          background: #f0fdf4;
          border: 1px solid #86efac;
          border-radius: 8px;
          padding: 20px;
          margin: 24px 0;
          text-align: left;
        }
        .stat-row {
          display: flex;
          justify-content: space-between;
          padding: 8px 0;
          border-bottom: 1px solid #d1fae5;
        }
        .stat-row:last-child {
          border-bottom: none;
        }
        .stat-label {
          color: #065f46;
          font-weight: 600;
        }
        .stat-value {
          color: #059669;
          font-weight: 700;
        }
        .actions {
          display: flex;
          gap: 12px;
          margin-top: 24px;
        }
        .btn {
          flex: 1;
          padding: 12px 24px;
          border-radius: 8px;
          text-decoration: none;
          font-weight: 600;
          text-align: center;
          transition: all 0.2s;
        }
        .btn-primary {
          background: #2563eb;
          color: white;
        }
        .btn-primary:hover {
          background: #1d4ed8;
        }
        .btn-secondary {
          background: #f3f4f6;
          color: #374151;
        }
        .btn-secondary:hover {
          background: #e5e7eb;
        }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="success-icon">✅</div>
        <h1>Import Successful!</h1>
        <p style="color: #666; margin-bottom: 24px;">
          Your <?=h($type)?> invoices have been imported to the database.
        </p>

        <div class="stats">
          <div class="stat-row">
            <span class="stat-label">Run ID:</span>
            <span class="stat-value"><?=h($result['run_id'])?></span>
          </div>
          <div class="stat-row">
            <span class="stat-label">Successfully Imported:</span>
            <span class="stat-value"><?=h((string)$result['success'])?> invoices</span>
          </div>
          <div class="stat-row">
            <span class="stat-label">Failed:</span>
            <span class="stat-value"><?=h((string)$result['failed'])?> invoices</span>
          </div>
          <div class="stat-row">
            <span class="stat-label">Exports Directory:</span>
            <span class="stat-value" style="font-size: 12px;"><?=h($result['exports_dir'])?></span>
          </div>
        </div>

        <div class="actions">
          <a href="index.php" class="btn btn-primary">Import Another Batch</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8" />
      <title>Import Failed</title>
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
          background: #f5f5f5;
          padding: 40px 20px;
        }
        .container { 
          max-width: 680px; 
          margin: 0 auto;
          background: white;
          border-radius: 12px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
          padding: 40px;
        }
        .error-icon {
          font-size: 64px;
          text-align: center;
          margin-bottom: 20px;
        }
        h1 {
          color: #dc2626;
          margin-bottom: 12px;
          font-size: 28px;
          text-align: center;
        }
        .error-box {
          background: #fef2f2;
          border: 1px solid #fecaca;
          border-radius: 8px;
          padding: 20px;
          margin: 24px 0;
          font-family: monospace;
          color: #991b1b;
          white-space: pre-wrap;
          word-wrap: break-word;
        }
        .btn {
          display: block;
          width: 100%;
          padding: 12px 24px;
          background: #2563eb;
          color: white;
          border-radius: 8px;
          text-decoration: none;
          font-weight: 600;
          text-align: center;
        }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="error-icon">❌</div>
        <h1>Import Failed</h1>
        <div class="error-box"><?=h($e->getMessage())?></div>
        <a href="index.php" class="btn">Back to Home</a>
      </div>
    </body>
    </html>
    <?php
    exit;
  }
}

/** ROUTE: preview page **/
$runId = (string)($_GET['run'] ?? '');
if (!$runId) { 
  http_response_code(400); 
  echo "Missing run id"; 
  exit; 
}

try {
  $draft = $store->loadDraft($runId);
} catch (Throwable $e) {
  http_response_code(404); 
  echo "<pre>".h($e->getMessage())."</pre>"; 
  exit;
}

$type = $draft['type'] ?? 'purchase';
$isPurchase = ($type === 'purchase');

// 準備預覽數據
$db = Db::pdo($config['db']);
$previewData = [];

foreach (($draft['invoices'] ?? []) as $idx => $inv) {
  $entityName = $isPurchase 
    ? ($inv['supplier_name'] ?? 'UNKNOWN') 
    : ($inv['customer_name'] ?? 'UNKNOWN');
  
  $sourceFile = (string)($inv['source_file'] ?? 'unknown.json');
  $items = $inv['items'] ?? [];
  
  // 從 JSON 中提取日期
  $invoiceDate = null;
  if (preg_match('/æ—¥æœŸï¼š(\d{4}-\d{2}-\d{2})/', file_get_contents($store->runDir($runId).'_unzipped/'.$sourceFile) ?? '', $m)) {
    $invoiceDate = $m[1];
  }
  if (!$invoiceDate) $invoiceDate = date('Y-m-d');
  
  // 生成工作時間 (09:00 - 18:00)
  $workTime = date('His', strtotime($invoiceDate) + rand(9*3600, 18*3600));
  $referenceNo = ($isPurchase ? 'pr-' : 'sr-') . date('Ymd', strtotime($invoiceDate)) . '-' . $workTime;
  
  // 檢查 supplier/customer 是否存在
  if ($isPurchase) {
    $stmt = $db->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
  } else {
    $stmt = $db->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
  }
  $stmt->execute([$entityName]);
  $entityExists = $stmt->fetch();
  $entityId = $entityExists ? (int)$entityExists['id'] : null;
  
  // 計算總計
  $totalQty = 0;
  $grandTotal = 0;
  $productRows = [];
  $productPurchaseRows = [];
  
  foreach ($items as $it) {
    $code = trim((string)($it['code'] ?? ''));
    $name = trim((string)($it['name'] ?? ''));
    if ($code === '') $code = strtoupper(substr(preg_replace('/[^A-Z0-9]+/i', '_', $name), 0, 20)) . '_' . substr(md5($name), 0, 6);
    if ($name === '') $name = $code;
    
    $qty = (float)($it['qty'] ?? 1);
    $unitPrice = (float)($it['unit_price'] ?? 0);
    $total = (float)($it['total'] ?? ($qty * $unitPrice));
    
    $totalQty += $qty;
    $grandTotal += $total;
    
    // 檢查 product 是否存在
    $stmt = $db->prepare("SELECT id FROM products WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $productExists = $stmt->fetch();
    $productId = $productExists ? (int)$productExists['id'] : null;
    
    $productRows[] = [
      'code' => $code,
      'name' => $name,
      'cost' => $isPurchase ? $unitPrice : round($unitPrice * 0.7, 2),
      'price' => $isPurchase ? $unitPrice : $unitPrice,
      'exists' => $productId !== null,
      'id' => $productId,
    ];
    
    $productPurchaseRows[] = [
      'product_code' => $code,
      'qty' => $qty,
      'unit_price' => $unitPrice,
      'total' => $total,
    ];
  }
  
  $previewData[] = [
    'source_file' => $sourceFile,
    'reference_no' => $referenceNo,
    'entity_name' => $entityName,
    'entity_exists' => $entityId !== null,
    'entity_id' => $entityId,
    'invoice_date' => $invoiceDate,
    'total_qty' => $totalQty,
    'grand_total' => $grandTotal,
    'products' => $productRows,
    'product_purchases' => $productPurchaseRows,
  ];
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Preview Tables - <?=h(ucfirst($type))?> Import</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
      background: #f5f5f5;
      padding: 20px;
    }
    .header {
      max-width: 1600px;
      margin: 0 auto 20px;
      background: white;
      padding: 24px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h1 {
      font-size: 24px;
      color: #1a1a1a;
      margin-bottom: 8px;
    }
    .type-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 600;
      margin-left: 12px;
    }
    .type-purchase { background: #dbeafe; color: #1e40af; }
    .type-sale { background: #dcfce7; color: #15803d; }
    
    .invoice-section {
      max-width: 1600px;
      margin: 0 auto 30px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      padding: 24px;
    }
    .section-title {
      font-size: 18px;
      font-weight: 700;
      color: #1a1a1a;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 2px solid #e5e7eb;
    }
    .table-wrapper {
      margin-bottom: 24px;
    }
    .table-label {
      font-size: 14px;
      font-weight: 600;
      color: #374151;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .badge {
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 12px;
      font-weight: 600;
    }
    .badge-new { background: #fef3c7; color: #92400e; }
    .badge-exists { background: #d1fae5; color: #065f46; }
    
    .table-container {
      max-height: 300px;
      overflow-y: auto;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    thead {
      position: sticky;
      top: 0;
      background: #f9fafb;
      z-index: 10;
    }
    th {
      padding: 10px 12px;
      text-align: left;
      font-weight: 600;
      color: #374151;
      border-bottom: 2px solid #e5e7eb;
      white-space: nowrap;
    }
    td {
      padding: 8px 12px;
      border-bottom: 1px solid #f3f4f6;
    }
    tbody tr:hover {
      background: #f9fafb;
    }
    .text-right { text-align: right; }
    .text-muted { color: #9ca3af; }
    .text-success { color: #059669; font-weight: 600; }
    .text-warning { color: #d97706; font-weight: 600; }
    
    .submit-container {
      max-width: 1600px;
      margin: 0 auto;
      background: white;
      padding: 24px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .btn-submit {
      width: 100%;
      padding: 16px 24px;
      background: #2563eb;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
    }
    .btn-submit:hover {
      background: #1d4ed8;
    }
  </style>
</head>
<body>

  <div class="header">
    <h1>
      Preview Import Data
      <span class="type-badge type-<?=h($type)?>"><?=h(ucfirst($type))?> Invoices</span>
    </h1>
    <p style="color: #666; font-size: 14px;">
      Run ID: <?=h($runId)?> | 
      Invoices: <?=h((string)count($previewData))?>
    </p>
  </div>

  <?php foreach ($previewData as $idx => $data): ?>
  <div class="invoice-section">
    <div class="section-title">
      📄 Invoice #<?=($idx+1)?> - <?=h($data['source_file'])?>
    </div>

    <!-- Supplier/Customer Table -->
    <div class="table-wrapper">
      <div class="table-label">
        <?=h($isPurchase ? '📦 Supplier' : '👤 Customer')?>
        <?php if ($data['entity_exists']): ?>
          <span class="badge badge-exists">✓ Exists (ID: <?=h((string)$data['entity_id'])?>)</span>
        <?php else: ?>
          <span class="badge badge-new">+ Will Create</span>
        <?php endif; ?>
      </div>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Company Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Address</th>
              <th>City</th>
              <th>Country</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><?=h($data['entity_name'])?></td>
              <td><?=h($data['entity_name'])?></td>
              <td class="text-muted">unknown+<?=h(strtolower(preg_replace('/\s+/', '', $data['entity_name'])))?>@example.com</td>
              <td class="text-muted">0000000000</td>
              <td class="text-muted">-</td>
              <td class="text-muted">-</td>
              <td class="text-muted">Hong Kong</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Purchase/Sale Table -->
    <div class="table-wrapper">
      <div class="table-label">
        <?=h($isPurchase ? '🛒 Purchase' : '💰 Sale')?> Record
      </div>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Reference No</th>
              <th>User ID</th>
              <th>Warehouse ID</th>
              <th><?=h($isPurchase ? 'Supplier' : 'Customer')?> ID</th>
              <th>Items</th>
              <th class="text-right">Total Qty</th>
              <th class="text-right">Grand Total</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong><?=h($data['reference_no'])?></strong></td>
              <td>1</td>
              <td>2</td>
              <td><?=h($data['entity_exists'] ? (string)$data['entity_id'] : 'New')?></td>
              <td><?=h((string)count($data['products']))?></td>
              <td class="text-right"><?=h(number_format($data['total_qty'], 2))?></td>
              <td class="text-right text-success"><?=h(number_format($data['grand_total'], 2))?></td>
              <td><?=h($isPurchase ? 'Received' : 'Completed')?></td>
              <td><?=h($data['invoice_date'])?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Products Table -->
    <div class="table-wrapper">
      <div class="table-label">
        📦 Products
      </div>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Type</th>
              <th class="text-right">Cost</th>
              <th class="text-right">Price</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['products'] as $prod): ?>
            <tr>
              <td><strong><?=h($prod['code'])?></strong></td>
              <td><?=h($prod['name'])?></td>
              <td>standard</td>
              <td class="text-right"><?=h(number_format($prod['cost'], 2))?></td>
              <td class="text-right"><?=h(number_format($prod['price'], 2))?></td>
              <td>
                <?php if ($prod['exists']): ?>
                  <span class="text-success">✓ Exists (ID: <?=h((string)$prod['id'])?>)</span>
                <?php else: ?>
                  <span class="text-warning">+ New</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Product Purchases/Sales Table -->
    <div class="table-wrapper">
      <div class="table-label">
        📋 Product <?=h($isPurchase ? 'Purchases' : 'Sales')?>
      </div>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th><?=h($isPurchase ? 'Purchase' : 'Sale')?> Ref</th>
              <th>Product Code</th>
              <th class="text-right">Quantity</th>
              <th class="text-right">Unit <?=h($isPurchase ? 'Cost' : 'Price')?></th>
              <th class="text-right">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['product_purchases'] as $pp): ?>
            <tr>
              <td><?=h($data['reference_no'])?></td>
              <td><?=h($pp['product_code'])?></td>
              <td class="text-right"><?=h(number_format($pp['qty'], 2))?></td>
              <td class="text-right"><?=h(number_format($pp['unit_price'], 2))?></td>
              <td class="text-right text-success"><?=h(number_format($pp['total'], 2))?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
  <?php endforeach; ?>

  <form method="post" action="?action=confirm&run=<?=h($runId)?>">
    <?=csrfField()?>
    <div class="submit-container">
      <button type="submit" class="btn-submit">
        ✓ Confirm & Import All Data to Database
      </button>
      <p style="margin-top: 12px; font-size: 13px; color: #666; text-align: center;">
        This will create <?=h((string)count($previewData))?> <?=h($type)?> records with all related data.
      </p>
    </div>
  </form>

</body>
</html>