<?php
declare(strict_types=1);

namespace Parsers\Traits;

/**
 * TableExtraction - 增強的表格提取功能
 * 
 * 支持從 HTML 表格和 Markdown 表格中提取數據
 * 包含智能表格識別和合併儲存格處理
 */
trait TableExtraction {

    /**
     * 從 HTML 內容中提取所有表格
     * 
     * @param string $html HTML 內容
     * @return array 表格數組
     */
    protected function extractHtmlTables(string $html): array {
        if (trim($html) === '') return [];

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        if (!$ok) return [];

        $tables = [];
        $tableElements = $dom->getElementsByTagName('table');

        foreach ($tableElements as $tableIdx => $table) {
            $tableData = $this->parseHtmlTableElement($table);
            if (!empty($tableData['rows'])) {
                $tableData['index'] = $tableIdx;
                $tables[] = $tableData;
            }
        }

        return $tables;
    }

    /**
     * 解析單個 HTML 表格元素
     */
    protected function parseHtmlTableElement(\DOMElement $table): array {
        $rows = [];
        $maxCols = 0;
        
        $trs = $table->getElementsByTagName('tr');
        
        foreach ($trs as $tr) {
            $cells = [];
            $colIndex = 0;
            
            foreach ($tr->childNodes as $cell) {
                if (!($cell instanceof \DOMElement)) continue;
                $tagName = strtolower($cell->tagName);
                if (!in_array($tagName, ['td', 'th'], true)) continue;

                // 處理 colspan
                $colspan = (int)($cell->getAttribute('colspan') ?: 1);
                $rowspan = (int)($cell->getAttribute('rowspan') ?: 1);
                
                $text = $this->cleanCellText($cell->textContent);
                
                // 填充 colspan
                for ($i = 0; $i < $colspan; $i++) {
                    $cells[$colIndex] = [
                        'text' => $i === 0 ? $text : '',
                        'rowspan' => $rowspan,
                        'colspan' => $colspan,
                        'isHeader' => $tagName === 'th',
                    ];
                    $colIndex++;
                }
            }

            if (!empty($cells)) {
                $rows[] = $cells;
                $maxCols = max($maxCols, count($cells));
            }
        }

        // 處理 rowspan
        $rows = $this->processRowspans($rows, $maxCols);

        // 簡化為純文字數組
        $simpleRows = [];
        foreach ($rows as $row) {
            $simpleRow = [];
            for ($i = 0; $i < $maxCols; $i++) {
                $simpleRow[] = isset($row[$i]) ? $row[$i]['text'] : '';
            }
            $simpleRows[] = $simpleRow;
        }

        return [
            'rows' => $simpleRows,
            'maxCols' => $maxCols,
            'rowCount' => count($simpleRows),
        ];
    }

    /**
     * 處理 rowspan - 將跨行的儲存格值複製到下方
     */
    protected function processRowspans(array $rows, int $maxCols): array {
        $spanTracker = []; // [col => ['text' => '', 'remaining' => 0]]

        foreach ($rows as $rowIdx => &$row) {
            // 先處理之前行的 rowspan
            foreach ($spanTracker as $col => $span) {
                if ($span['remaining'] > 0) {
                    // 插入空位
                    array_splice($row, $col, 0, [['text' => $span['text'], 'rowspan' => 1, 'colspan' => 1, 'isHeader' => false]]);
                    $spanTracker[$col]['remaining']--;
                }
            }

            // 記錄新的 rowspan
            foreach ($row as $colIdx => $cell) {
                if (isset($cell['rowspan']) && $cell['rowspan'] > 1) {
                    $spanTracker[$colIdx] = [
                        'text' => $cell['text'],
                        'remaining' => $cell['rowspan'] - 1,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * 從 Markdown 內容中提取表格
     * 
     * @param string $markdown Markdown 內容
     * @return array 表格數組
     */
    protected function extractMarkdownTables(string $markdown): array {
        $tables = [];
        
        // 匹配 Markdown 表格
        // |col1|col2|col3|
        // |---|---|---|
        // |data|data|data|
        $pattern = '/(\|[^\n]+\|[\r\n]+\|[\-:\|\s]+\|[\r\n]+(?:\|[^\n]+\|[\r\n]*)+)/s';
        
        if (preg_match_all($pattern, $markdown, $matches)) {
            foreach ($matches[1] as $idx => $tableText) {
                $tableData = $this->parseMarkdownTable($tableText);
                if (!empty($tableData['rows'])) {
                    $tableData['index'] = $idx;
                    $tables[] = $tableData;
                }
            }
        }

        return $tables;
    }

    /**
     * 解析 Markdown 表格文字
     */
    protected function parseMarkdownTable(string $tableText): array {
        $lines = explode("\n", trim($tableText));
        $rows = [];
        $isFirstDataRow = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 跳過分隔行 |---|---|
            if (preg_match('/^\|[\-:\|\s]+\|$/', $line)) {
                continue;
            }

            // 解析單元格
            $cells = [];
            $parts = explode('|', $line);
            
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' || count($cells) > 0) {
                    $cells[] = $this->cleanCellText($part);
                }
            }

            // 移除首尾空元素
            if (!empty($cells) && $cells[0] === '') array_shift($cells);
            if (!empty($cells) && end($cells) === '') array_pop($cells);

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
     * 清理儲存格文字
     */
    protected function cleanCellText(?string $text): string {
        if ($text === null) return '';
        
        // 移除多餘空白
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // 移除常見的 OCR 噪音
        $text = preg_replace('/^[\|\-\+]+$/', '', $text);
        
        return $text;
    }

    /**
     * 評估表格是否可能是發票項目表
     * 
     * @param array $table 表格數據
     * @return float 0.0-1.0 的評分
     */
    protected function scoreTableAsInvoiceItems(array $table): float {
        if (empty($table['rows']) || count($table['rows']) < 2) {
            return 0.0;
        }

        $score = 0.0;
        $header = $table['rows'][0];
        $headerText = strtolower(implode(' ', $header));

        // 檢查表頭關鍵詞
        $keywords = [
            'high' => ['qty', 'quantity', 'price', 'amount', 'total', '數量', '单价', '單價', '金额', '金額', '合计', '合計'],
            'medium' => ['item', 'product', 'description', 'code', '品名', '名称', '名稱', '货品', '貨品', '款号', '款號'],
            'low' => ['unit', 'size', 'color', '规格', '規格', '颜色', '顏色', '备注', '備註'],
        ];

        foreach ($keywords['high'] as $kw) {
            if (mb_stripos($headerText, $kw) !== false) {
                $score += 0.15;
            }
        }
        foreach ($keywords['medium'] as $kw) {
            if (mb_stripos($headerText, $kw) !== false) {
                $score += 0.08;
            }
        }
        foreach ($keywords['low'] as $kw) {
            if (mb_stripos($headerText, $kw) !== false) {
                $score += 0.03;
            }
        }

        // 根據數據行數加分
        $dataRows = count($table['rows']) - 1;
        if ($dataRows >= 1 && $dataRows <= 100) {
            $score += min(0.2, $dataRows * 0.02);
        }

        // 檢查是否有數字列
        $hasNumericColumn = false;
        foreach ($table['rows'] as $rowIdx => $row) {
            if ($rowIdx === 0) continue; // 跳過表頭
            foreach ($row as $cell) {
                if (preg_match('/^\d+(?:[.,]\d+)?$/', trim($cell))) {
                    $hasNumericColumn = true;
                    break 2;
                }
            }
        }
        if ($hasNumericColumn) {
            $score += 0.15;
        }

        return min(1.0, $score);
    }

    /**
     * 選擇最佳的發票項目表格
     * 
     * @param array $tables 表格數組
     * @return array|null 最佳表格或 null
     */
    protected function selectBestInvoiceTable(array $tables): ?array {
        if (empty($tables)) return null;

        $bestTable = null;
        $bestScore = 0.0;

        foreach ($tables as $table) {
            $score = $this->scoreTableAsInvoiceItems($table);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTable = $table;
            }
        }

        // 只返回評分達到閾值的表格
        return $bestScore >= 0.3 ? $bestTable : null;
    }

    /**
     * 從表格中提取項目數據
     * 
     * @param array $table 表格數據
     * @param array $columnMap 列映射 (可選)
     * @return array 項目數組
     */
    protected function extractItemsFromTableData(array $table, array $columnMap = []): array {
        $rows = $table['rows'] ?? [];
        if (count($rows) < 2) return [];

        // 如果沒有提供列映射，嘗試自動映射
        if (empty($columnMap)) {
            $columnMap = $this->mapHeaderRow($rows[0]);
        }

        $items = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $item = $this->extractItemFromRow($row, $columnMap);
            
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * 從單行數據中提取項目
     */
    protected function extractItemFromRow(array $row, array $columnMap): ?array {
        $getValue = function($field) use ($row, $columnMap) {
            if (!isset($columnMap[$field])) return '';
            $idx = $columnMap[$field];
            return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
        };

        $code = $getValue('code');
        $name = $getValue('name');
        $color = $getValue('color');
        $size = $getValue('size');
        $qtyStr = $getValue('qty');
        $unitPriceStr = $getValue('unit_price');
        $totalStr = $getValue('total');

        // 跳過空行或摘要行
        $skipPatterns = ['/^(合计|total|subtotal|grand|小計|sum)$/iu'];
        foreach ([$code, $name] as $val) {
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $val)) {
                    return null;
                }
            }
        }

        // 必須有名稱或代碼
        if ($name === '' && $code === '') {
            return null;
        }

        // 解析數字
        $qty = $this->parseNumber($qtyStr);
        $unitPrice = $this->parseNumber($unitPriceStr);
        $total = $this->parseNumber($totalStr);

        // 計算缺失值
        if ($qty <= 0 && $unitPrice > 0 && $total > 0) {
            $qty = $total / $unitPrice;
            // 四捨五入到整數如果接近
            if (abs($qty - round($qty)) < 0.01) {
                $qty = round($qty);
            }
        }
        if ($qty <= 0) $qty = 1;

        if ($unitPrice <= 0 && $qty > 0 && $total > 0) {
            $unitPrice = $total / $qty;
        }

        if ($total <= 0 && $qty > 0 && $unitPrice > 0) {
            $total = $qty * $unitPrice;
        }

        // 組合完整名稱
        $fullName = $name;
        if ($color !== '') {
            $fullName .= ' - ' . $color;
        }
        if ($size !== '') {
            $fullName .= ' [' . $size . ']';
        }

        return [
            'code' => $code,
            'name' => $fullName,
            'qty' => round($qty, 4),
            'unit_price' => round($unitPrice, 4),
            'total' => round($total, 2),
            'metadata' => [
                'color' => $color,
                'size' => $size,
                'remark' => $getValue('remark'),
            ],
        ];
    }

    /**
     * 解析數字（處理各種格式）
     */
    protected function parseNumber(string $str): float {
        if (trim($str) === '') return 0.0;
        
        // 移除貨幣符號和空白
        $str = preg_replace('/[¥￥$€£\s]/', '', $str);
        
        // 處理千分位
        $str = str_replace(',', '', $str);
        
        // 嘗試提取數字
        if (preg_match('/-?[\d.]+/', $str, $m)) {
            return (float)$m[0];
        }
        
        return 0.0;
    }
}
