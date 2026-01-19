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

/** ROUTE: Save edited draft (AJAX) **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save_draft') {
  header('Content-Type: application/json');
  try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new RuntimeException('Invalid JSON input');
    
    $runId = (string)($input['run_id'] ?? '');
    if (!$runId) throw new RuntimeException('Missing run_id');
    
    $draft = $store->loadDraft($runId);
    $draft['invoices'] = $input['invoices'] ?? [];
    $store->saveDraft($runId, $draft);
    
    echo json_encode(['ok' => true, 'message' => 'Draft saved']);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/** ROUTE: Check entity exists (AJAX) **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'check_entity') {
  header('Content-Type: application/json');
  try {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'supplier';
    $name = trim($input['name'] ?? '');
    
    $db = Db::pdo($config['db']);
    
    if ($type === 'supplier') {
      $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE name = ? LIMIT 1");
    } else {
      $stmt = $db->prepare("SELECT id, name FROM customers WHERE name = ? LIMIT 1");
    }
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    
    echo json_encode([
      'exists' => $row !== false,
      'id' => $row ? (int)$row['id'] : null,
      'name' => $row ? $row['name'] : null
    ]);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/** ROUTE: Check product exists (AJAX) **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'check_product') {
  header('Content-Type: application/json');
  try {
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim($input['code'] ?? '');
    
    $db = Db::pdo($config['db']);
    $stmt = $db->prepare("SELECT id, code, name, cost, price FROM products WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    
    echo json_encode([
      'exists' => $row !== false,
      'id' => $row ? (int)$row['id'] : null,
      'code' => $row ? $row['code'] : null,
      'name' => $row ? $row['name'] : null,
      'cost' => $row ? (float)$row['cost'] : null,
      'price' => $row ? (float)$row['price'] : null
    ]);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

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

    $db = Db::pdo($config['db']);
    
    if ($type === 'purchase') {
      $importer = new PurchaseImporter($db, $config);
      $result = $importer->importDraft($draft, $runId, true);
    } else {
      $importer = new SaleImporter($db, $config);
      $result = $importer->importDraft($draft, $runId, true);
    }

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
        .success-icon { font-size: 64px; margin-bottom: 20px; }
        h1 { color: #059669; margin-bottom: 12px; font-size: 28px; }
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
        .stat-row:last-child { border-bottom: none; }
        .stat-label { color: #065f46; font-weight: 600; }
        .stat-value { color: #059669; font-weight: 700; }
        .btn {
          display: inline-block;
          padding: 12px 24px;
          border-radius: 8px;
          text-decoration: none;
          font-weight: 600;
          text-align: center;
          background: #2563eb;
          color: white;
        }
        .btn:hover { background: #1d4ed8; }
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
        </div>
        <a href="index.php" class="btn">Import Another Batch</a>
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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; background: #f5f5f5; padding: 40px 20px; }
        .container { max-width: 680px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; }
        .error-icon { font-size: 64px; text-align: center; margin-bottom: 20px; }
        h1 { color: #dc2626; margin-bottom: 12px; font-size: 28px; text-align: center; }
        .error-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 20px; margin: 24px 0; font-family: monospace; color: #991b1b; white-space: pre-wrap; }
        .btn { display: block; width: 100%; padding: 12px 24px; background: #2563eb; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; text-align: center; }
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

// Check DB for existing entities
$db = Db::pdo($config['db']);

function checkSupplierExists(PDO $db, string $name): ?int {
  $stmt = $db->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
  $stmt->execute([$name]);
  $row = $stmt->fetch();
  return $row ? (int)$row['id'] : null;
}

function checkCustomerExists(PDO $db, string $name): ?int {
  $stmt = $db->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
  $stmt->execute([$name]);
  $row = $stmt->fetch();
  return $row ? (int)$row['id'] : null;
}

function checkProductExists(PDO $db, string $code): ?array {
  $stmt = $db->prepare("SELECT id, name, cost, price FROM products WHERE code = ? LIMIT 1");
  $stmt->execute([$code]);
  $row = $stmt->fetch();
  return $row ? ['id' => (int)$row['id'], 'name' => $row['name'], 'cost' => (float)$row['cost'], 'price' => (float)$row['price']] : null;
}

// Prepare preview data with DB checks
$invoicesJson = [];
foreach (($draft['invoices'] ?? []) as $idx => $inv) {
  $entityName = $isPurchase 
    ? ($inv['supplier_name'] ?? 'UNKNOWN') 
    : ($inv['customer_name'] ?? 'UNKNOWN');
  
  $entityId = $isPurchase 
    ? checkSupplierExists($db, $entityName)
    : checkCustomerExists($db, $entityName);
  
  $items = [];
  foreach (($inv['items'] ?? []) as $it) {
    $code = trim((string)($it['code'] ?? ''));
    $productInfo = $code !== '' ? checkProductExists($db, $code) : null;
    
    $items[] = [
      'code' => $code,
      'name' => (string)($it['name'] ?? ''),
      'qty' => (float)($it['qty'] ?? 1),
      'unit_price' => (float)($it['unit_price'] ?? 0),
      'total' => (float)($it['total'] ?? 0),
      'product_exists' => $productInfo !== null,
      'product_id' => $productInfo['id'] ?? null,
    ];
  }
  
  $invoicesJson[] = [
    'source_file' => (string)($inv['source_file'] ?? 'unknown.json'),
    'entity_name' => $entityName,
    'entity_exists' => $entityId !== null,
    'entity_id' => $entityId,
    'invoice_date' => (string)($inv['invoice_date'] ?? date('Y-m-d')),
    'invoice_number' => (string)($inv['invoice_number'] ?? ''),
    'declared_total' => $inv['declared_total'],
    'calc_total' => (float)($inv['calc_total'] ?? 0),
    'items' => $items,
  ];
}

$csrf = $_SESSION['csrf'] ?? '';
$parserInfo = $draft['source'] ?? [];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Preview & Edit - <?=h(ucfirst($type))?> Import</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
      background: #f5f5f5;
      padding: 20px;
    }
    .header {
      max-width: 1400px;
      margin: 0 auto 20px;
      background: white;
      padding: 24px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .header-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    h1 { font-size: 24px; color: #1a1a1a; }
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
    .parser-info {
      margin-top: 12px;
      font-size: 13px;
      color: #666;
      padding: 12px;
      background: #f9fafb;
      border-radius: 8px;
    }
    .parser-info span { margin-right: 16px; }
    .confidence-bar {
      display: inline-block;
      width: 60px;
      height: 8px;
      background: #e5e7eb;
      border-radius: 4px;
      overflow: hidden;
      vertical-align: middle;
      margin-left: 4px;
    }
    .confidence-fill {
      height: 100%;
      background: #059669;
      border-radius: 4px;
    }
    
    .invoice-card {
      max-width: 1400px;
      margin: 0 auto 24px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      padding: 24px;
    }
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 2px solid #e5e7eb;
    }
    .card-title { font-size: 18px; font-weight: 700; color: #1a1a1a; }
    
    .form-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 20px;
    }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group label {
      font-size: 13px;
      font-weight: 600;
      color: #374151;
    }
    .form-group input, .form-group select {
      padding: 10px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
    }
    .form-group input:focus, .form-group select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    
    .status-badge {
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 12px;
      font-weight: 600;
    }
    .badge-exists { background: #d1fae5; color: #065f46; }
    .badge-new { background: #fef3c7; color: #92400e; }
    
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
    }
    .items-table th {
      background: #f9fafb;
      padding: 12px;
      text-align: left;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      border-bottom: 2px solid #e5e7eb;
    }
    .items-table td {
      padding: 8px 12px;
      border-bottom: 1px solid #f3f4f6;
    }
    .items-table input {
      width: 100%;
      padding: 8px;
      border: 1px solid #e5e7eb;
      border-radius: 4px;
      font-size: 13px;
    }
    .items-table input:focus {
      outline: none;
      border-color: #2563eb;
    }
    .items-table input.input-sm { width: 80px; text-align: right; }
    .items-table input.input-code { width: 120px; }
    
    .btn-add-row {
      margin-top: 12px;
      padding: 8px 16px;
      background: #f3f4f6;
      border: 1px dashed #9ca3af;
      border-radius: 6px;
      color: #4b5563;
      cursor: pointer;
      font-size: 13px;
    }
    .btn-add-row:hover { background: #e5e7eb; }
    
    .btn-remove {
      background: #fef2f2;
      color: #dc2626;
      border: none;
      padding: 4px 8px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
    }
    .btn-remove:hover { background: #fee2e2; }
    
    .totals-row {
      background: #f9fafb;
      font-weight: 600;
    }
    .totals-row td { padding: 12px; }
    
    .actions-bar {
      max-width: 1400px;
      margin: 0 auto;
      background: white;
      padding: 20px 24px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      display: flex;
      gap: 16px;
      position: sticky;
      bottom: 20px;
    }
    .btn {
      flex: 1;
      padding: 14px 24px;
      border-radius: 8px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      border: none;
      text-align: center;
      text-decoration: none;
    }
    .btn-primary { background: #2563eb; color: white; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-secondary { background: #f3f4f6; color: #374151; }
    .btn-secondary:hover { background: #e5e7eb; }
    .btn-success { background: #059669; color: white; }
    .btn-success:hover { background: #047857; }
    
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 12px 20px;
      border-radius: 8px;
      color: white;
      font-weight: 500;
      z-index: 1000;
      display: none;
    }
    .toast-success { background: #059669; }
    .toast-error { background: #dc2626; }
  </style>
</head>
<body>

<div class="toast toast-success" id="toast"></div>

<div class="header">
  <div class="header-top">
    <div>
      <h1>
        Preview & Edit
        <span class="type-badge type-<?=h($type)?>"><?=h(ucfirst($type))?></span>
      </h1>
      <p style="color: #666; font-size: 14px; margin-top: 8px;">
        Run ID: <?=h($runId)?> | Invoices: <?=h((string)count($invoicesJson))?>
      </p>
    </div>
    <button class="btn btn-secondary" onclick="saveAllDrafts()" style="display: none!important;">💾 Save Changes</button>
  </div>
  <div class="parser-info">
    <span style="display: none!important;">📄 <strong>Format:</strong> <?=h($parserInfo['parser_name'] ?? 'Unknown')?></span>
    <!-- <span>📁 <strong>Files:</strong> <?=h((string)($parserInfo['file_count'] ?? 0))?></span> -->
        <span><strong>Files:</strong> <?=h((string)($parserInfo['file_count'] ?? 0))?></span>
    <span style="display: none!important;">📊 <strong>Confidence:</strong>
      <span class="confidence-bar">
        <span class="confidence-fill" style="width: <?=h((string)(($parserInfo['confidence'] ?? 0) * 100))?>%"></span>
      </span>
      <?=h((string)round(($parserInfo['confidence'] ?? 0) * 100))?>%
    </span>
  </div>
</div>

<div id="invoices-container"></div>

<div class="actions-bar">
  <a href="index.php" class="btn btn-secondary">← Back</a>
  <button class="btn btn-secondary" onclick="saveAllDrafts()" style="display: none!important;">💾 Save Draft</button>
  <form method="post" action="?action=confirm&run=<?=h($runId)?>" style="flex: 2;" id="confirmForm">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <button type="submit" class="btn btn-success" style="width: 100%;">
      ✓ Confirm & Import to Database
    </button>
  </form>
</div>
      <!-- <span class="card-title">📄 Invoice #${invIdx + 1} - ${inv.source_file}</span> -->
<script>
const runId = <?=json_encode($runId)?>;
const type = <?=json_encode($type)?>;
const isPurchase = <?=json_encode($isPurchase)?>;
let invoices = <?=json_encode($invoicesJson, JSON_UNESCAPED_UNICODE)?>;

function showToast(message, isError = false) {
  const toast = document.getElementById('toast');
  toast.textContent = message;
  toast.className = 'toast ' + (isError ? 'toast-error' : 'toast-success');
  toast.style.display = 'block';
  setTimeout(() => toast.style.display = 'none', 3000);
}

function generateReferenceNo(date) {
  const d = new Date(date);
  const h = String(9 + Math.floor(Math.random() * 9)).padStart(2, '0');
  const m = String(Math.floor(Math.random() * 60)).padStart(2, '0');
  const s = String(Math.floor(Math.random() * 60)).padStart(2, '0');
  const prefix = isPurchase ? 'pr' : 'sr';
  const dateStr = d.toISOString().slice(0,10).replace(/-/g, '');
  return `${prefix}-${dateStr}-${h}${m}${s}`;
}

function recalcTotals(invIdx) {
  const inv = invoices[invIdx];
  let totalQty = 0;
  let grandTotal = 0;
  
  inv.items.forEach((item, i) => {
    const qty = parseFloat(item.qty) || 0;
    const unitPrice = parseFloat(item.unit_price) || 0;
    const total = qty * unitPrice;
    
    inv.items[i].total = Math.round(total * 100) / 100;
    totalQty += qty;
    grandTotal += total;
  });
  
  inv.calc_total = Math.round(grandTotal * 100) / 100;
  renderInvoice(invIdx);
}

function updateItem(invIdx, itemIdx, field, value) {
  if (field === 'qty' || field === 'unit_price' || field === 'total') {
    invoices[invIdx].items[itemIdx][field] = parseFloat(value) || 0;
    if (field !== 'total') {
      recalcTotals(invIdx);
    }
  } else {
    invoices[invIdx].items[itemIdx][field] = value;
  }
}

function updateInvoice(invIdx, field, value) {
  if (field === 'entity_name') {
    invoices[invIdx].entity_name = value;
    checkEntityExists(invIdx, value);
  } else if (field === 'invoice_date') {
    invoices[invIdx].invoice_date = value;
  }
}

async function checkEntityExists(invIdx, name) {
  try {
    const response = await fetch('?action=check_entity', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type: isPurchase ? 'supplier' : 'customer', name })
    });
    const data = await response.json();
    invoices[invIdx].entity_exists = data.exists;
    invoices[invIdx].entity_id = data.id;
    renderInvoice(invIdx);
  } catch (e) {
    console.error('Check entity error:', e);
  }
}

async function checkProductExists(invIdx, itemIdx, code) {
  if (!code.trim()) return;
  try {
    const response = await fetch('?action=check_product', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code })
    });
    const data = await response.json();
    invoices[invIdx].items[itemIdx].product_exists = data.exists;
    invoices[invIdx].items[itemIdx].product_id = data.id;
    renderInvoice(invIdx);
  } catch (e) {
    console.error('Check product error:', e);
  }
}

function addItem(invIdx) {
  invoices[invIdx].items.push({
    code: '',
    name: '',
    qty: 1,
    unit_price: 0,
    total: 0,
    product_exists: false,
    product_id: null
  });
  renderInvoice(invIdx);
}

function removeItem(invIdx, itemIdx) {
  if (invoices[invIdx].items.length <= 1) {
    showToast('Cannot remove last item', true);
    return;
  }
  invoices[invIdx].items.splice(itemIdx, 1);
  recalcTotals(invIdx);
}

function renderInvoice(invIdx) {
  const inv = invoices[invIdx];
  const container = document.getElementById(`invoice-${invIdx}`);
  if (!container) return;
  
  const totalQty = inv.items.reduce((sum, it) => sum + (parseFloat(it.qty) || 0), 0);
  const entityLabel = isPurchase ? 'Supplier' : 'Customer';
  const refNo = generateReferenceNo(inv.invoice_date);
  
  container.innerHTML = `
    <div class="card-header">
            <span class="card-title">Invoice #${invIdx + 1} - ${inv.source_file}</span>
      <span class="status-badge ${inv.entity_exists ? 'badge-exists' : 'badge-new'}">
        ${inv.entity_exists ? '✓ ' + entityLabel + ' Exists (ID: ' + inv.entity_id + ')' : '+ New ' + entityLabel}
      </span>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label>${entityLabel} Name</label>
        <input type="text" value="${inv.entity_name}" 
               onchange="updateInvoice(${invIdx}, 'entity_name', this.value)"
               onblur="checkEntityExists(${invIdx}, this.value)">
      </div>
      <div class="form-group">
        <label>Invoice Date</label>
        <input type="date" value="${inv.invoice_date}" 
               onchange="updateInvoice(${invIdx}, 'invoice_date', this.value)">
      </div>
      <div class="form-group">
        <label>Reference No (Auto)</label>
        <input type="text" value="${refNo}" readonly style="background: #f9fafb;">
      </div>
      <div class="form-group">
        <label>Grand Total</label>
        <input type="text" value="${inv.calc_total.toFixed(2)}" readonly style="background: #f9fafb; font-weight: 600;">
      </div>
    </div>
    
    <table class="items-table">
      <thead>
        <tr>
          <th style="width: 40px;">#</th>
          <th style="width: 120px;">Code</th>
          <th>Product Name</th>
          <th style="width: 90px;">Qty</th>
          <th style="width: 100px;">Unit Price</th>
          <th style="width: 100px;">Total</th>
          <th style="width: 100px;">Status</th>
          <th style="width: 60px;"></th>
        </tr>
      </thead>
      <tbody>
        ${inv.items.map((item, i) => `
          <tr>
            <td>${i + 1}</td>
            <td>
              <input type="text" class="input-code" value="${item.code}" 
                     onchange="updateItem(${invIdx}, ${i}, 'code', this.value); checkProductExists(${invIdx}, ${i}, this.value)">
            </td>
            <td>
              <input type="text" value="${item.name}" 
                     onchange="updateItem(${invIdx}, ${i}, 'name', this.value)">
            </td>
            <td>
              <input type="number" class="input-sm" value="${item.qty}" step="0.01"
                     onchange="updateItem(${invIdx}, ${i}, 'qty', this.value)">
            </td>
            <td>
              <input type="number" class="input-sm" value="${item.unit_price}" step="0.01"
                     onchange="updateItem(${invIdx}, ${i}, 'unit_price', this.value)">
            </td>
            <td>
              <input type="number" class="input-sm" value="${item.total.toFixed(2)}" step="0.01" readonly
                     style="background: #f9fafb;">
            </td>
            <td>
              <span class="status-badge ${item.product_exists ? 'badge-exists' : 'badge-new'}">
                ${item.product_exists ? '✓ ID: ' + item.product_id : '+ New'}
              </span>
            </td>
            <td>
              <button class="btn-remove" onclick="removeItem(${invIdx}, ${i})">✕</button>
            </td>
          </tr>
        `).join('')}
        <tr class="totals-row">
          <td colspan="3" style="text-align: right;">Totals:</td>
          <td style="text-align: right;">${totalQty.toFixed(2)}</td>
          <td></td>
          <td style="text-align: right; color: #059669;">${inv.calc_total.toFixed(2)}</td>
          <td colspan="2"></td>
        </tr>
      </tbody>
    </table>
    
    <button class="btn-add-row" onclick="addItem(${invIdx})">+ Add Item</button>
  `;
}

function renderAllInvoices() {
  const container = document.getElementById('invoices-container');
  container.innerHTML = invoices.map((inv, idx) => 
    `<div class="invoice-card" id="invoice-${idx}"></div>`
  ).join('');
  
  invoices.forEach((inv, idx) => renderInvoice(idx));
}

async function saveAllDrafts() {
  const saveData = {
    run_id: runId,
    invoices: invoices.map(inv => ({
      source_file: inv.source_file,
      supplier_name: isPurchase ? inv.entity_name : '',
      customer_name: !isPurchase ? inv.entity_name : '',
      invoice_date: inv.invoice_date,
      invoice_number: inv.invoice_number || '',
      declared_total: inv.declared_total,
      calc_total: inv.calc_total,
      items: inv.items.map(it => ({
        code: it.code,
        name: it.name,
        qty: it.qty,
        unit_price: it.unit_price,
        total: it.total
      }))
    }))
  };
  
  try {
    const response = await fetch('?action=save_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(saveData)
    });
    const result = await response.json();
    
    if (result.ok) {
      showToast('Draft saved successfully!');
    } else {
      showToast('Error: ' + result.error, true);
    }
  } catch (e) {
    showToast('Failed to save: ' + e.message, true);
  }
}

// Initial render
renderAllInvoices();

// Save before form submit
document.getElementById('confirmForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  await saveAllDrafts();
  this.submit();
});
</script>

</body>
</html>
