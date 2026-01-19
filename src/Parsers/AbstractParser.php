<?php
declare(strict_types=1);

namespace Parsers;

/**
 * AbstractParser - 解析器抽象基類
 * 
 * 提供所有解析器共用的基礎功能
 * 增強版：包含智能字段映射和表格提取能力
 */
abstract class AbstractParser implements ParserInterface {

    use Traits\SmartFieldMapping;
    use Traits\TableExtraction;
    
    /**
     * 按文件基本名稱分組
     * 用於處理同一發票的多個文件（如 JSON + MD）
     */
    protected function groupFilesByBaseName(array $files): array {
        $groups = [];
        
        foreach ($files as $file) {
            $name = $file['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $base = pathinfo($name, PATHINFO_FILENAME);
            
            // 移除常見後綴
            $base = preg_replace('/_(res|output|result|parsed|p\d+)$/i', '', $base);
            
            if (!isset($groups[$base])) {
                $groups[$base] = [];
            }
            $groups[$base][$ext] = $file;
        }
        
        return $groups;
    }

    /**
     * 按擴展名過濾文件
     */
    protected function filterByExtensions(array $files, array $extensions): array {
        return array_filter($files, function($file) use ($extensions) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            return in_array($ext, $extensions, true);
        });
    }

    /**
     * 讀取 JSON 文件
     */
    protected function readJsonFile(array $file): ?array {
        if (isset($file['content']) && is_array($file['content'])) {
            return $file['content'];
        }
        
        if (isset($file['path']) && file_exists($file['path'])) {
            $raw = file_get_contents($file['path']);
            if ($raw === false) return null;
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        }
        
        return null;
    }

    /**
     * 讀取文本文件
     */
    protected function readTextFile(array $file): ?string {
        if (isset($file['content']) && is_string($file['content'])) {
            return $file['content'];
        }
        
        if (isset($file['path']) && file_exists($file['path'])) {
            $raw = file_get_contents($file['path']);
            return $raw !== false ? $raw : null;
        }
        
        return null;
    }

    /**
     * 標準化發票數據結構
     * 確保輸出格式與數據庫兼容
     */
    protected function normalizeInvoice(array $data): array {
        return [
            'source_file' => $data['source_file'] ?? 'unknown',
            'format_detected' => $this->getId(),
            'supplier_name' => $this->cleanString($data['supplier_name'] ?? ''),
            'customer_name' => $this->cleanString($data['customer_name'] ?? ''),
            'invoice_date' => $this->normalizeDate($data['invoice_date'] ?? null),
            'invoice_number' => $data['invoice_number'] ?? null,
            'declared_total' => $this->normalizeAmount($data['declared_total'] ?? null),
            'calc_total' => round((float)($data['calc_total'] ?? 0), 2),
            'currency' => $data['currency'] ?? null,
            'items' => array_map([$this, 'normalizeItem'], $data['items'] ?? []),
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    /**
     * 標準化單個項目
     */
    protected function normalizeItem(array $item): array {
        $qty = (float)($item['qty'] ?? 1);
        if ($qty <= 0) $qty = 1;

        $unitPrice = (float)($item['unit_price'] ?? 0);
        $total = (float)($item['total'] ?? 0);

        // 自動計算缺失值
        if ($total <= 0 && $qty > 0 && $unitPrice > 0) {
            $total = $qty * $unitPrice;
        }
        if ($unitPrice <= 0 && $qty > 0 && $total > 0) {
            $unitPrice = $total / $qty;
        }

        return [
            'code' => $this->cleanString($item['code'] ?? ''),
            'name' => $this->cleanString($item['name'] ?? ''),
            'description' => $this->cleanString($item['description'] ?? ''),
            'qty' => round($qty, 4),
            'unit' => $this->cleanString($item['unit'] ?? ''),
            'unit_price' => round($unitPrice, 4),
            'total' => round($total, 2),
            'metadata' => $item['metadata'] ?? [],
        ];
    }

    /**
     * 清理字符串
     */
    protected function cleanString(?string $str): string {
        if ($str === null) return '';
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    /**
     * 標準化日期
     */
    protected function normalizeDate($date): ?string {
        if (empty($date)) return null;
        
        if ($date instanceof \DateTime) {
            return $date->format('Y-m-d');
        }

        $date = (string)$date;

        // 已經是標準格式
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // 嘗試各種格式
        $formats = ['Y/m/d', 'd-m-Y', 'd/m/Y', 'm-d-Y', 'm/d/Y'];
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
            return null;
        }
    }

    /**
     * 標準化金額
     */
    protected function normalizeAmount($amount): ?float {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (is_numeric($amount)) {
            return round((float)$amount, 2);
        }

        // 清理字符串
        $str = (string)$amount;
        $str = preg_replace('/[^0-9.\-]/', '', $str);

        return is_numeric($str) ? round((float)$str, 2) : null;
    }

    /**
     * 傳統的列映射方法（保持向後兼容）
     */
    protected function mapColumns(array $header): array {
        // 使用新的智能映射
        return $this->mapHeaderRow($header);
    }

    /**
     * 從儲存格獲取值
     */
    protected function cell(array $row, ?int $idx): string {
        if ($idx === null || !isset($row[$idx])) {
            return '';
        }
        return trim((string)$row[$idx]);
    }
}
