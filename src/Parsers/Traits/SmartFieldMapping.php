<?php
declare(strict_types=1);

namespace Parsers\Traits;

/**
 * SmartFieldMapping - 智能字段映射
 * 
 * 使用多語言正則表達式匹配表頭，支持中文、英文、繁體、簡體
 * 可以處理各種 OCR 輸出的發票格式
 */
trait SmartFieldMapping {

    /**
     * 字段匹配模式 - 按優先順序排列
     * 每個字段可以有多個匹配模式
     */
    protected array $fieldPatterns = [
        'seq' => [
            '/^(#|no\.?|序号|序號|項次|项次|行号|行號|s\.?n\.?)$/iu',
            '/^(line|row|idx|index)$/iu',
        ],
        'code' => [
            '/^(code|款号|款號|編號|编号|货号|貨號|sku|item\s*#?|product\s*code|art\.?\s*no\.?)$/iu',
            '/^(型号|型號|article|ref|reference|barcode|條碼|条码|品号|品號)$/iu',
            '/^(part\s*no\.?|p\/n|material\s*no\.?)$/iu',
        ],
        'name' => [
            '/^(name|description|item|產品|产品|名称|名稱|品名|說明|说明|货品|貨品|商品)$/iu',
            '/^(物品|项目|項目|goods|product|material|desc\.?|描述|規格|规格)$/iu',
            '/^(detail|details|particulars|內容|内容)$/iu',
        ],
        'color' => [
            '/^(color|colour|颜色|顏色|色|col\.?)$/iu',
        ],
        'size' => [
            '/^(size|尺码|尺碼|尺寸|規格|规格|sz\.?)$/iu',
        ],
        'unit' => [
            '/^(unit|單位|单位|uom|u\/m)$/iu',
        ],
        'qty' => [
            '/^(qty|quantity|數量|数量|pcs|件数|件數|數|数)$/iu',
            '/^(order\s*qty|訂購量|订购量|amount|count|no\.?\s*of\s*units?)$/iu',
            '/^(件|個|个|pack|pkt|sets?|boxes?)$/iu',
        ],
        'unit_price' => [
            '/^(unit\s*price|price|單價|单价|售價|售价|cost|單|单)$/iu',
            '/^(@|each|per\s*unit|rate|u\.?\s*price|p\.?\s*u\.?)$/iu',
            '/^(price\/unit|價格|价格)$/iu',
        ],
        'total' => [
            '/^(total|amount|金額|金额|小計|小计|subtotal|line\s*total|amt\.?)$/iu',
            '/^(ext\.?\s*price|extended|sum|總額|总额|合計|合计|value)$/iu',
        ],
        'remark' => [
            '/^(remark|remarks|備註|备注|note|notes|memo|comment|附註|附注)$/iu',
        ],
        'discount' => [
            '/^(discount|折扣|disc\.?|off|減價|减价)$/iu',
        ],
    ];

    /**
     * 常見的貨幣符號和格式
     */
    protected array $currencyPatterns = [
        'CNY' => '/^(¥|￥|rmb|cny|人民币|人民幣)/iu',
        'HKD' => '/^(hk\$|hkd|港币|港幣)/iu',
        'USD' => '/^(\$|usd|us\$|美元|美金)/iu',
        'EUR' => '/^(€|eur|欧元|歐元)/iu',
        'GBP' => '/^(£|gbp|英镑|英鎊)/iu',
    ];

    /**
     * 智能匹配單個表頭到字段
     * 
     * @param string $header 表頭文字
     * @return string|null 匹配到的字段名，或 null
     */
    protected function smartMapColumn(string $header): ?string {
        $header = trim($header);
        
        // 移除常見的前後綴
        $header = preg_replace('/^[\(\[\{]|[\)\]\}]$/', '', $header);
        $header = trim($header);
        
        if ($header === '' || strlen($header) > 50) {
            return null;
        }

        foreach ($this->fieldPatterns as $field => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $header)) {
                    return $field;
                }
            }
        }

        // 模糊匹配 - 檢查是否包含關鍵詞
        return $this->fuzzyMatchColumn($header);
    }

    /**
     * 模糊匹配表頭
     */
    protected function fuzzyMatchColumn(string $header): ?string {
        $header = strtolower($header);
        
        $fuzzyMap = [
            'code' => ['款', '编', '編', 'code', 'sku', 'art', 'ref'],
            'name' => ['名', '品', 'name', 'desc', 'item', 'product'],
            'qty' => ['数', '數', 'qty', 'quantity', 'pcs', 'amount'],
            'unit_price' => ['价', '價', 'price', 'unit', 'rate', 'cost'],
            'total' => ['总', '總', '计', '計', 'total', 'amount', 'sum'],
            'color' => ['色', 'color', 'colour'],
            'size' => ['尺', 'size', '规', '規'],
        ];

        foreach ($fuzzyMap as $field => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($header, $kw) !== false) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * 映射整個表頭行
     * 
     * @param array $headers 表頭數組
     * @return array ['code' => 0, 'name' => 1, ...] 字段到列索引的映射
     */
    protected function mapHeaderRow(array $headers): array {
        $map = [];
        $usedIndices = [];

        // 第一遍：精確匹配
        foreach ($headers as $idx => $header) {
            $field = $this->smartMapColumn((string)$header);
            if ($field !== null && !isset($map[$field])) {
                $map[$field] = $idx;
                $usedIndices[$idx] = true;
            }
        }

        // 第二遍：根據位置和內容推斷
        if (!isset($map['name']) && !isset($map['code'])) {
            // 如果沒有找到名稱或代碼，取第一個文字列
            foreach ($headers as $idx => $header) {
                if (isset($usedIndices[$idx])) continue;
                $h = trim((string)$header);
                if ($h !== '' && !is_numeric($h)) {
                    $map['name'] = $idx;
                    $usedIndices[$idx] = true;
                    break;
                }
            }
        }

        // 根據列順序推斷數字列
        $numericCols = [];
        foreach ($headers as $idx => $header) {
            if (!isset($usedIndices[$idx])) {
                $numericCols[] = $idx;
            }
        }

        // 如果有未分配的數字列，嘗試按常見順序分配
        $numericFields = ['qty', 'unit_price', 'total'];
        $availableNumeric = array_diff($numericFields, array_keys($map));
        
        foreach ($availableNumeric as $field) {
            if (!empty($numericCols)) {
                $map[$field] = array_shift($numericCols);
            }
        }

        return $map;
    }

    /**
     * 根據數據模式推斷列類型
     * 
     * @param array $rows 數據行（不包括表頭）
     * @param int $colIndex 列索引
     * @return string|null 推斷的字段類型
     */
    protected function inferColumnType(array $rows, int $colIndex): ?string {
        $values = [];
        foreach ($rows as $row) {
            if (isset($row[$colIndex])) {
                $values[] = trim((string)$row[$colIndex]);
            }
        }

        if (empty($values)) return null;

        $numericCount = 0;
        $hasDecimals = false;
        $maxValue = 0;
        $totalChars = 0;

        foreach ($values as $v) {
            $clean = preg_replace('/[,\s]/', '', $v);
            if (is_numeric($clean)) {
                $numericCount++;
                $num = (float)$clean;
                if ($num > $maxValue) $maxValue = $num;
                if (strpos($clean, '.') !== false) $hasDecimals = true;
            }
            $totalChars += mb_strlen($v);
        }

        $numericRatio = $numericCount / count($values);

        // 如果大部分是數字
        if ($numericRatio > 0.8) {
            $avgValue = $maxValue / count($values);
            
            // 小整數可能是數量
            if (!$hasDecimals && $maxValue < 1000) {
                return 'qty';
            }
            // 有小數且數值較大可能是金額
            if ($hasDecimals || $maxValue > 100) {
                return 'total';
            }
        }

        // 平均字符長度較長的可能是名稱
        $avgChars = $totalChars / count($values);
        if ($avgChars > 10 && $numericRatio < 0.2) {
            return 'name';
        }

        return null;
    }

    /**
     * 檢測並提取貨幣
     * 
     * @param string $text 包含金額的文字
     * @return array ['currency' => 'HKD', 'amount' => 123.45]
     */
    protected function extractCurrency(string $text): array {
        $result = ['currency' => null, 'amount' => null];

        foreach ($this->currencyPatterns as $currency => $pattern) {
            if (preg_match($pattern, $text)) {
                $result['currency'] = $currency;
                break;
            }
        }

        // 提取數字
        if (preg_match('/([0-9][0-9,]*\.?\d*)/', $text, $m)) {
            $result['amount'] = (float)str_replace(',', '', $m[1]);
        }

        return $result;
    }
}
