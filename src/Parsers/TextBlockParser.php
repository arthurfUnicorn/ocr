<?php
declare(strict_types=1);

namespace Parsers;

require_once __DIR__ . '/Traits/SmartFieldMapping.php';
require_once __DIR__ . '/Traits/TableExtraction.php';
require_once __DIR__ . '/Traits/TextBlockParsing.php';

/**
 * TextBlockParser - 處理無表格結構的發票
 * 
 * 適用於：
 * - 手寫發票
 * - 簡單格式發票
 * - OCR 無法識別表格的發票
 * - 純文字格式的發票
 */
class TextBlockParser extends AbstractParser {
    
    use Traits\SmartFieldMapping;
    use Traits\TableExtraction;
    use Traits\TextBlockParsing;

    public function getId(): string {
        return 'text_block';
    }

    public function getName(): string {
        return 'Text Block Parser (Non-tabular)';
    }

    public function getSupportedExtensions(): array {
        return ['json', 'md', 'txt'];
    }

    /**
     * 評估是否可以解析這些文件
     * 返回置信度分數
     */
    public function canParse(array $files): float {
        $score = 0.0;
        $checked = 0;

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if ($ext === 'json') {
                $json = $this->readJsonFile($file);
                if (!$json) continue;
                
                $checked++;
                
                // 檢查是否是 doc_parser 格式但沒有表格
                $root = $this->normalizeJsonRoot($json);
                if (isset($root['parsing_res_list'])) {
                    $hasTable = false;
                    $hasText = false;
                    
                    foreach ($root['parsing_res_list'] as $block) {
                        $label = strtolower((string)($block['block_label'] ?? ''));
                        if (strpos($label, 'table') !== false) {
                            $hasTable = true;
                        }
                        if (in_array($label, ['text', 'paragraph', 'title'])) {
                            $hasText = true;
                        }
                    }
                    
                    // 如果有文字但沒有表格，這是我們的目標
                    if ($hasText && !$hasTable) {
                        $score += 0.8;
                    } else if ($hasText && $hasTable) {
                        // 有表格的話，降低優先級
                        $score += 0.2;
                    }
                }
            } else if (in_array($ext, ['md', 'txt'])) {
                $content = $this->readTextFile($file);
                if (!$content) continue;
                
                $checked++;
                
                // 檢查是否有表格
                $hasHtmlTable = preg_match('/<table/i', $content);
                $hasMdTable = preg_match('/\|.+\|[\r\n]+\|[\-:]+\|/', $content);
                
                if (!$hasHtmlTable && !$hasMdTable) {
                    // 沒有表格，檢查是否有發票相關內容
                    $keywords = ['total', 'amount', 'qty', 'price', '金额', '數量', '单价', '合计'];
                    $keywordCount = 0;
                    foreach ($keywords as $kw) {
                        if (stripos($content, $kw) !== false) {
                            $keywordCount++;
                        }
                    }
                    if ($keywordCount >= 2) {
                        $score += 0.6;
                    }
                }
            }
        }

        return $checked > 0 ? min(1.0, $score / $checked) : 0.0;
    }

    /**
     * 解析文件
     */
    public function parse(array $files): array {
        $invoices = [];

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if ($ext === 'json') {
                $invoice = $this->parseJsonFile($file);
            } else {
                $invoice = $this->parseTextFile($file);
            }

            if ($invoice !== null && !empty($invoice['items'])) {
                $invoices[] = $this->normalizeInvoice($invoice);
            }
        }

        return $invoices;
    }

    /**
     * 解析 JSON 文件（doc_parser 格式）
     */
    protected function parseJsonFile(array $file): ?array {
        $json = $this->readJsonFile($file);
        if (!$json) return null;

        $root = $this->normalizeJsonRoot($json);
        $blocks = $root['parsing_res_list'] ?? [];
        
        if (empty($blocks)) return null;

        // 收集所有文字內容
        $allText = '';
        $textBlocks = [];
        
        foreach ($blocks as $block) {
            $label = strtolower((string)($block['block_label'] ?? ''));
            $content = (string)($block['block_content'] ?? '');
            
            // 跳過表格（如果有的話，由其他解析器處理）
            if (strpos($label, 'table') !== false) continue;
            
            // 收集文字區塊
            if (in_array($label, ['text', 'paragraph', 'title', 'list', ''])) {
                $textBlocks[] = [
                    'label' => $label,
                    'content' => $content,
                    'bbox' => $block['block_bbox'] ?? null,
                ];
                $allText .= $content . "\n";
            }
        }

        if (trim($allText) === '') return null;

        // 提取發票信息
        $header = $this->extractInvoiceHeader($allText);
        $items = $this->extractItemsFromText($allText);

        // 如果純文字方法找不到項目，嘗試從區塊結構提取
        if (empty($items)) {
            $items = $this->extractItemsFromBlocks($textBlocks);
        }

        $calcTotal = array_sum(array_column($items, 'total'));

        return [
            'source_file' => $file['name'],
            'supplier_name' => $header['supplier_name'],
            'customer_name' => $header['customer_name'],
            'invoice_date' => $header['invoice_date'],
            'invoice_number' => $header['invoice_number'],
            'declared_total' => $header['total'],
            'calc_total' => round($calcTotal, 2),
            'currency' => $header['currency'],
            'items' => $items,
        ];
    }

    /**
     * 解析文字文件
     */
    protected function parseTextFile(array $file): ?array {
        $content = $this->readTextFile($file);
        if (!$content) return null;

        $header = $this->extractInvoiceHeader($content);
        $items = $this->extractItemsFromText($content);

        $calcTotal = array_sum(array_column($items, 'total'));

        return [
            'source_file' => $file['name'],
            'supplier_name' => $header['supplier_name'],
            'customer_name' => $header['customer_name'],
            'invoice_date' => $header['invoice_date'],
            'invoice_number' => $header['invoice_number'],
            'declared_total' => $header['total'],
            'calc_total' => round($calcTotal, 2),
            'currency' => $header['currency'],
            'items' => $items,
        ];
    }

    /**
     * 從文字區塊中提取項目
     * 分析區塊的空間位置來識別項目列表
     */
    protected function extractItemsFromBlocks(array $blocks): array {
        $items = [];
        
        // 按 Y 坐標排序區塊
        usort($blocks, function($a, $b) {
            $ay = $a['bbox'][1] ?? 0;
            $by = $b['bbox'][1] ?? 0;
            return $ay <=> $by;
        });

        // 嘗試識別連續的項目區塊
        $currentGroup = [];
        $prevY = null;
        $threshold = 50; // Y 座標閾值

        foreach ($blocks as $block) {
            $y = $block['bbox'][1] ?? 0;
            $content = $block['content'];

            // 如果與上一個區塊在同一行附近
            if ($prevY !== null && abs($y - $prevY) < $threshold) {
                $currentGroup[] = $content;
            } else {
                // 處理之前的組
                if (!empty($currentGroup)) {
                    $item = $this->parseGroupAsItem($currentGroup);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
                $currentGroup = [$content];
            }
            $prevY = $y;
        }

        // 處理最後一組
        if (!empty($currentGroup)) {
            $item = $this->parseGroupAsItem($currentGroup);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * 將一組文字解析為項目
     */
    protected function parseGroupAsItem(array $group): ?array {
        $text = implode(' ', $group);
        
        // 提取數字
        preg_match_all('/([\d,]+\.?\d*)/', $text, $numMatches);
        $numbers = array_filter(
            array_map(function($n) {
                return (float)str_replace(',', '', $n);
            }, $numMatches[1]),
            function($n) { return $n > 0; }
        );
        $numbers = array_values($numbers);

        // 提取名稱（移除數字後的文字）
        $name = preg_replace('/[\d,]+\.?\d*/', '', $text);
        $name = $this->cleanItemName($name);

        // 需要有名稱和至少一個數字
        if (mb_strlen($name) < 2 || empty($numbers)) {
            return null;
        }

        // 解析數字
        $qty = 1;
        $unitPrice = 0;
        $total = 0;

        if (count($numbers) >= 3) {
            $qty = $numbers[0];
            $unitPrice = $numbers[1];
            $total = $numbers[2];
        } else if (count($numbers) == 2) {
            if ($numbers[0] < $numbers[1] && $numbers[0] <= 100) {
                $qty = $numbers[0];
                $total = $numbers[1];
                $unitPrice = $total / $qty;
            } else {
                $unitPrice = $numbers[0];
                $total = $numbers[1];
            }
        } else {
            $total = $numbers[0];
        }

        return [
            'code' => '',
            'name' => $name,
            'qty' => round($qty, 4),
            'unit_price' => round($unitPrice, 4),
            'total' => round($total, 2),
            'metadata' => ['parse_method' => 'block_group'],
        ];
    }

    /**
     * 標準化 JSON 根節點
     */
    protected function normalizeJsonRoot(array $json): array {
        // 處理可能的嵌套結構
        if (isset($json['result']) && is_array($json['result'])) {
            return $json['result'];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        return $json;
    }
}
