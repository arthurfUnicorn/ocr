<?php
// public/index.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/CsvWriter.php';
require_once __DIR__ . '/../src/DocParserInvoiceParser.php';
require_once __DIR__ . '/../src/PurchaseImporter.php';
require_once __DIR__ . '/../src/SaleImporter.php';
require_once __DIR__ . '/../src/RunStore.php';

session_start();

$config = require __DIR__ . '/../config.php';

Util::ensureDir($config['paths']['uploads']);
Util::ensureDir($config['paths']['exports']);
Util::ensureDir($config['paths']['logs']);

$store = new RunStore($config['paths']['uploads']);
$action = $_GET['action'] ?? '';

/** CSRF **/
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function csrfField(): string {
  return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf']).'">';
}
function requireCsrf(): void {
  $sent = $_POST['csrf'] ?? '';
  if (!$sent || !hash_equals($_SESSION['csrf'], $sent)) {
    throw new RuntimeException('CSRF validation failed');
  }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** ROUTE: upload ZIP -> build draft -> redirect preview **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
  try {
    requireCsrf();

    $type = $_POST['type'] ?? '';
    if (!in_array($type, ['purchase', 'sale'], true)) {
      throw new RuntimeException('Invalid type. Must be purchase or sale.');
    }

    if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Upload failed');
    }
    $orig = $_FILES['zip']['name'];
    if (!preg_match('/\.zip$/i', $orig)) {
      throw new RuntimeException('Please upload a .zip file');
    }

    $runId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    $zipPath = $config['paths']['uploads'] . "/{$runId}.zip";
    if (!move_uploaded_file($_FILES['zip']['tmp_name'], $zipPath)) {
      throw new RuntimeException('Failed to move uploaded file');
    }

    $extractDir = $config['paths']['uploads'] . "/{$runId}_unzipped";
    Util::ensureDir($extractDir);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Cannot open zip');
    $zip->extractTo($extractDir);
    $zip->close();

    // Build draft from JSON outputs
    $parser = new DocParserInvoiceParser();
    $jsonFiles = Util::listFiles($extractDir, ['json']);

    $draft = [
      'run_id' => $runId,
      'type' => $type,
      'created_at' => Util::nowSql(),
      'source' => [
        'zip_name' => $orig,
        'json_count' => count($jsonFiles),
      ],
      'invoices' => [],
    ];

    foreach ($jsonFiles as $jf) {
      $doc = Util::readJson($jf);
      $inv = $parser->parse($doc);

      // Use date from parser or fallback to today
      $invoiceDate = $inv['invoice_date'] ?? date('Y-m-d');

      $draft['invoices'][] = [
        'source_file' => basename($jf),
        'supplier_name' => (string)($inv['supplier_name'] ?? ''),
        'customer_name' => (string)($inv['supplier_name'] ?? ''), // Use same parser for now
        'invoice_date' => $invoiceDate,
        'declared_total' => $inv['declared_total'],
        'calc_total' => (float)($inv['calc_total'] ?? 0),
        'items' => array_values(array_map(function($it){
          return [
            'code' => (string)($it['code'] ?? ''),
            'name' => (string)($it['name'] ?? ''),
            'qty' => (float)($it['qty'] ?? 1),
            'unit_price' => (float)($it['unit_price'] ?? 0),
            'total' => (float)($it['total'] ?? 0),
          ];
        }, $inv['items'] ?? [])),
      ];
    }

    $store->saveDraft($runId, $draft);
    $store->saveRawUpload($runId, $zipPath, $extractDir);

    header('Location: preview.php?run=' . urlencode($runId));
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>".h($e->getMessage())."</pre>";
    exit;
  }
}

/** DEFAULT: upload page **/
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Invoice Importer - Choose Type</title>
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
    h1 { 
      font-size: 28px;
      color: #1a1a1a;
      margin-bottom: 12px;
    }
    .subtitle {
      color: #666;
      margin-bottom: 32px;
      font-size: 15px;
    }
    .form-group {
      margin-bottom: 24px;
    }
    label {
      display: block;
      font-weight: 600;
      margin-bottom: 8px;
      color: #333;
      font-size: 14px;
    }
    .type-selector {
      display: flex;
      gap: 16px;
      margin-bottom: 24px;
    }
    .type-option {
      flex: 1;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      padding: 20px;
      cursor: pointer;
      transition: all 0.2s;
      position: relative;
    }
    .type-option:hover {
      border-color: #2563eb;
      background: #f8faff;
    }
    .type-option.selected {
      border-color: #2563eb;
      background: #eff6ff;
    }
    .type-option input[type="radio"] {
      position: absolute;
      opacity: 0;
    }
    .type-content {
      text-align: center;
    }
    .type-icon {
      font-size: 32px;
      margin-bottom: 12px;
    }
    .type-title {
      font-weight: 600;
      font-size: 16px;
      color: #1a1a1a;
      margin-bottom: 4px;
    }
    .type-desc {
      font-size: 13px;
      color: #666;
    }
    .check-icon {
      display: none;
      position: absolute;
      top: 12px;
      right: 12px;
      width: 24px;
      height: 24px;
      background: #2563eb;
      border-radius: 50%;
      color: white;
      font-size: 14px;
      line-height: 24px;
      text-align: center;
    }
    .type-option.selected .check-icon {
      display: block;
    }
    input[type="file"] {
      width: 100%;
      padding: 12px;
      border: 2px dashed #d0d0d0;
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
    }
    input[type="file"]:hover {
      border-color: #2563eb;
      background: #f8faff;
    }
    button {
      width: 100%;
      padding: 14px 24px;
      background: #2563eb;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
    }
    button:hover {
      background: #1d4ed8;
    }
    button:disabled {
      background: #d0d0d0;
      cursor: not-allowed;
    }
    .info-box {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 8px;
      padding: 16px;
      margin-top: 24px;
      font-size: 14px;
      color: #0369a1;
    }
    .info-box strong {
      display: block;
      margin-bottom: 8px;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>📦 Invoice Importer</h1>
    <p class="subtitle">Upload your doc_parser ZIP file and select invoice type</p>

    <form method="post" action="?action=upload" enctype="multipart/form-data" id="uploadForm">
      <?=csrfField()?>

      <div class="form-group">
        <label>Select Invoice Type</label>
        <div class="type-selector">
          <label class="type-option" id="opt-purchase">
            <input type="radio" name="type" value="purchase" required>
            <div class="type-content">
              <div class="type-icon">🛒</div>
              <div class="type-title">Purchase Invoice</div>
              <div class="type-desc">From suppliers</div>
            </div>
            <div class="check-icon">✓</div>
          </label>

          <label class="type-option" id="opt-sale">
            <input type="radio" name="type" value="sale" required>
            <div class="type-content">
              <div class="type-icon">💰</div>
              <div class="type-title">Sale Invoice</div>
              <div class="type-desc">To customers</div>
            </div>
            <div class="check-icon">✓</div>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label>Upload ZIP File</label>
        <input type="file" name="zip" accept=".zip" required />
      </div>

      <button type="submit">Continue to Preview →</button>

      <div class="info-box">
        <strong>📋 What's inside the ZIP?</strong>
        Your ZIP should contain JSON files from PaddleOCR's doc_parser output. Each JSON file represents one invoice document.
      </div>
    </form>
  </div>

  <script>
    document.querySelectorAll('.type-option').forEach(opt => {
      opt.addEventListener('click', function() {
        document.querySelectorAll('.type-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
        this.querySelector('input[type="radio"]').checked = true;
      });
    });
  </script>
</body>
</html>
