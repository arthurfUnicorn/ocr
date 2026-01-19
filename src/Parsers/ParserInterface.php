<?php
declare(strict_types=1);

namespace Parsers;

/**
 * ParserInterface - 發票解析器接口
 * 
 * 所有解析器必須實現此接口
 */
interface ParserInterface {
    
    /**
     * 獲取解析器唯一標識符
     * 
     * @return string 解析器 ID
     */
    public function getId(): string;

    /**
     * 獲取人類可讀的解析器名稱
     * 
     * @return string 解析器名稱
     */
    public function getName(): string;

    /**
     * 獲取此解析器支持的文件擴展名
     * 
     * @return array 擴展名數組（不含點號）
     */
    public function getSupportedExtensions(): array;

    /**
     * 評估此解析器處理給定文件的能力
     * 
     * @param array $files 文件信息數組 [['path' => string, 'name' => string, 'content' => mixed], ...]
     * @return float 置信度分數 0.0-1.0
     *               0.0 = 無法解析
     *               0.5 = 可能可以解析
     *               1.0 = 絕對可以解析
     */
    public function canParse(array $files): float;

    /**
     * 解析文件並返回標準化的發票數據
     * 
     * @param array $files 文件信息數組
     * @return array 發票數組，每個發票包含：
     *               [
     *                   'source_file' => string,      // 來源文件名
     *                   'format_detected' => string,  // 檢測到的格式
     *                   'supplier_name' => string,    // 供應商名稱
     *                   'customer_name' => string,    // 客戶名稱
     *                   'invoice_date' => ?string,    // 日期 (YYYY-MM-DD)
     *                   'invoice_number' => ?string,  // 發票號碼
     *                   'declared_total' => ?float,   // 聲明總額
     *                   'calc_total' => float,        // 計算總額
     *                   'currency' => ?string,        // 貨幣
     *                   'items' => [                  // 項目數組
     *                       [
     *                           'code' => string,
     *                           'name' => string,
     *                           'description' => string,
     *                           'qty' => float,
     *                           'unit' => string,
     *                           'unit_price' => float,
     *                           'total' => float,
     *                           'metadata' => array,
     *                       ],
     *                       ...
     *                   ],
     *                   'metadata' => array,          // 其他元數據
     *               ]
     */
    public function parse(array $files): array;
}
