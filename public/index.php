<?php
// public/index.php
// 修正版 - 與 Invoice Parser v2 兼容
declare(strict_types=1);

// ============================================
// 核心工具類
// ============================================
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/FileScanner.php';
require_once __DIR__ . '/../src/RunStore.php';
require_once __DIR__ . '/../src/PurchaseImporter.php';
require_once __DIR__ . '/../src/SaleImporter.php';

// ============================================
// 解析器系統 - 按正確順序加載
// ============================================

// 1. 先加載 Traits（必須在 AbstractParser 之前）
require_once __DIR__ . '/../src/Parsers/Traits/SmartFieldMapping.php';
require_once __DIR__ . '/../src/Parsers/Traits/TableExtraction.php';
require_once __DIR__ . '/../src/Parsers/Traits/TextBlockParsing.php';

// 2. 加載接口和基類
require_once __DIR__ . '/../src/Parsers/ParserInterface.php';
require_once __DIR__ . '/../src/Parsers/AbstractParser.php';

// 3. 加載所有解析器
require_once __DIR__ . '/../src/Parsers/DocParserJsonParser.php';
require_once __DIR__ . '/../src/Parsers/GenericMarkdownParser.php';
require_once __DIR__ . '/../src/Parsers/TextBlockParser.php';
require_once __DIR__ . '/../src/Parsers/LlmAssistedParser.php';

// 4. 加載驗證器
require_once __DIR__ . '/../src/Validators/InvoiceDataValidator.php';

// 5. 最後加載註冊表
require_once __DIR__ . '/../src/ParserRegistry.php';

// ============================================
// 以下是原始 index.php 的其餘代碼
// ============================================

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

/** ROUTE: API - Get available parsers **/
if ($action === 'parsers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $registry = new ParserRegistry();
  $parsers = [];
  foreach ($registry->getAllParsers() as $id => $parser) {
    $parsers[] = [
      'id' => $id,
      'name' => $parser->getName(),
      'extensions' => $parser->getSupportedExtensions(),
    ];
  }
  echo json_encode(['parsers' => $parsers]);
  exit;
}

/** ROUTE: upload files -> build draft -> redirect preview **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
  try {
    requireCsrf();

    $type = $_POST['type'] ?? '';
    if (!in_array($type, ['purchase', 'sale'], true)) {
      throw new RuntimeException('Invalid type. Must be purchase or sale.');
    }

    $forceParserId = $_POST['parser'] ?? null;
    if ($forceParserId === 'auto') {
      $forceParserId = null;
    }

    // Generate run ID
    $runId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $targetDir = $config['paths']['uploads'] . "/{$runId}_files";

    // Process uploaded files
    $scanner = new FileScanner(['json', 'md']);
    
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
      throw new RuntimeException('No files uploaded');
    }

    $scanResult = $scanner->processUploadedFiles($_FILES['files'], $targetDir);
    
    if ($scanResult['count'] === 0) {
      throw new RuntimeException('No valid JSON or MD files found in upload');
    }

    // Load file contents
    $files = $scanner->loadFileContents($scanResult['files']);
    $groups = $scanner->groupByBaseName($scanResult['files']);
    
    // Detect and parse using registry
    $registry = new ParserRegistry();
    $parseResult = $registry->parse($files, $forceParserId);

    if (empty($parseResult['invoices'])) {
      throw new RuntimeException('No invoices could be extracted from the uploaded files');
    }

    // Build draft
    $draft = [
      'run_id' => $runId,
      'type' => $type,
      'created_at' => Util::nowSql(),
      'source' => [
        'upload_type' => 'folder',
        'file_count' => $scanResult['count'],
        'file_summary' => $scanner->summarize($scanResult['files']),
      ],
      'parser' => [
        'id' => $parseResult['parser_used'],
        'name' => $parseResult['parser_name'],
        'confidence' => $parseResult['confidence'],
      ],
      'invoices' => $parseResult['invoices'],
    ];

    $store->saveDraft($runId, $draft);
    $store->saveRawUpload($runId, $targetDir, $targetDir);

    header('Location: preview.php?run=' . urlencode($runId));
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Error</title>";
    echo "<style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto;}";
    echo ".error{background:#fef2f2;border:1px solid #fecaca;padding:20px;border-radius:8px;color:#991b1b;}";
    echo "a{color:#2563eb;}</style></head><body>";
    echo "<h1>❌ Upload Error</h1>";
    echo "<div class='error'><pre>" . h($e->getMessage()) . "</pre></div>";
    echo "<p style='margin-top:20px;'><a href='index.php'>← Back to upload</a></p>";
    echo "</body></html>";
    exit;
  }
}

/** ROUTE: upload ZIP (legacy support) **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_zip') {
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

    // Scan and parse using new system
    $scanner = new FileScanner(['json', 'md']);
    $scanResult = ['files' => $scanner->scanDirectory($extractDir)];
    $files = $scanner->loadFileContents($scanResult['files']);
    
    $registry = new ParserRegistry();
    $parseResult = $registry->parse($files);

    $draft = [
      'run_id' => $runId,
      'type' => $type,
      'created_at' => Util::nowSql(),
      'source' => [
        'upload_type' => 'zip',
        'zip_name' => $orig,
        'file_count' => count($scanResult['files']),
      ],
      'parser' => [
        'id' => $parseResult['parser_used'],
        'name' => $parseResult['parser_name'],
        'confidence' => $parseResult['confidence'],
      ],
      'invoices' => $parseResult['invoices'],
    ];

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
$registry = new ParserRegistry();
$parsers = $registry->getAllParsers();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Invoice Importer - Upload</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
      background: #f5f5f5;
      padding: 40px 20px;
    }
    .container { 
      max-width: 720px; 
      margin: 0 auto;
      background: white;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      padding: 40px;
    }
    h1 { font-size: 28px; color: #1a1a1a; margin-bottom: 8px; }
    .subtitle { color: #666; margin-bottom: 32px; font-size: 15px; }
    
    .form-group { margin-bottom: 24px; }
    label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; font-size: 14px; }
    
    .type-selector { display: flex; gap: 16px; margin-bottom: 24px; }
    .type-option {
      flex: 1;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      padding: 20px;
      cursor: pointer;
      transition: all 0.2s;
      position: relative;
    }
    .type-option:hover { border-color: #2563eb; background: #f8faff; }
    .type-option.selected { border-color: #2563eb; background: #eff6ff; }
    .type-option input[type="radio"] { position: absolute; opacity: 0; }
    .type-content { text-align: center; }
    .type-icon { font-size: 32px; margin-bottom: 12px; }
    .type-title { font-weight: 600; font-size: 16px; color: #1a1a1a; margin-bottom: 4px; }
    .type-desc { font-size: 13px; color: #666; }
    .check-icon {
      display: none;
      position: absolute; top: 12px; right: 12px;
      color: #2563eb; font-size: 18px;
    }
    .type-option.selected .check-icon { display: block; }
    
    .upload-tabs { display: flex; gap: 8px; margin-bottom: 12px; }
    .tab-btn {
      padding: 8px 16px;
      border: 1px solid #d0d0d0;
      background: white;
      border-radius: 6px;
      cursor: pointer;
      font-size: 13px;
      transition: all 0.2s;
    }
    .tab-btn:hover { background: #f5f5f5; }
    .tab-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
    
    .upload-zone {
      border: 2px dashed #d0d0d0;
      border-radius: 8px;
      padding: 40px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
      background: #fafafa;
    }
    .upload-zone:hover { border-color: #2563eb; background: #f8faff; }
    .upload-zone.dragover { border-color: #2563eb; background: #eff6ff; }
    .upload-icon { font-size: 48px; margin-bottom: 16px; }
    .upload-text { font-size: 16px; color: #333; margin-bottom: 8px; }
    .upload-hint { font-size: 13px; color: #888; }
    
    .file-list { margin-top: 16px; max-height: 200px; overflow-y: auto; }
    .file-item { 
      display: flex; align-items: center; gap: 12px; 
      padding: 10px 12px; background: #f5f5f5; border-radius: 6px;
      margin-bottom: 8px;
    }
    .file-item .icon { font-size: 20px; }
    .file-item .name { flex: 1; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .file-item .size { color: #9ca3af; font-size: 12px; }
    .file-item .remove { color: #ef4444; cursor: pointer; padding: 4px; }
    
    .parser-selector { margin-bottom: 24px; }
    .parser-selector select {
      width: 100%;
      padding: 12px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      font-size: 14px;
      background: white;
    }
    .parser-selector select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .parser-hint { font-size: 12px; color: #6b7280; margin-top: 6px; }
    
    button[type="submit"] {
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
    button[type="submit"]:hover { background: #1d4ed8; }
    button[type="submit"]:disabled { background: #d0d0d0; cursor: not-allowed; }
    
    .info-box {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 8px;
      padding: 16px;
      margin-top: 24px;
      font-size: 14px;
      color: #0369a1;
    }
    .info-box strong { display: block; margin-bottom: 8px; }
    .info-box ul { margin-left: 20px; margin-top: 8px; }
    
    .hidden { display: none; }
  </style>
</head>
<body>
  <div class="container">
    <!-- <h1>📦 Invoice Importer</h1> -->
    <h1>Invoice Importer</h1>
    <p class="subtitle">Upload invoice files for parsing and import</p>

    <form method="post" action="?action=upload" enctype="multipart/form-data" id="uploadForm">
      <?=csrfField()?>

      <div class="form-group">
        <label>1. Select Invoice Type</label>
        <div class="type-selector">
          <label class="type-option selected" id="opt-purchase">
            <input type="radio" name="type" value="purchase" required checked>
            <div class="type-content">
              <!-- <div class="type-icon">🛒</div> -->
              <div class="type-title">Purchase Invoice</div>
              <div class="type-desc">From suppliers</div>
            </div>
            <div class="check-icon">✓</div>
          </label>

          <label class="type-option" id="opt-sale">
            <input type="radio" name="type" value="sale" required>
            <div class="type-content">
              <!-- <div class="type-icon">💰</div> -->
              <div class="type-title">Sale Invoice</div>
              <div class="type-desc">To customers</div>
            </div>
            <div class="check-icon">✓</div>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label>2. Upload Files</label>
        <div class="upload-tabs">
          <!-- <button type="button" class="tab-btn active" data-tab="folder">📁 Folder Upload</button>
          <button type="button" class="tab-btn" data-tab="files">📄 Select Files</button> -->
                    <button type="button" class="tab-btn active" data-tab="folder">Folder Upload</button>
          <button type="button" class="tab-btn" data-tab="files">Select Files</button>
        </div>
        
        <div class="upload-zone" id="uploadZone">
          <!-- <div class="upload-icon">📂</div> -->
          <div class="upload-text">
            <span id="uploadPrompt">Click or drag a folder here</span>
          </div>
          <div class="upload-hint">Accepts JSON and MD files • Other files will be ignored</div>
          
          <!-- Folder upload (webkitdirectory) -->
          <input type="file" name="files[]" id="folderInput" webkitdirectory multiple class="hidden">
          <!-- Files upload -->
          <input type="file" name="files[]" id="filesInput" accept=".json,.md" multiple class="hidden">
        </div>
        
        <div class="file-list hidden" id="fileList"></div>
      </div>

      <div class="form-group parser-selector" style="display: none!important;">
        <label>3. Parser Selection (Optional)</label>
        <select name="parser">
          <option value="auto">🔍 Auto-detect format</option>
          <?php foreach ($parsers as $id => $parser): ?>
          <option value="<?=h($id)?>"><?=h($parser->getName())?></option>
          <?php endforeach; ?>
        </select>
        <div class="parser-hint">Leave as "Auto-detect" unless you know the specific format</div>
      </div>

      <button type="submit" id="submitBtn" disabled>Continue to Preview →</button>

      <div class="info-box"  style="display: none!important;">
        <strong>📋 Supported File Types</strong>
        The system accepts JSON and MD files from various OCR/parsing tools:
        <ul>
          <li><strong>PaddleOCR doc_parser</strong> - JSON with parsing_res_list</li>
          <li><strong>Markdown invoices</strong> - MD files with tables</li>
          <li><strong>Text-based invoices</strong> - Files without table structure</li>
          <li><strong>LLM-assisted</strong> - Complex formats (requires API)</li>
        </ul>
      </div>
    </form>
  </div>

  <script>
    const uploadZone = document.getElementById('uploadZone');
    const folderInput = document.getElementById('folderInput');
    const filesInput = document.getElementById('filesInput');
    const fileList = document.getElementById('fileList');
    const submitBtn = document.getElementById('submitBtn');
    const uploadPrompt = document.getElementById('uploadPrompt');
    const tabBtns = document.querySelectorAll('.tab-btn');
    
    let currentTab = 'folder';
    let selectedFiles = [];
    
    // Tab switching
    tabBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        tabBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentTab = btn.dataset.tab;
        updatePrompt();
        clearFiles();
      });
    });
    
    function updatePrompt() {
      uploadPrompt.textContent = currentTab === 'folder' 
        ? 'Click or drag a folder here'
        : 'Click or drag files here';
    }
    
    function clearFiles() {
      selectedFiles = [];
      fileList.innerHTML = '';
      fileList.classList.add('hidden');
      submitBtn.disabled = true;
      folderInput.value = '';
      filesInput.value = '';
    }
    
    // Type selection
    document.querySelectorAll('.type-option').forEach(opt => {
      opt.addEventListener('click', () => {
        document.querySelectorAll('.type-option').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
      });
    });
    
    // Upload zone click
    uploadZone.addEventListener('click', () => {
      if (currentTab === 'folder') {
        folderInput.click();
      } else {
        filesInput.click();
      }
    });
    
    // Drag and drop
    uploadZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      uploadZone.classList.add('dragover');
    });
    
    uploadZone.addEventListener('dragleave', () => {
      uploadZone.classList.remove('dragover');
    });
    
    uploadZone.addEventListener('drop', (e) => {
      e.preventDefault();
      uploadZone.classList.remove('dragover');
      
      const items = e.dataTransfer.items;
      if (items) {
        handleDataTransferItems(items);
      }
    });
    
    async function handleDataTransferItems(items) {
      const files = [];
      
      for (const item of items) {
        if (item.kind === 'file') {
          const entry = item.webkitGetAsEntry();
          if (entry) {
            if (entry.isDirectory) {
              await traverseDirectory(entry, files);
            } else {
              const file = item.getAsFile();
              if (isValidFile(file.name)) {
                files.push(file);
              }
            }
          }
        }
      }
      
      if (files.length > 0) {
        handleFiles(files);
      }
    }
    
    async function traverseDirectory(entry, files) {
      const reader = entry.createReader();
      return new Promise((resolve) => {
        reader.readEntries(async (entries) => {
          for (const e of entries) {
            if (e.isFile) {
              const file = await getFileFromEntry(e);
              if (isValidFile(file.name)) {
                files.push(file);
              }
            } else if (e.isDirectory) {
              await traverseDirectory(e, files);
            }
          }
          resolve();
        });
      });
    }
    
    function getFileFromEntry(entry) {
      return new Promise((resolve) => {
        entry.file(resolve);
      });
    }
    
    function isValidFile(name) {
      return /\.(json|md)$/i.test(name);
    }
    
    // File input change
    folderInput.addEventListener('change', () => {
      const files = Array.from(folderInput.files).filter(f => isValidFile(f.name));
      handleFiles(files);
    });
    
    filesInput.addEventListener('change', () => {
      const files = Array.from(filesInput.files);
      handleFiles(files);
    });
    
    function handleFiles(files) {
      selectedFiles = files;
      renderFileList();
      submitBtn.disabled = files.length === 0;
    }
    
    function renderFileList() {
      if (selectedFiles.length === 0) {
        fileList.classList.add('hidden');
        return;
      }
      
      fileList.classList.remove('hidden');
      fileList.innerHTML = selectedFiles.map((file, idx) => `
        <div class="file-item">
          <span class="icon">${file.name.endsWith('.json') ? '📄' : '📝'}</span>
          <span class="name" title="${file.name}">${file.name}</span>
          <span class="size">${formatSize(file.size)}</span>
          <span class="remove" onclick="removeFile(${idx})">✕</span>
        </div>
      `).join('');
    }
    
    function removeFile(idx) {
      selectedFiles.splice(idx, 1);
      renderFileList();
      submitBtn.disabled = selectedFiles.length === 0;
      
      // Update file input
      const dt = new DataTransfer();
      selectedFiles.forEach(f => dt.items.add(f));
      if (currentTab === 'folder') {
        // Cannot update webkitdirectory input, user must re-select
        if (selectedFiles.length === 0) {
          folderInput.value = '';
        }
      } else {
        filesInput.files = dt.files;
      }
    }
    
    function formatSize(bytes) {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
      return (bytes/1024/1024).toFixed(1) + ' MB';
    }

    document.getElementById('uploadForm').addEventListener('submit', (e) => {
      if (currentTab === 'folder') {
        filesInput.disabled = true;
        filesInput.name = '';
      } else {
        folderInput.disabled = true;
        folderInput.name = '';
      }
    });
  </script>
</body>
</html>
