<?php
declare(strict_types=1);

namespace Parsers;

/**
 * GenericMarkdownParser - 通用 Markdown 發票解析器
 * 
 * 增強版：
 * - 支持 HTML 表格和 Markdown 表格
 * - 智能字段映射
 * - 合併文件分割
 */
class GenericMarkdownParser extends AbstractParser {

    use Traits\TextBlockParsing;
    
    public function getId(): string {
        return 'generic_markdown';
    }

    public function getName(): string {
        return 'Generic Markdown Invoice';
    }

    public function getSupportedExtensions(): array {
        return ['md', 'txt'];
    }

    /**
     * 評估是否可以解析
     */
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
            $fileScore = 0.0;
            
            // 檢查是否有表格
            if (preg_match('/<table/i', $content)) {
                $fileScore += 0.4;
            }
            if (preg_match('/\|.+\|[\r\n]+\|[\-:]+\|/', $content)) {
                $fileScore += 0.3;
            }
            
            // 檢查發票關鍵詞
            $keywords = [
                'high' => ['total', 'amount', 'qty', 'quantity', 'price', '金额', '數量', '单价', '合计'],
                'medium' => ['invoice', '發票', '销售单', '收據', 'item', 'product'],
            ];
            
            foreach ($keywords['high'] as $kw) {
                if (stripos($content, $kw) !== false) {
                    $fileScore += 0.08;
                }
            }
            foreach ($keywords['medium'] as $kw) {
                if (stripos($content, $kw) !== false) {
                    $fileScore += 0.04;
                }
            }
            
            $score += min(0.8, $fileScore);
        }

        return $checked > 0 ? $score / $checked : 0.0;
    }

    /**
     * 解析文件
     */
    public function parse(array $files): array {
        $mdFiles = $this->filterByExtensions($files, ['md', 'txt']);
        $invoices = [];

        foreach ($mdFiles as $file) {
            $content = $this->readTextFile($file);
            if (!$content) continue;

            // 檢查是否是合併文件
            if ($this->isMergedFile($file['name'], $content)) {
                $subInvoices = $this->parseMergedFile($content, $file['name']);
                foreach ($subInvoices as $inv) {
                    if (!empty($inv['items'])) {
                        $invoices[] = $this->normalizeInvoice($inv);
                    }
                }
                continue;
            }

            // 單一發票
            $invoice = $this->extractFromMarkdown($content, $file['name']);
            if (!empty($invoice['items'])) {
                $invoices[] = $this->normalizeInvoice($invoice);
            }
        }

        return $invoices;
    }

    /**
     * 檢查是否是合併文件
     */
    protected function isMergedFile(string $name, string $content): bool {
        // 檢查文件名
        if (preg_match('/merge|combined|all/i', $name)) {
            return true;
        }
        
        // 檢查是否有多個發票標題
        $headerCount = preg_match_all('/^#{1,3}\s+.*(invoice|發票|销售单|收據)/imu', $content);
        return $headerCount > 1;
    }

    /**
     * 解析合併文件
     */
    protected function parseMergedFile(string $content, string $sourceFile): array {
        // 按標題分割
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

    /**
     * 從 Markdown 內容提取發票數據
     */
    protected function extractFromMarkdown(string $content, string $sourceFile): array {
        // 提取頭部信息
        $header = $this->extractInvoiceHeader($content);
        
        // 提取項目
        $items = [];
        
        // 方法 1：HTML 表格
        $htmlTables = $this->extractHtmlTables($content);
        if (!empty($htmlTables)) {
            $bestTable = $this->selectBestInvoiceTable($htmlTables);
            if ($bestTable) {
                $items = $this->extractItemsFromTableData($bestTable);
            }
        }
        
        // 方法 2：Markdown 表格
        if (empty($items)) {
            $mdTables = $this->extractMarkdownTables($content);
            if (!empty($mdTables)) {
                $bestTable = $this->selectBestInvoiceTable($mdTables);
                if ($bestTable) {
                    $items = $this->extractItemsFromTableData($bestTable);
                }
            }
        }
        
        // 方法 3：從文字提取（如果沒有表格）
        if (empty($items)) {
            $items = $this->extractItemsFromText($content);
        }

        // 計算總額
        $calcTotal = array_sum(array_column($items, 'total'));

        return [
            'source_file' => $sourceFile,
            'supplier_name' => $header['supplier_name'] ?: $this->extractTitle($content),
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
     * 提取標題（通常是供應商名）
     */
    protected function extractTitle(string $content): string {
        // 嘗試 H1 或 H2
        if (preg_match('/^#{1,2}\s+(.+)/m', $content, $m)) {
            return trim($m[1]);
        }
        
        // 嘗試第一個非空行
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && !preg_match('/^[#\-\*\|]/', $line)) {
                return $line;
            }
        }
        
        return '';
    }
}
