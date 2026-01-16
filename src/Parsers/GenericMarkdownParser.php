<?php
declare(strict_types=1);

namespace Parsers;

/**
 * Parser for generic markdown invoice files
 * 
 * This is a fallback parser that tries to extract invoice data from markdown files
 * when no structured JSON is available.
 */
class GenericMarkdownParser extends AbstractParser {
  
  public function getId(): string {
    return 'generic_markdown';
  }

  public function getName(): string {
    return 'Generic Markdown Invoice';
  }

  public function getSupportedExtensions(): array {
    return ['md', 'txt'];
  }

  public function canParse(array $files): float {
    $mdFiles = $this->filterByExtensions($files, ['md', 'txt']);
    
    if (empty($mdFiles)) {
      return 0.0;
    }

    $score = 0.0;
    $checked = 0;

    foreach ($mdFiles as $file) {
      $content = $this->readTextFile($file);
      if (!$content) continue;
      
      $checked++;
      
      // Check for invoice-like content
      if (preg_match('/<table/i', $content)) {
        $score += 0.3;
      }
      
      // Check for common invoice keywords
      $keywords = ['total', 'amount', 'qty', 'price', '金额', '數量', '单价', '合计', 'invoice', '發票'];
      foreach ($keywords as $kw) {
        if (stripos($content, $kw) !== false) {
          $score += 0.1;
        }
      }
      
      $score = min(0.7, $score); // Cap at 0.7 - prefer JSON parsers
    }

    return $checked > 0 ? $score / $checked : 0.0;
  }

  public function parse(array $files): array {
    $mdFiles = $this->filterByExtensions($files, ['md', 'txt']);
    $invoices = [];

    foreach ($mdFiles as $file) {
      $content = $this->readTextFile($file);
      if (!$content) continue;

      // Skip if this looks like a merged file (contains multiple invoices)
      if ($this->isMergedFile($file['name'], $content)) {
        $subInvoices = $this->parseMergedFile($content, $file['name']);
        foreach ($subInvoices as $inv) {
          $invoices[] = $this->normalizeInvoice($inv);
        }
        continue;
      }

      $invoice = $this->extractFromMarkdown($content, $file['name']);
      if (!empty($invoice['items'])) {
        $invoices[] = $this->normalizeInvoice($invoice);
      }
    }

    return $invoices;
  }

  private function isMergedFile(string $name, string $content): bool {
    // Check filename
    if (preg_match('/merge|combined|all/i', $name)) {
      return true;
    }
    
    // Check for multiple invoice headers
    $headerCount = preg_match_all('/^#{1,3}\s+.*(invoice|發票|销售单|收據)/imu', $content);
    return $headerCount > 1;
  }

  private function parseMergedFile(string $content, string $sourceFile): array {
    // Split by headers that look like invoice starts
    $parts = preg_split('/(?=^#{1,3}\s+)/m', $content);
    $invoices = [];
    
    $idx = 0;
    foreach ($parts as $part) {
      $part = trim($part);
      if (empty($part)) continue;
      
      $invoice = $this->extractFromMarkdown($part, "{$sourceFile}#part{$idx}");
      if (!empty($invoice['items'])) {
        $invoices[] = $invoice;
        $idx++;
      }
    }
    
    return $invoices;
  }

  private function extractFromMarkdown(string $content, string $sourceFile): array {
    $title = $this->extractTitle($content);
    $date = $this->extractDate($content);
    $total = $this->extractTotal($content);
    $items = $this->extractTableItems($content);
    
    $calc = 0.0;
    foreach ($items as $it) $calc += (float)$it['total'];

    return [
      'source_file' => $sourceFile,
      'supplier_name' => $title,
      'customer_name' => $this->extractCustomer($content),
      'invoice_date' => $date,
      'invoice_number' => $this->extractInvoiceNumber($content),
      'declared_total' => $total,
      'calc_total' => round($calc, 2),
      'items' => $items,
    ];
  }

  private function extractTitle(string $content): string {
    // Look for H1 or H2
    if (preg_match('/^#{1,2}\s+(.+)/m', $content, $m)) {
      return trim($m[1]);
    }
    // Look for first line
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
      $line = trim($line);
      if (!empty($line) && !preg_match('/^[#\-\*]/', $line)) {
        return $line;
      }
    }
    return '';
  }

  private function extractDate(string $content): ?string {
    if (preg_match('/日期[：:]\s*(\d{4}-\d{2}-\d{2})/u', $content, $m)) {
      return $m[1];
    }
    if (preg_match('/date[:\s]+(\d{4}-\d{2}-\d{2})/i', $content, $m)) {
      return $m[1];
    }
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $content, $m)) {
      return $m[0];
    }
    return null;
  }

  private function extractTotal(string $content): ?float {
    $patterns = [
      '/本单额[：:]\s*(\d+(?:\.\d+)?)/u',
      '/grand\s*total[:\s]+([0-9,.]+)/i',
      '/total[:\s]+\$?\s*([0-9,.]+)/i',
      '/合计[:\s]*\$?\s*([0-9,.]+)/u',
    ];
    
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $content, $m)) {
        $val = str_replace(',', '', $m[1]);
        return is_numeric($val) ? (float)$val : null;
      }
    }
    return null;
  }

  private function extractCustomer(string $content): string {
    if (preg_match('/客[户戶][：:]\s*([^\n]+)/u', $content, $m)) {
      return trim($m[1]);
    }
    return '';
  }

  private function extractInvoiceNumber(string $content): ?string {
    if (preg_match('/批次[：:]\s*(\d+)/u', $content, $m)) {
      return $m[1];
    }
    if (preg_match('/invoice\s*#?\s*[:\s]*([A-Z0-9\-]+)/i', $content, $m)) {
      return $m[1];
    }
    return null;
  }

  private function extractTableItems(string $content): array {
    // Look for HTML tables
    if (preg_match('/<table[^>]*>(.+?)<\/table>/is', $content, $m)) {
      return $this->parseHtmlTableItems($m[0]);
    }
    
    // Look for markdown tables
    if (preg_match('/\|.+\|[\r\n]+\|[\-:]+\|/s', $content)) {
      return $this->parseMarkdownTableItems($content);
    }
    
    return [];
  }

  private function parseHtmlTableItems(string $html): array {
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $rows = [];
    $trs = $dom->getElementsByTagName('tr');
    foreach ($trs as $tr) {
      $cells = [];
      foreach ($tr->childNodes as $td) {
        if (!($td instanceof \DOMElement)) continue;
        if (!in_array(strtolower($td->tagName), ['td','th'], true)) continue;
        $cells[] = trim($td->textContent ?? '');
      }
      if ($cells) $rows[] = $cells;
    }

    if (count($rows) < 2) return [];

    // Detect columns
    $header = array_map('strtolower', $rows[0]);
    $colMap = $this->detectColumns($header);

    return $this->rowsToItems(array_slice($rows, 1), $colMap);
  }

  private function parseMarkdownTableItems(string $content): array {
    $lines = explode("\n", $content);
    $rows = [];
    
    foreach ($lines as $line) {
      $line = trim($line);
      if (empty($line) || !preg_match('/^\|/', $line)) continue;
      if (preg_match('/^\|[\s\-:]+\|$/', $line)) continue; // Skip separator
      
      $cells = array_map('trim', explode('|', trim($line, '|')));
      if (!empty($cells)) {
        $rows[] = $cells;
      }
    }

    if (count($rows) < 2) return [];

    $header = array_map('strtolower', $rows[0]);
    $colMap = $this->detectColumns($header);

    return $this->rowsToItems(array_slice($rows, 1), $colMap);
  }

  private function detectColumns(array $header): array {
    $find = function(array $cands) use ($header) {
      foreach ($header as $i => $col) {
        foreach ($cands as $c) {
          if (strpos($col, $c) !== false) return $i;
        }
      }
      return -1;
    };

    return [
      'code' => $find(['code', 'sku', '款号', '編號', '编号']),
      'name' => $find(['name', 'description', 'item', 'product', '名称', '名稱', '產品', '项目']),
      'qty' => $find(['qty', 'quantity', '数量', '數量']),
      'unit_price' => $find(['price', 'unit', '单价', '單價']),
      'total' => $find(['total', 'amount', '金额', '金額', '合计']),
    ];
  }

  private function rowsToItems(array $rows, array $colMap): array {
    $items = [];
    
    foreach ($rows as $row) {
      $code = $colMap['code'] >= 0 ? trim($row[$colMap['code']] ?? '') : '';
      $name = $colMap['name'] >= 0 ? trim($row[$colMap['name']] ?? '') : '';
      $qtyStr = $colMap['qty'] >= 0 ? ($row[$colMap['qty']] ?? '1') : '1';
      $priceStr = $colMap['unit_price'] >= 0 ? ($row[$colMap['unit_price']] ?? '0') : '0';
      $totalStr = $colMap['total'] >= 0 ? ($row[$colMap['total']] ?? '0') : '0';

      // Skip summary rows
      if (preg_match('/^(合计|total|subtotal|grand)/i', $code) || 
          preg_match('/^(合计|total|subtotal|grand)/i', $name)) {
        continue;
      }

      if (empty($code) && empty($name)) continue;

      $qty = (float)preg_replace('/[^\d.\-]/', '', $qtyStr) ?: 1;
      $price = (float)preg_replace('/[^\d.\-]/', '', str_replace(',', '', $priceStr));
      $total = (float)preg_replace('/[^\d.\-]/', '', str_replace(',', '', $totalStr));

      if ($total <= 0 && $qty > 0 && $price > 0) {
        $total = $qty * $price;
      }

      $items[] = [
        'code' => $code,
        'name' => $name ?: $code,
        'qty' => $qty,
        'unit_price' => $price,
        'total' => round($total, 2),
      ];
    }

    return $items;
  }
}
