<?php
declare(strict_types=1);

namespace Validators;

/**
 * InvoiceDataValidator - 發票數據驗證和修正
 * 
 * 功能：
 * - 驗證必填字段
 * - 修正缺失的計算值
 * - 修正常見 OCR 錯誤
 * - 標準化數據格式
 * - 檢測異常數據
 */
class InvoiceDataValidator {

    /**
     * 驗證配置
     */
    protected array $config = [
        'min_item_name_length' => 2,
        'max_item_name_length' => 200,
        'max_qty' => 100000,
        'max_unit_price' => 10000000,
        'max_total' => 100000000,
        'tolerance_percent' => 5, // 計算誤差容許百分比
    ];

    /**
     * 常見 OCR 錯誤映射
     */
    protected array $ocrFixes = [
        // 數字中的字母
        'O' => '0',
        'o' => '0',
        'l' => '1',
        'I' => '1',
        'Z' => '2',
        'S' => '5',
        'B' => '8',
        // 符號
        '，' => ',',
        '。' => '.',
    ];

    /**
     * 驗證結果
     */
    protected array $errors = [];
    protected array $warnings = [];
    protected array $fixes = [];

    /**
     * 設置配置
     */
    public function setConfig(array $config): self {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * 驗證並修正發票數據
     * 
     * @param array $invoice 發票數據
     * @return array ['invoice' => array, 'valid' => bool, 'errors' => array, 'warnings' => array, 'fixes' => array]
     */
    public function validateAndFix(array $invoice): array {
        $this->errors = [];
        $this->warnings = [];
        $this->fixes = [];

        // 步驟 1：基本結構驗證
        $invoice = $this->validateStructure($invoice);

        // 步驟 2：修正 OCR 錯誤
        $invoice = $this->fixOcrErrors($invoice);

        // 步驟 3：修正缺失的計算值
        $invoice = $this->fixMissingCalculations($invoice);

        // 步驟 4：標準化數據格式
        $invoice = $this->normalizeData($invoice);

        // 步驟 5：驗證數據合理性
        $this->validateDataRanges($invoice);

        // 步驟 6：驗證總額
        $this->validateTotals($invoice);

        return [
            'invoice' => $invoice,
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'fixes' => $this->fixes,
        ];
    }

    /**
     * 批量驗證發票
     */
    public function validateBatch(array $invoices): array {
        $results = [];
        $summary = [
            'total' => count($invoices),
            'valid' => 0,
            'invalid' => 0,
            'fixed' => 0,
        ];

        foreach ($invoices as $idx => $invoice) {
            $result = $this->validateAndFix($invoice);
            $results[] = $result;

            if ($result['valid']) {
                $summary['valid']++;
            } else {
                $summary['invalid']++;
            }

            if (!empty($result['fixes'])) {
                $summary['fixed']++;
            }
        }

        return [
            'invoices' => array_column($results, 'invoice'),
            'results' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * 驗證基本結構
     */
    protected function validateStructure(array $invoice): array {
        // 確保 items 是數組
        if (!isset($invoice['items']) || !is_array($invoice['items'])) {
            $invoice['items'] = [];
            $this->errors[] = 'Missing or invalid items array';
        }

        // 確保基本字段存在
        $defaults = [
            'source_file' => 'unknown',
            'supplier_name' => '',
            'customer_name' => '',
            'invoice_date' => null,
            'invoice_number' => null,
            'declared_total' => null,
            'calc_total' => 0,
            'currency' => null,
        ];

        foreach ($defaults as $key => $default) {
            if (!isset($invoice[$key])) {
                $invoice[$key] = $default;
            }
        }

        return $invoice;
    }

    /**
     * 修正 OCR 錯誤
     */
    protected function fixOcrErrors(array $invoice): array {
        // 修正供應商/客戶名稱
        if (!empty($invoice['supplier_name'])) {
            $fixed = $this->fixTextOcr($invoice['supplier_name']);
            if ($fixed !== $invoice['supplier_name']) {
                $this->fixes[] = "Fixed supplier_name OCR: '{$invoice['supplier_name']}' -> '{$fixed}'";
                $invoice['supplier_name'] = $fixed;
            }
        }

        // 修正項目
        foreach ($invoice['items'] as $idx => &$item) {
            // 修正項目名稱
            if (!empty($item['name'])) {
                $fixed = $this->fixTextOcr($item['name']);
                if ($fixed !== $item['name']) {
                    $this->fixes[] = "Item {$idx}: Fixed name OCR";
                    $item['name'] = $fixed;
                }
            }

            // 修正代碼
            if (!empty($item['code'])) {
                $fixed = $this->fixCodeOcr($item['code']);
                if ($fixed !== $item['code']) {
                    $this->fixes[] = "Item {$idx}: Fixed code OCR: '{$item['code']}' -> '{$fixed}'";
                    $item['code'] = $fixed;
                }
            }

            // 修正數字字段
            foreach (['qty', 'unit_price', 'total'] as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $original = $item[$field];
                    $item[$field] = $this->fixNumericOcr($original);
                    if ($item[$field] != $original) {
                        $this->fixes[] = "Item {$idx}: Fixed {$field} OCR: '{$original}' -> '{$item[$field]}'";
                    }
                }
            }
        }

        return $invoice;
    }

    /**
     * 修正文字 OCR 錯誤
     */
    protected function fixTextOcr(string $text): string {
        // 移除多餘空白
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // 修正常見的 OCR 替換（僅在明顯錯誤的位置）
        // 例如：數字旁邊的字母可能是 OCR 錯誤
        $text = preg_replace('/(?<=\d)O(?=\d)/', '0', $text);
        $text = preg_replace('/(?<=\d)l(?=\d)/', '1', $text);

        return $text;
    }

    /**
     * 修正代碼 OCR 錯誤
     */
    protected function fixCodeOcr(string $code): string {
        // 代碼通常是字母數字混合，更激進地修正
        $code = strtoupper(trim($code));
        
        // 如果看起來像純數字代碼，修正所有字母
        if (preg_match('/^[0-9OIlZSB]+$/', $code)) {
            foreach ($this->ocrFixes as $wrong => $correct) {
                $code = str_replace($wrong, $correct, $code);
            }
        }

        return $code;
    }

    /**
     * 修正數字 OCR 錯誤
     */
    protected function fixNumericOcr($value): float {
        if (is_numeric($value)) {
            return (float)$value;
        }

        $str = (string)$value;
        
        // 修正常見錯誤
        $str = str_replace(['，', ' '], [',', ''], $str);
        $str = str_replace(',', '', $str); // 移除千分位
        
        // 修正字母
        foreach ($this->ocrFixes as $wrong => $correct) {
            $str = str_replace($wrong, $correct, $str);
        }

        // 提取數字
        if (preg_match('/-?[\d.]+/', $str, $m)) {
            return (float)$m[0];
        }

        return 0.0;
    }

    /**
     * 修正缺失的計算值
     */
    protected function fixMissingCalculations(array $invoice): array {
        foreach ($invoice['items'] as $idx => &$item) {
            $qty = (float)($item['qty'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $total = (float)($item['total'] ?? 0);

            // 情況 1：缺少總額
            if ($total <= 0 && $qty > 0 && $unitPrice > 0) {
                $item['total'] = round($qty * $unitPrice, 2);
                $this->fixes[] = "Item {$idx}: Calculated missing total = {$item['total']}";
            }

            // 情況 2：缺少單價
            if ($unitPrice <= 0 && $qty > 0 && $total > 0) {
                $item['unit_price'] = round($total / $qty, 4);
                $this->fixes[] = "Item {$idx}: Calculated missing unit_price = {$item['unit_price']}";
            }

            // 情況 3：缺少數量
            if ($qty <= 0 && $unitPrice > 0 && $total > 0) {
                $calculated = $total / $unitPrice;
                // 如果接近整數，使用整數
                if (abs($calculated - round($calculated)) < 0.01) {
                    $item['qty'] = round($calculated);
                } else {
                    $item['qty'] = round($calculated, 4);
                }
                $this->fixes[] = "Item {$idx}: Calculated missing qty = {$item['qty']}";
            }

            // 情況 4：只有總額，設置默認數量和單價
            if ($qty <= 0 && $unitPrice <= 0 && $total > 0) {
                $item['qty'] = 1;
                $item['unit_price'] = $total;
                $this->fixes[] = "Item {$idx}: Set default qty=1, unit_price={$total}";
            }

            // 確保數量至少為1
            if ($item['qty'] <= 0) {
                $item['qty'] = 1;
            }
        }

        // 重新計算總額
        $calcTotal = 0;
        foreach ($invoice['items'] as $item) {
            $calcTotal += (float)($item['total'] ?? 0);
        }
        $invoice['calc_total'] = round($calcTotal, 2);

        return $invoice;
    }

    /**
     * 標準化數據格式
     */
    protected function normalizeData(array $invoice): array {
        // 標準化日期
        if (!empty($invoice['invoice_date'])) {
            $invoice['invoice_date'] = $this->normalizeDate($invoice['invoice_date']);
        }

        // 標準化金額
        if ($invoice['declared_total'] !== null) {
            $invoice['declared_total'] = round((float)$invoice['declared_total'], 2);
        }

        // 標準化項目
        foreach ($invoice['items'] as &$item) {
            // 確保必要字段存在
            $item = array_merge([
                'code' => '',
                'name' => '',
                'description' => '',
                'qty' => 1,
                'unit' => '',
                'unit_price' => 0,
                'total' => 0,
                'metadata' => [],
            ], $item);

            // 確保字符串字段
            $item['code'] = trim((string)$item['code']);
            $item['name'] = trim((string)$item['name']);
            $item['description'] = trim((string)($item['description'] ?? ''));

            // 確保數字字段
            $item['qty'] = round((float)$item['qty'], 4);
            $item['unit_price'] = round((float)$item['unit_price'], 4);
            $item['total'] = round((float)$item['total'], 2);

            // 如果沒有名稱，使用代碼
            if ($item['name'] === '' && $item['code'] !== '') {
                $item['name'] = $item['code'];
            }

            // 生成代碼如果為空
            if ($item['code'] === '' && $item['name'] !== '') {
                $item['code'] = $this->generateItemCode($item['name']);
            }
        }

        // 移除空項目
        $invoice['items'] = array_filter($invoice['items'], function($item) {
            return !empty($item['name']) || !empty($item['code']);
        });
        $invoice['items'] = array_values($invoice['items']);

        return $invoice;
    }

    /**
     * 標準化日期格式
     */
    protected function normalizeDate(?string $date): ?string {
        if (empty($date)) return null;

        // 嘗試解析各種格式
        $formats = [
            'Y-m-d',
            'Y/m/d',
            'd-m-Y',
            'd/m/Y',
            'm-d-Y',
            'm/d/Y',
            'Y年m月d日',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $date);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        // 嘗試自動解析
        try {
            $parsed = new \DateTime($date);
            return $parsed->format('Y-m-d');
        } catch (\Exception $e) {
            $this->warnings[] = "Could not parse date: {$date}";
            return null;
        }
    }

    /**
     * 生成項目代碼
     */
    protected function generateItemCode(string $name): string {
        // 從名稱生成簡短代碼
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name);
        $name = mb_substr($name, 0, 10);
        
        if (empty($name)) {
            return 'ITEM' . mt_rand(1000, 9999);
        }

        return strtoupper($name);
    }

    /**
     * 驗證數據範圍
     */
    protected function validateDataRanges(array $invoice): void {
        foreach ($invoice['items'] as $idx => $item) {
            // 檢查名稱長度
            $nameLen = mb_strlen($item['name']);
            if ($nameLen < $this->config['min_item_name_length']) {
                $this->warnings[] = "Item {$idx}: Name too short ({$nameLen} chars)";
            }
            if ($nameLen > $this->config['max_item_name_length']) {
                $this->warnings[] = "Item {$idx}: Name too long ({$nameLen} chars)";
            }

            // 檢查數量
            if ($item['qty'] > $this->config['max_qty']) {
                $this->warnings[] = "Item {$idx}: Qty unusually high ({$item['qty']})";
            }
            if ($item['qty'] < 0) {
                $this->errors[] = "Item {$idx}: Negative qty ({$item['qty']})";
            }

            // 檢查單價
            if ($item['unit_price'] > $this->config['max_unit_price']) {
                $this->warnings[] = "Item {$idx}: Unit price unusually high ({$item['unit_price']})";
            }
            if ($item['unit_price'] < 0) {
                $this->errors[] = "Item {$idx}: Negative unit_price ({$item['unit_price']})";
            }

            // 檢查總額
            if ($item['total'] > $this->config['max_total']) {
                $this->warnings[] = "Item {$idx}: Total unusually high ({$item['total']})";
            }
            if ($item['total'] < 0) {
                $this->errors[] = "Item {$idx}: Negative total ({$item['total']})";
            }

            // 驗證計算
            $expectedTotal = $item['qty'] * $item['unit_price'];
            if (abs($expectedTotal - $item['total']) > 0.01 && $expectedTotal > 0) {
                $diff = abs(($expectedTotal - $item['total']) / $expectedTotal * 100);
                if ($diff > $this->config['tolerance_percent']) {
                    $this->warnings[] = "Item {$idx}: qty * unit_price ({$expectedTotal}) != total ({$item['total']})";
                }
            }
        }
    }

    /**
     * 驗證總額
     */
    protected function validateTotals(array $invoice): void {
        $declared = $invoice['declared_total'];
        $calc = $invoice['calc_total'];

        if ($declared === null) {
            $this->warnings[] = "No declared total found";
            return;
        }

        if ($calc <= 0) {
            $this->errors[] = "Calculated total is zero or negative";
            return;
        }

        $diff = abs($declared - $calc);
        $percent = $diff / max($declared, $calc) * 100;

        if ($percent > $this->config['tolerance_percent']) {
            $this->warnings[] = "Total mismatch: declared={$declared}, calculated={$calc} (diff={$percent}%)";
        }
    }

    /**
     * 獲取最後的錯誤
     */
    public function getErrors(): array {
        return $this->errors;
    }

    /**
     * 獲取最後的警告
     */
    public function getWarnings(): array {
        return $this->warnings;
    }

    /**
     * 獲取最後的修正
     */
    public function getFixes(): array {
        return $this->fixes;
    }
}
