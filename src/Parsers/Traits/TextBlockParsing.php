<?php
declare(strict_types=1);

namespace Parsers\Traits;

/**
 * TextBlockParsing - 從純文字區塊中提取發票數據
 * 
 * 適用於沒有表格結構的發票，如手寫發票或簡單格式發票
 * 使用多種模式匹配來識別項目
 */
trait TextBlockParsing {

    /**
     * 從文字內容中提取發票頭部信息
     * 
     * @param string $text 文字內容
     * @return array 發票頭部信息
     */
    protected function extractInvoiceHeader(string $text): array {
        return [
            'supplier_name' => $this->extractSupplierName($text),
            'customer_name' => $this->extractCustomerName($text),
            'invoice_date' => $this->extractInvoiceDate($text),
            'invoice_number' => $this->extractInvoiceNumber($text),
            'total' => $this->extractDeclaredTotal($text),
            'currency' => $this->detectCurrency($text),
        ];
    }

    /**
     * 提取供應商名稱
     */
    protected function extractSupplierName(string $text): string {
        $patterns = [
            '/供[应應]商[：:]\s*([^\n\r]+)/u',
            '/供[货貨]商[：:]\s*([^\n\r]+)/u',
            '/vendor[:\s]+([^\n\r]+)/i',
            '/supplier[:\s]+([^\n\r]+)/i',
            '/from[:\s]+([^\n\r]+)/i',
            '/公司[：:]\s*([^\n\r]+)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->cleanEntityName($m[1]);
            }
        }

        // 嘗試從開頭提取
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            // 跳過日期行和數字行
            if (preg_match('/^\d{4}[-\/]/', $line)) continue;
            if (preg_match('/^[#\*\-]/', $line)) continue;
            if (strlen($line) > 5 && strlen($line) < 100) {
                // 可能是公司名
                if (preg_match('/(有限公司|co\.?\s*ltd|company|trading|enterprise|inc\.?|corp\.?)/iu', $line)) {
                    return $this->cleanEntityName($line);
                }
            }
        }

        return '';
    }

    /**
     * 提取客戶名稱
     */
    protected function extractCustomerName(string $text): string {
        $patterns = [
            '/客[户戶][：:]\s*([^\n\r]+)/u',
            '/買[家方][：:]\s*([^\n\r]+)/u',
            '/customer[:\s]+([^\n\r]+)/i',
            '/bill\s*to[:\s]+([^\n\r]+)/i',
            '/sold\s*to[:\s]+([^\n\r]+)/i',
            '/to[:\s]+([^\n\r]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->cleanEntityName($m[1]);
            }
        }

        return '';
    }

    /**
     * 提取發票日期
     */
    protected function extractInvoiceDate(string $text): ?string {
        $patterns = [
            // YYYY-MM-DD 格式
            '/日期[：:]\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/u',
            '/date[:\s]+(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i',
            '/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/',
            
            // DD/MM/YYYY 或 MM/DD/YYYY 格式
            '/日期[：:]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/u',
            '/date[:\s]+(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/i',
            
            // 中文日期
            '/(\d{4})年(\d{1,2})月(\d{1,2})日/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                if (isset($m[3])) {
                    // 中文日期格式
                    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                }
                return $this->normalizeDateString($m[1]);
            }
        }

        return null;
    }

    /**
     * 標準化日期字符串格式為 YYYY-MM-DD
     * 注意：此方法名避免與 AbstractParser::normalizeDate 衝突
     */
    protected function normalizeDateString(string $dateStr): ?string {
        $dateStr = str_replace('/', '-', $dateStr);
        $parts = explode('-', $dateStr);
        
        if (count($parts) !== 3) return null;

        // 判斷格式
        if (strlen($parts[0]) === 4) {
            // YYYY-MM-DD
            return sprintf('%04d-%02d-%02d', (int)$parts[0], (int)$parts[1], (int)$parts[2]);
        } else if (strlen($parts[2]) === 4) {
            // DD-MM-YYYY 或 MM-DD-YYYY
            $day = (int)$parts[0];
            $month = (int)$parts[1];
            $year = (int)$parts[2];
            
            // 如果第一個數字大於12，應該是 DD-MM-YYYY
            if ($day > 12) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            // 否則假設是 MM-DD-YYYY (美式)
            return sprintf('%04d-%02d-%02d', $year, $day, $month);
        }

        return null;
    }

    /**
     * 提取發票號碼
     */
    protected function extractInvoiceNumber(string $text): ?string {
        $patterns = [
            '/發票[号號][：:]\s*([A-Za-z0-9\-]+)/u',
            '/invoice\s*(?:#|no\.?|number)[:\s]*([A-Za-z0-9\-]+)/i',
            '/單[号號][：:]\s*([A-Za-z0-9\-]+)/u',
            '/批次[：:]\s*(\d+)/u',
            '/ref(?:erence)?[:\s]*([A-Za-z0-9\-]+)/i',
            '/order\s*(?:#|no\.?)[:\s]*([A-Za-z0-9\-]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * 提取聲明的總額
     */
    protected function extractDeclaredTotal(string $text): ?float {
        $patterns = [
            '/grand\s*total[:\s]*[\$¥￥€£]?\s*([\d,]+\.?\d*)/i',
            '/total\s*(?:amount|due)?[:\s]*[\$¥￥€£]?\s*([\d,]+\.?\d*)/i',
            '/合[计計][：:]\s*[\$¥￥€£]?\s*([\d,]+\.?\d*)/u',
            '/總[数數額额][：:]\s*[\$¥￥€£]?\s*([\d,]+\.?\d*)/u',
            '/本單額[：:]\s*[\$¥￥€£]?\s*([\d,]+\.?\d*)/u',
            '/amount\s*(?:payable|due)[:\s]*[\$¥￥€£]?\s*([\d,]+\.?\d*)/i',
        ];

        // 先找最後出現的總額（通常是最終總額）
        $lastMatch = null;
        $lastPos = -1;

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    if ($match[1] > $lastPos) {
                        $lastPos = $match[1];
                        $lastMatch = $match[0];
                    }
                }
            }
        }

        if ($lastMatch !== null) {
            $value = str_replace(',', '', $lastMatch);
            return is_numeric($value) ? (float)$value : null;
        }

        return null;
    }

    /**
     * 檢測貨幣
     */
    protected function detectCurrency(string $text): ?string {
        $currencyMap = [
            'CNY' => ['/¥|￥|rmb|人民币|人民幣/iu'],
            'HKD' => ['/hk\$|hkd|港币|港幣/iu'],
            'USD' => ['/\$(?!hk)|usd|us\$|美元|美金/iu'],
            'EUR' => ['/€|eur|欧元|歐元/iu'],
            'GBP' => ['/£|gbp|英镑|英鎊/iu'],
        ];

        foreach ($currencyMap as $currency => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return $currency;
                }
            }
        }

        return null;
    }

    /**
     * 從純文字中提取項目（無表格結構）
     * 
     * @param string $text 文字內容
     * @return array 項目數組
     */
    protected function extractItemsFromText(string $text): array {
        $items = [];

        // 方法1：匹配 "產品名 x數量 @單價" 格式
        $items = array_merge($items, $this->parseMultiplicationFormat($text));

        // 方法2：匹配 "產品名 數量 單價 總額" 行格式
        $items = array_merge($items, $this->parseLineFormat($text));

        // 方法3：匹配列表格式
        $items = array_merge($items, $this->parseListFormat($text));

        // 去重
        $items = $this->deduplicateItems($items);

        return $items;
    }

    /**
     * 解析乘法格式：產品名 x數量 @單價
     */
    protected function parseMultiplicationFormat(string $text): array {
        $items = [];
        
        $patterns = [
            // 產品名 x2 @100 = 200
            '/([^\d\n]+?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*[@＠]\s*(\d+(?:\.\d+)?)/iu',
            // 產品名 2pcs @ $100
            '/([^\d\n]+?)\s*(\d+(?:\.\d+)?)\s*(?:pcs?|件)?\s*[@＠]\s*[\$¥￥]?\s*(\d+(?:\.\d+)?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $name = $this->cleanItemName($m[1]);
                    if (mb_strlen($name) < 2) continue;
                    
                    $qty = (float)$m[2];
                    $unitPrice = (float)$m[3];
                    
                    if ($qty > 0 && $unitPrice > 0) {
                        $items[] = [
                            'code' => '',
                            'name' => $name,
                            'qty' => $qty,
                            'unit_price' => $unitPrice,
                            'total' => round($qty * $unitPrice, 2),
                            'metadata' => ['parse_method' => 'multiplication'],
                        ];
                    }
                }
            }
        }

        return $items;
    }

    /**
     * 解析行格式：產品名 數量 單價 總額
     */
    protected function parseLineFormat(string $text): array {
        $items = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 跳過標題行和總計行
            if (preg_match('/^(合计|total|subtotal|grand|小計|#|序号|項次)/iu', $line)) continue;

            // 提取所有數字
            if (preg_match_all('/([\d,]+\.?\d*)/', $line, $numMatches)) {
                $numbers = array_filter(
                    array_map(function($n) {
                        return (float)str_replace(',', '', $n);
                    }, $numMatches[1]),
                    function($n) { return $n > 0; }
                );
                $numbers = array_values($numbers);

                // 至少需要1個數字
                if (count($numbers) >= 1) {
                    // 移除數字後得到產品名
                    $name = preg_replace('/[\d,]+\.?\d*/', '', $line);
                    $name = $this->cleanItemName($name);

                    if (mb_strlen($name) >= 2) {
                        // 根據數字數量推斷
                        $qty = 1;
                        $unitPrice = 0;
                        $total = 0;

                        if (count($numbers) >= 3) {
                            // 假設順序是 qty, unit_price, total
                            $qty = $numbers[0];
                            $unitPrice = $numbers[1];
                            $total = $numbers[2];
                        } else if (count($numbers) == 2) {
                            // 可能是 qty, total 或 unit_price, total
                            if ($numbers[0] <= 100 && $numbers[1] > $numbers[0]) {
                                $qty = $numbers[0];
                                $total = $numbers[1];
                                $unitPrice = $total / $qty;
                            } else {
                                $unitPrice = $numbers[0];
                                $total = $numbers[1];
                                $qty = $total / $unitPrice;
                            }
                        } else {
                            // 只有一個數字，假設是金額
                            $total = $numbers[0];
                        }

                        // 驗證數據合理性
                        if ($total > 0 && abs($qty * $unitPrice - $total) / $total < 0.1) {
                            $items[] = [
                                'code' => '',
                                'name' => $name,
                                'qty' => round($qty, 4),
                                'unit_price' => round($unitPrice, 4),
                                'total' => round($total, 2),
                                'metadata' => ['parse_method' => 'line'],
                            ];
                        }
                    }
                }
            }
        }

        return $items;
    }

    /**
     * 解析列表格式
     */
    protected function parseListFormat(string $text): array {
        $items = [];
        
        // 匹配帶項目符號的行
        $pattern = '/^[\*\-\•\d\.]+\s*(.+?)[\s\-]+[\$¥￥]?\s*([\d,]+\.?\d*)$/mu';
        
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = $this->cleanItemName($m[1]);
                $total = (float)str_replace(',', '', $m[2]);
                
                if (mb_strlen($name) >= 2 && $total > 0) {
                    $items[] = [
                        'code' => '',
                        'name' => $name,
                        'qty' => 1,
                        'unit_price' => $total,
                        'total' => $total,
                        'metadata' => ['parse_method' => 'list'],
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * 清理項目名稱
     */
    protected function cleanItemName(string $name): string {
        // 移除常見的前後綴
        $name = preg_replace('/^[\d\.\)\]\-\*\•\s]+/', '', $name);
        $name = preg_replace('/[\s\-\*]+$/', '', $name);
        
        // 移除多餘空白
        $name = preg_replace('/\s+/', ' ', $name);
        
        return trim($name);
    }

    /**
     * 清理實體名稱（供應商/客戶）
     */
    protected function cleanEntityName(string $name): string {
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);
        
        // 移除常見的前後綴
        $name = preg_replace('/^(供[应應]商|vendor|supplier|from)[:\s]*/iu', '', $name);
        
        return $name;
    }

    /**
     * 去除重複項目
     */
    protected function deduplicateItems(array $items): array {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = mb_strtolower($item['name']) . '|' . $item['qty'] . '|' . $item['total'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return $result;
    }
}
