<?php
declare(strict_types=1);

namespace Parsers;

/**
 * DocParserJsonParser - PaddleOCR doc_parser 格式解析器
 * 
 * 增強版：
 * - 使用智能字段映射
 * - 支持更多表格格式
 * - 更好的數據提取
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

    /**
     * 評估是否可以解析
     */
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
            $root = $this->normalizeRoot($json);
            
            // 檢查 doc_parser 特徵結構
            if (isset($root['parsing_res_list']) && is_array($root['parsing_res_list'])) {
                $score += 0.5;
                
                $blocks = $root['parsing_res_list'];
                if (!empty($blocks)) {
                    $firstBlock = $blocks[0];
                    
                    // 檢查典型的區塊結構
                    if (isset($firstBlock['block_label']) && isset($firstBlock['block_content'])) {
                        $score += 0.3;
                    }
                    if (isset($firstBlock['block_bbox'])) {
                        $score += 0.2;
                    }
                }
            }
            
            // 其他 doc_parser 標誌
            if (isset($root['layout_det_res'])) {
                $score += 0.1;
            }
            if (isset($root['model_settings'])) {
                $score += 0.1;
            }
        }

        return $checked > 0 ? min(1.0, $score / $checked) : 0.0;
    }

    /**
     * 解析文件
     */
    public function parse(array $files): array {
        $invoices = [];
        $groups = $this->groupFilesByBaseName($files);

        foreach ($groups as $baseName => $group) {
            $jsonFile = $group['json'] ?? null;
            $mdFile = $group['md'] ?? null;

            if (!$jsonFile) continue;

            $invoice = $this->parseJsonFile($jsonFile, $mdFile);
            if ($invoice && !empty($invoice['items'])) {
                $invoices[] = $this->normalizeInvoice($invoice);
            }
        }

        // 如果沒有按組匹配到，嘗試單獨處理每個 JSON
        if (empty($invoices)) {
            foreach ($this->filterByExtensions($files, ['json']) as $file) {
                $invoice = $this->parseJsonFile($file, null);
                if ($invoice && !empty($invoice['items'])) {
                    $invoices[] = $this->normalizeInvoice($invoice);
                }
            }
        }

        return $invoices;
    }

    /**
     * 解析單個 JSON 文件
     */
    protected function parseJsonFile(array $jsonFile, ?array $mdFile): ?array {
        $json = $this->readJsonFile($jsonFile);
        if (!$json) return null;

        $root = $this->normalizeRoot($json);
        $blocks = $root['parsing_res_list'] ?? [];
        
        if (empty($blocks)) return null;

        // 收集信息
        $tables = $this->collectTables($blocks);
        $textBlocks = $this->collectTextBlocks($blocks);
        $allText = implode("\n", $textBlocks);

        // 提取發票信息
        $supplierName = $this->extractSupplierName($textBlocks, $allText);
        $customerName = $this->extractCustomerName($allText);
        $invoiceDate = $this->extractDate($allText);
        $invoiceNumber = $this->extractInvoiceNumber($allText);
        $declaredTotal = $this->extractTotal($textBlocks);

        // 提取項目
        $items = [];
        if (!empty($tables)) {
            $bestTable = $this->pickBestTable($tables);
            if ($bestTable) {
                $items = $this->extractItemsFromTableData($bestTable);
            }
        }

        // 計算總額
        $calcTotal = array_sum(array_column($items, 'total'));

        return [
            'source_file' => $jsonFile['name'],
            'supplier_name' => $supplierName,
            'customer_name' => $customerName,
            'invoice_date' => $invoiceDate,
            'invoice_number' => $invoiceNumber,
            'declared_total' => $declaredTotal,
            'calc_total' => round($calcTotal, 2),
            'items' => $items,
        ];
    }

    /**
     * 標準化 JSON 根節點
     */
    protected function normalizeRoot(array $json): array {
        if (isset($json['result']) && is_array($json['result'])) {
            return $json['result'];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        return $json;
    }

    /**
     * 收集表格區塊
     */
    protected function collectTables(array $blocks): array {
        $tables = [];
        
        foreach ($blocks as $block) {
            $label = strtolower((string)($block['block_label'] ?? ''));
            if (strpos($label, 'table') === false) continue;
            
            $html = (string)($block['block_content'] ?? '');
            $tableData = $this->parseHtmlTable($html);
            
            if ($tableData && !empty($tableData['rows'])) {
                $tables[] = $tableData;
            }
        }
        
        return $tables;
    }

    /**
     * 解析 HTML 表格
     */
    protected function parseHtmlTable(string $html): ?array {
        if (trim($html) === '') return null;
        
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        if (!$ok) return null;

        $tableElements = $dom->getElementsByTagName('table');
        if ($tableElements->length === 0) return null;

        $rows = [];
        $trs = $tableElements->item(0)->getElementsByTagName('tr');
        
        foreach ($trs as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof \DOMElement)) continue;
                if (!in_array(strtolower($td->tagName), ['td', 'th'], true)) continue;
                $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent ?? ''));
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }

        return [
            'rows' => $rows,
            'maxCols' => !empty($rows) ? max(array_map('count', $rows)) : 0,
            'rowCount' => count($rows),
        ];
    }

    /**
     * 收集文字區塊
     */
    protected function collectTextBlocks(array $blocks): array {
        $texts = [];
        
        foreach ($blocks as $block) {
            $label = strtolower((string)($block['block_label'] ?? ''));
            if (strpos($label, 'table') !== false) continue;
            
            $content = $block['block_content'] ?? '';
            $text = is_string($content) ? strip_tags($content) : '';
            $text = trim(preg_replace('/\s+/', ' ', $text));
            
            if (!empty($text)) {
                $texts[] = $text;
            }
        }
        
        return $texts;
    }

    /**
     * 提取供應商名稱
     */
    protected function extractSupplierName(array $textBlocks, string $allText): string {
        // 先嘗試從特定標籤提取
        $patterns = [
            '/供[应應]商[：:]\s*([^\n]+)/u',
            '/from[:\s]+([^\n]+)/i',
            '/vendor[:\s]+([^\n]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $allText, $m)) {
                return trim($m[1]);
            }
        }

        // 嘗試從開頭的文字區塊提取（通常第一行是公司名）
        foreach ($textBlocks as $text) {
            // 跳過日期
            if (preg_match('/^\d{4}[-\/]/', $text)) continue;
            
            // 檢查是否像公司名
            if (preg_match('/(有限公司|co\.?\s*ltd|trading|enterprise)/iu', $text)) {
                return trim($text);
            }
            
            // 如果是較短的非數字文字，可能是公司名
            if (mb_strlen($text) > 3 && mb_strlen($text) < 100 && !preg_match('/^\d/', $text)) {
                return trim($text);
            }
        }

        return '';
    }

    /**
     * 提取客戶名稱
     */
    protected function extractCustomerName(string $text): string {
        $patterns = [
            '/客[户戶][：:]\s*([^\n]+)/u',
            '/to[:\s]+([^\n]+)/i',
            '/bill\s*to[:\s]+([^\n]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }

        return '';
    }

    /**
     * 提取日期
     */
    protected function extractDate(string $text): ?string {
        $patterns = [
            '/日期[：:]\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/u',
            '/date[:\s]+(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i',
            '/(\d{4})年(\d{1,2})月(\d{1,2})日/u',
            '/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                if (isset($m[3])) {
                    // 中文日期
                    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                }
                return $this->normalizeDate($m[1]);
            }
        }

        return null;
    }

    /**
     * 提取發票號碼
     */
    protected function extractInvoiceNumber(string $text): ?string {
        $patterns = [
            '/發票[号號][：:]\s*([A-Za-z0-9\-]+)/u',
            '/invoice\s*#?\s*[:\s]*([A-Za-z0-9\-]+)/i',
            '/批次[：:]\s*(\d+)/u',
            '/order\s*#?\s*[:\s]*([A-Za-z0-9\-]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * 提取總額
     */
    protected function extractTotal(array $textBlocks): ?float {
        $allText = implode("\n", $textBlocks);
        
        $patterns = [
            '/本單額[：:]\s*([\d,]+\.?\d*)/u',
            '/grand\s*total[:\s]*[\$¥￥]?\s*([\d,]+\.?\d*)/i',
            '/total[:\s]*[\$¥￥]?\s*([\d,]+\.?\d*)/i',
            '/合[计計][：:]\s*[\$¥￥]?\s*([\d,]+\.?\d*)/u',
        ];

        // 找最後出現的總額
        $lastValue = null;
        $lastPos = -1;

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $allText, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    if ($match[1] > $lastPos) {
                        $lastPos = $match[1];
                        $lastValue = $match[0];
                    }
                }
            }
        }

        if ($lastValue !== null) {
            $value = str_replace(',', '', $lastValue);
            return is_numeric($value) ? (float)$value : null;
        }

        return null;
    }

    /**
     * 選擇最佳表格
     */
    protected function pickBestTable(array $tables): ?array {
        if (empty($tables)) return null;

        $best = null;
        $bestScore = -1;

        foreach ($tables as $table) {
            $score = $this->scoreTableAsInvoiceItems($table);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $table;
            }
        }

        return $best;
    }
}
