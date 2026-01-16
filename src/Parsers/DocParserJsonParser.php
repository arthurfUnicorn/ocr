<?php
declare(strict_types=1);

namespace Parsers;

/**
 * Parser for PaddleOCR doc_parser output format
 * 
 * Identifies by:
 * - JSON files with 'parsing_res_list' array
 * - Contains 'block_label', 'block_content', 'block_bbox' fields
 * - Usually paired with .md file of same name
 */
class DocParserJsonParser extends AbstractParser {
  
  public function getId(): string {
    return 'doc_parser_json';
  }

  public function getName(): string {
    return 'PaddleOCR DocParser JSON';
  }

  public function getSupportedExtensions(): array {
    return ['json', 'md'];
  }

  public function canParse(array $files): float {
    $jsonFiles = $this->filterByExtensions($files, ['json']);
    
    if (empty($jsonFiles)) {
      return 0.0;
    }

    $score = 0.0;
    $checked = 0;

    foreach ($jsonFiles as $file) {
      $json = $this->readJsonFile($file);
      if (!$json) continue;
      
      $checked++;
      
      // Check for doc_parser specific structure
      $root = $this->normalizeRoot($json);
      
      if (isset($root['parsing_res_list']) && is_array($root['parsing_res_list'])) {
        $score += 0.5;
        
        // Check for typical block structure
        $blocks = $root['parsing_res_list'];
        if (!empty($blocks)) {
          $firstBlock = $blocks[0];
          if (isset($firstBlock['block_label']) && isset($firstBlock['block_content'])) {
            $score += 0.3;
          }
          if (isset($firstBlock['block_bbox'])) {
            $score += 0.2;
          }
        }
      }
      
      // Check for layout_det_res (another doc_parser indicator)
      if (isset($root['layout_det_res'])) {
        $score += 0.1;
      }
      
      // Check for model_settings (doc_parser specific)
      if (isset($root['model_settings'])) {
        $score += 0.1;
      }
    }

    return $checked > 0 ? min(1.0, $score / $checked) : 0.0;
  }

  public function parse(array $files): array {
    $jsonFiles = $this->filterByExtensions($files, ['json']);
    $mdFiles = $this->filterByExtensions($files, ['md']);
    
    $groups = $this->groupFilesByBaseName($files);
    $invoices = [];

    foreach ($jsonFiles as $file) {
      $json = $this->readJsonFile($file);
      if (!$json) continue;

      $root = $this->normalizeRoot($json);
      if (!isset($root['parsing_res_list'])) continue;

      $blocks = $root['parsing_res_list'];
      
      $invoice = $this->extractInvoiceData($blocks, $file['name']);
      $invoices[] = $this->normalizeInvoice($invoice);
    }

    return $invoices;
  }

  private function normalizeRoot(array $json): array {
    if (isset($json['parsing_res_list'])) return $json;
    if (isset($json['res']['parsing_res_list'])) return $json['res'];
    if (isset($json['result']['parsing_res_list'])) return $json['result'];
    return $json;
  }

  private function extractInvoiceData(array $blocks, string $sourceFile): array {
    $supplier = $this->guessSupplier($blocks);
    $invoiceDate = $this->extractDate($blocks);
    $declaredTotal = $this->guessDeclaredTotal($blocks);
    $invoiceNumber = $this->extractInvoiceNumber($blocks);

    $tables = $this->collectTables($blocks);
    $best = $this->pickBestTable($tables);
    $items = $best ? $this->extractItemsFromTable($best) : [];

    $calc = 0.0;
    foreach ($items as $it) $calc += (float)$it['total'];

    return [
      'source_file' => $sourceFile,
      'supplier_name' => $supplier,
      'customer_name' => $this->extractCustomer($blocks),
      'invoice_date' => $invoiceDate,
      'invoice_number' => $invoiceNumber,
      'declared_total' => $declaredTotal,
      'calc_total' => round($calc, 2),
      'items' => $items,
      'metadata' => [
        'block_count' => count($blocks),
        'table_count' => count($tables),
      ],
    ];
  }

  private function guessSupplier(array $blocks): string {
    $cands = [];
    foreach ($blocks as $b) {
      $text = trim((string)($b['block_content'] ?? ''));
      if ($text === '') continue;
      $line = trim(explode("\n", $text)[0]);

      // Skip common non-supplier patterns
      if (preg_match('/invoice|receipt|tax|date|tel|phone|fax|address|bill to|ship to|發票|收據|税|稅|日期|電話|地址|客戶|客户|批次|营业员|經辦人|经办人/iu', $line)) continue;
      if (mb_strlen($line) < 2) continue;

      $digitCount = preg_match_all('/\d/', $line);
      if ($digitCount >= 6) continue;

      $score = 0;
      if (preg_match('/sdn bhd|ltd|limited|inc|co\.|company|enterprise|trading/i', $line)) $score += 5;
      if (preg_match('/有限公司|國際|国际|網絡|网络|贸易|貿易|商店|商行|皮具|销售/u', $line)) $score += 5;
      $score += min(10, mb_strlen($line) / 3);

      if (isset($b['block_bbox'][1])) $score += max(0, 10 - (float)$b['block_bbox'][1] / 80.0);

      $cands[] = ['line'=>$line,'score'=>$score];
    }

    usort($cands, fn($a,$b)=> $b['score'] <=> $a['score']);
    return $cands[0]['line'] ?? '';
  }

  private function extractCustomer(array $blocks): string {
    foreach ($blocks as $b) {
      $text = trim((string)($b['block_content'] ?? ''));
      if (preg_match('/客[户戶][：:]\s*(.+)/u', $text, $m)) {
        return trim($m[1]);
      }
      if (preg_match('/bill\s*to[:\s]+(.+)/i', $text, $m)) {
        return trim($m[1]);
      }
    }
    return '';
  }

  private function extractDate(array $blocks): ?string {
    foreach ($blocks as $b) {
      $text = trim((string)($b['block_content'] ?? ''));
      // Chinese format: 日期：2025-01-10
      if (preg_match('/日期[：:]\s*(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
        return $m[1];
      }
      // English format: Date: 2025-01-10
      if (preg_match('/date[:\s]+(\d{4}-\d{2}-\d{2})/i', $text, $m)) {
        return $m[1];
      }
      // Other date formats
      if (preg_match('/(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $text, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
      }
    }
    return null;
  }

  private function extractInvoiceNumber(array $blocks): ?string {
    foreach ($blocks as $b) {
      $text = trim((string)($b['block_content'] ?? ''));
      // Chinese: 批次：45009
      if (preg_match('/批次[：:]\s*(\d+)/u', $text, $m)) {
        return $m[1];
      }
      // English: Invoice No: INV-001
      if (preg_match('/invoice\s*(no|number|#)?[:\s]+([A-Z0-9\-]+)/i', $text, $m)) {
        return $m[2];
      }
    }
    return null;
  }

  private function guessDeclaredTotal(array $blocks): ?float {
    $all = [];
    foreach ($blocks as $b) {
      $t = trim((string)($b['block_content'] ?? ''));
      if ($t) $all[] = $t;
    }
    $joined = implode("\n", $all);

    $re = '/(grand\s*total|total\s*due|amount\s*due|total|本单额|合計|合计|總數|总数)\s*[:：]?\s*([A-Z]{0,3}\s*)?([0-9][0-9,]*\.?[0-9]{0,2})/iu';
    if (preg_match_all($re, $joined, $m) && !empty($m[3])) {
      $last = end($m[3]);
      $v = $this->parseMoney($last);
      return $v > 0 ? $v : null;
    }
    return null;
  }

  private function parseMoney(string $s): float {
    $t = preg_replace('/[^\d\.,-]/', '', $s);
    $t = str_replace(',', '', $t);
    return is_numeric($t) ? (float)$t : 0.0;
  }

  private function collectTables(array $blocks): array {
    $out = [];
    foreach ($blocks as $b) {
      $label = strtolower((string)($b['block_label'] ?? ''));
      if (strpos($label, 'table') === false) continue;
      $html = (string)($b['block_content'] ?? '');
      $t = $this->parseHtmlTable($html);
      if ($t) $out[] = $t;
    }
    return $out;
  }

  private function parseHtmlTable(string $html): ?array {
    if (trim($html) === '') return null;
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    if (!$ok) return null;

    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) return null;

    $rows = [];
    $trs = $tables->item(0)->getElementsByTagName('tr');
    foreach ($trs as $tr) {
      $cells = [];
      foreach ($tr->childNodes as $td) {
        if (!($td instanceof \DOMElement)) continue;
        if (!in_array(strtolower($td->tagName), ['td','th'], true)) continue;
        $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent ?? ''));
      }
      if ($cells) $rows[] = $cells;
    }
    return count($rows) ? ['rows'=>$rows] : null;
  }

  private function pickBestTable(array $tables): ?array {
    if (!$tables) return null;
    $best = null; $bestScore = -1;
    
    $keywords = [
      'description','item','product','code','qty','quantity','unit price','amount','total','subtotal',
      '說明','项目','項目','產品','货品','編號','编号','數量','数量','單價','单价','總數','总数','金額','金额','合計','合计',
      '款号','名称','颜色','尺码','备注'
    ];
    
    foreach ($tables as $t) {
      $hdr = strtolower(implode(' ', $t['rows'][0] ?? []));
      $score = 0;
      foreach ($keywords as $k) if (strpos($hdr, strtolower($k)) !== false) $score += 2;
      $score += min(10, count($t['rows']));
      if ($score > $bestScore) { $bestScore = $score; $best = $t; }
    }
    return $best;
  }

  private function extractItemsFromTable(array $table): array {
    $rows = $table['rows'] ?? [];
    if (count($rows) < 2) return [];

    $header = array_map(fn($x)=> strtolower((string)$x), $rows[0]);
    $map = $this->mapColumns($header);

    $items = [];
    for ($i=1; $i<count($rows); $i++) {
      $r = $rows[$i];

      $seqNo = $this->cell($r, $map['seq']);
      $code = $this->cell($r, $map['code']);
      $name = $this->cell($r, $map['name']);
      $color = $this->cell($r, $map['color']);
      $qtyS = $this->cell($r, $map['qty']);
      $unitS= $this->cell($r, $map['unit_price']);
      $totS = $this->cell($r, $map['total']);

      // Skip summary rows
      if (preg_match('/^(合计|total|grand|subtotal)$/iu', trim($seqNo)) || 
          preg_match('/^(合计|total|grand|subtotal)$/iu', trim($code))) {
        continue;
      }

      if ($name === '' && $code === '') continue;

      $unit = $this->parseMoney($unitS);
      $total = $this->parseMoney($totS);

      $qty = (float)preg_replace('/[^\d\.-]/','', $qtyS);
      if ($qty <= 0 && $unit > 0 && $total > 0) {
        $q = $total / $unit;
        $near = round($q);
        $qty = (abs($q - $near) < 0.02) ? $near : $q;
      }
      if ($qty <= 0) $qty = 1;

      $fullName = $name;
      if ($color !== '') {
        $fullName = $name . ' - ' . $color;
      }

      $items[] = [
        'code' => $code,
        'name' => $fullName,
        'qty' => round($qty, 4),
        'unit_price' => round($unit > 0 ? $unit : ($total > 0 ? $total/$qty : 0), 4),
        'total' => round($total > 0 ? $total : $qty * ($unit > 0 ? $unit : 0), 2),
        'metadata' => ['color' => $color],
      ];
    }
    return $items;
  }

  private function mapColumns(array $h): array {
    $find = function(array $cands) use ($h) {
      foreach ($h as $i=>$col) {
        foreach ($cands as $c) if (strpos($col, $c) !== false) return $i;
      }
      return -1;
    };

    $seq  = $find(['序号', '序號', 'no', 'seq', '#']);
    $code = $find(['款号', '款號', 'code', 'item code', 'sku', '編號', '编号']);
    $name = $find(['名称', '名稱', 'description', 'item', 'product', 'name', '說明', '说明', '項目', '项目', '產品', '产品']);
    $color= $find(['颜色', '顏色', 'color', 'colour']);
    $qty  = $find(['数量', '數量', 'qty', 'quantity']);
    $unit = $find(['单价', '單價', 'unit price', 'price', 'unit cost']);
    $tot  = $find(['金额', '金額', 'amount', 'total', '總數', '总数', '小計', '小计', 'subtotal']);

    if ($seq === -1) $seq = 0;
    if ($name === -1) $name = 2;
    if ($tot === -1) $tot = count($h) - 2;

    return [
      'seq' => $seq, 'code' => $code, 'name' => $name,
      'color' => $color, 'qty' => $qty, 'unit_price' => $unit, 'total' => $tot
    ];
  }

  private function cell(array $r, int $idx): string {
    if ($idx < 0 || $idx >= count($r)) return '';
    return trim((string)$r[$idx]);
  }
}
