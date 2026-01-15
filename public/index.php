<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/CsvWriter.php';
require_once __DIR__ . '/../src/DocParserInvoiceParser.php';
require_once __DIR__ . '/../src/Importer.php';
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

      $draft['invoices'][] = [
        'source_file' => basename($jf),
        'supplier_name' => (string)($inv['supplier_name'] ?? ''),
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

    header('Location: ?action=preview&run=' . urlencode($runId));
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>".h($e->getMessage())."</pre>";
    exit;
  }
}

/** ROUTE: confirm -> import DB **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm') {
  try {
    requireCsrf();
    $runId = (string)($_GET['run'] ?? '');
    if (!$runId) throw new RuntimeException('Missing run id');

    // read posted data (edited)
    $posted = $_POST['invoices'] ?? null;
    if (!is_array($posted)) throw new RuntimeException('Invalid invoices payload');

    // rebuild normalized draft
    $draft = $store->loadDraft($runId);
    $draft['invoices'] = [];

    foreach ($posted as $idx => $inv) {
      if (!is_array($inv)) continue;

      $supplier = trim((string)($inv['supplier_name'] ?? ''));
      $supplier = $supplier !== '' ? $supplier : 'UNKNOWN_SUPPLIER';

      $decl = $inv['declared_total'] ?? null;
      $decl = ($decl === '' || $decl === null) ? null : (float)$decl;

      $itemsIn = $inv['items'] ?? [];
      if (!is_array($itemsIn)) $itemsIn = [];

      $items = [];
      $calc = 0.0;
      foreach ($itemsIn as $j => $it) {
        if (!is_array($it)) continue;

        $code = trim((string)($it['code'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));
        $qty  = (float)($it['qty'] ?? 0);
        $unit = (float)($it['unit_price'] ?? 0);
        $tot  = (float)($it['total'] ?? 0);

        if ($name === '' && $code === '') continue;
        if ($qty <= 0) $qty = 1;

        // 如果用户没填 total，就用 qty*unit 算
        if ($tot <= 0 && $unit > 0) $tot = $qty * $unit;

        $calc += $tot;

        $items[] = [
          'code' => $code,
          'name' => $name !== '' ? $name : ($code !== '' ? $code : ('ITEM_'.$j)),
          'qty' => $qty,
          'unit_price' => $unit,
          'total' => $tot,
        ];
      }

      $draft['invoices'][] = [
        'source_file' => (string)($inv['source_file'] ?? ('invoice_'.$idx)),
        'supplier_name' => $supplier,
        'declared_total' => $decl,
        'calc_total' => round($calc, 2),
        'items' => $items,
      ];
    }

    // Import to DB
    $db = Db::pdo($config['db']);
    $importer = new Importer($db, $config);

    // true = 写DB
    $result = $importer->importDraft($draft, $runId, true);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>".h($e->getMessage())."</pre>";
    exit;
  }
}

/** ROUTE: preview page **/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'preview') {
  $runId = (string)($_GET['run'] ?? '');
  if (!$runId) { http_response_code(400); echo "Missing run id"; exit; }

  try {
    $draft = $store->loadDraft($runId);
  } catch (Throwable $e) {
    http_response_code(404); echo "<pre>".h($e->getMessage())."</pre>"; exit;
  }

  ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Preview & Edit</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .card { border:1px solid #ddd; border-radius:10px; padding:16px; margin-bottom:14px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f6f6f6; text-align:left; }
    .row-actions button { margin-right: 6px; }
    .warn { color:#b45309; font-weight:600; }
    .ok { color:#166534; font-weight:600; }
    .small { color:#666; font-size:12px; }
    input[type="text"], input[type="number"] { width: 100%; box-sizing: border-box; padding:6px; }
    .topbar { display:flex; gap:12px; align-items:center; margin-bottom:14px; }
    .topbar a { text-decoration:none; }
  </style>
</head>
<body>

  <div class="topbar">
    <h2 style="margin:0;">Preview & Edit</h2>
    <div class="small">Run: <?=h($runId)?> | ZIP: <?=h((string)($draft['source']['zip_name'] ?? ''))?></div>
  </div>

  <form method="post" action="?action=confirm&run=<?=h($runId)?>">
    <?=csrfField()?>

    <?php foreach (($draft['invoices'] ?? []) as $i => $inv): 
      $decl = $inv['declared_total'];
      $calc = (float)($inv['calc_total'] ?? 0);
      $diff = ($decl !== null) ? abs((float)$decl - $calc) : 0.0;
      $status = ($decl === null) ? 'NO DECLARED TOTAL' : (($diff <= 0.05) ? 'OK' : 'MISMATCH');
    ?>
      <div class="card">
        <div style="display:flex; justify-content:space-between; gap:10px;">
          <div>
            <div><b>File:</b> <?=h((string)$inv['source_file'])?></div>
            <div class="small">Declared: <b><?=h($decl === null ? '' : (string)$decl)?></b> | Calc: <b><?=h((string)$calc)?></b>
              <?php if ($status === 'OK'): ?>
                <span class="ok">✓ OK</span>
              <?php elseif ($status === 'MISMATCH'): ?>
                <span class="warn">⚠ Mismatch (diff <?=h((string)$diff)?>)</span>
              <?php else: ?>
                <span class="warn">⚠ No declared total</span>
              <?php endif; ?>
            </div>
          </div>
          <div style="min-width:260px;">
            <label class="small">Supplier name</label>
            <input type="text" name="invoices[<?=$i?>][supplier_name]" value="<?=h((string)$inv['supplier_name'])?>">
            <input type="hidden" name="invoices[<?=$i?>][source_file]" value="<?=h((string)$inv['source_file'])?>">
          </div>
        </div>

        <div style="margin-top:10px;">
          <table class="items-table" data-invoice-index="<?=$i?>">
            <thead>
              <tr>
                <th style="width:120px;">Code</th>
                <th>Name</th>
                <th style="width:90px;">Qty</th>
                <th style="width:120px;">Unit Price</th>
                <th style="width:130px;">Total</th>
                <th style="width:150px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($inv['items'] ?? []) as $j => $it): ?>
                <tr>
                  <td><input type="text" name="invoices[<?=$i?>][items][<?=$j?>][code]" value="<?=h((string)$it['code'])?>"></td>
                  <td><input type="text" name="invoices[<?=$i?>][items][<?=$j?>][name]" value="<?=h((string)$it['name'])?>"></td>
                  <td><input type="number" step="0.0001" class="qty" name="invoices[<?=$i?>][items][<?=$j?>][qty]" value="<?=h((string)$it['qty'])?>"></td>
                  <td><input type="number" step="0.0001" class="unit" name="invoices[<?=$i?>][items][<?=$j?>][unit_price]" value="<?=h((string)$it['unit_price'])?>"></td>
                  <td><input type="number" step="0.01" class="line-total" name="invoices[<?=$i?>][items][<?=$j?>][total]" value="<?=h((string)$it['total'])?>"></td>
                  <td class="row-actions">
                    <button type="button" class="btn-add">+ row</button>
                    <button type="button" class="btn-del">delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="display:flex; gap:12px; margin-top:10px;">
            <div style="min-width:200px;">
              <label class="small">Declared total (optional)</label>
              <input type="number" step="0.01" name="invoices[<?=$i?>][declared_total]" value="<?=h($decl === null ? '' : (string)$decl)?>">
            </div>
            <div style="flex:1;">
              <div class="small">Live calc total</div>
              <div><b class="calc-sum" id="calc-sum-<?=$i?>"><?=h((string)$calc)?></b></div>
            </div>
          </div>

        </div>
      </div>
    <?php endforeach; ?>

    <button type="submit" style="padding:10px 18px; font-size:16px;">Confirm Import → Save to DB</button>
    <p class="small">
      Tip: qty / unit price 修改后会自动重算 total；也可手动改 total（例如含税/折扣行）。
    </p>
  </form>

<script>
(function(){
  function recalcTable(table){
    let sum = 0;
    table.querySelectorAll('tbody tr').forEach(tr=>{
      const qtyEl = tr.querySelector('.qty');
      const unitEl = tr.querySelector('.unit');
      const totEl = tr.querySelector('.line-total');
      const qty = parseFloat(qtyEl?.value || '0') || 0;
      const unit = parseFloat(unitEl?.value || '0') || 0;
      let tot = parseFloat(totEl?.value || '0') || 0;
      // 如果 total 为空/0，则用 qty*unit
      if (!totEl.value || tot <= 0){
        tot = qty * unit;
        if (isFinite(tot)) totEl.value = (Math.round(tot * 100) / 100).toFixed(2);
      }
      sum += (parseFloat(totEl.value || '0') || 0);
    });
    const invoiceIndex = table.getAttribute('data-invoice-index');
    const sumEl = document.getElementById('calc-sum-' + invoiceIndex);
    if (sumEl) sumEl.textContent = (Math.round(sum * 100) / 100).toFixed(2);
  }

  function renumber(table){
    const invoiceIndex = table.getAttribute('data-invoice-index');
    table.querySelectorAll('tbody tr').forEach((tr, idx)=>{
      tr.querySelectorAll('input').forEach(inp=>{
        // rename invoices[i][items][j][field]
        inp.name = inp.name.replace(/invoices\[\d+\]\[items\]\[\d+\]/, `invoices[${invoiceIndex}][items][${idx}]`);
      });
    });
  }

  document.querySelectorAll('.items-table').forEach(table=>{
    table.addEventListener('input', (e)=>{
      if (e.target.classList.contains('qty') || e.target.classList.contains('unit') || e.target.classList.contains('line-total')) {
        // 如果 qty/unit 改了，强制用 qty*unit 更新 total（更符合你们导入）
        if (e.target.classList.contains('qty') || e.target.classList.contains('unit')) {
          const tr = e.target.closest('tr');
          const qty = parseFloat(tr.querySelector('.qty').value || '0') || 0;
          const unit = parseFloat(tr.querySelector('.unit').value || '0') || 0;
          const totEl = tr.querySelector('.line-total');
          const tot = qty * unit;
          if (isFinite(tot)) totEl.value = (Math.round(tot * 100) / 100).toFixed(2);
        }
        recalcTable(table);
      }
    });

    table.addEventListener('click', (e)=>{
      if (e.target.classList.contains('btn-add')) {
        const tr = e.target.closest('tr');
        const clone = tr.cloneNode(true);
        clone.querySelectorAll('input').forEach(inp=> inp.value = '');
        tr.parentNode.insertBefore(clone, tr.nextSibling);
        renumber(table);
        recalcTable(table);
      }
      if (e.target.classList.contains('btn-del')) {
        const tr = e.target.closest('tr');
        const tbody = tr.parentNode;
        if (tbody.querySelectorAll('tr').length <= 1) return;
        tr.remove();
        renumber(table);
        recalcTable(table);
      }
    });

    recalcTable(table);
  });
})();
</script>

</body>
</html>
<?php
  exit;
}

/** DEFAULT: upload page **/
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Doc Parser Importer</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 24px; }
    .box { border:1px solid #ddd; padding:16px; border-radius:10px; max-width:760px; }
  </style>
</head>
<body>
  <h2>Upload doc_parser ZIP</h2>
  <div class="box">
    <form method="post" action="?action=upload" enctype="multipart/form-data">
      <?=csrfField()?>
      <p>Upload a ZIP containing PaddleOCR doc_parser outputs (*.json, optional *.md).</p>
      <input type="file" name="zip" accept=".zip" required />
      <br><br>
      <button type="submit">Upload → Preview</button>
    </form>
  </div>
</body>
</html>
