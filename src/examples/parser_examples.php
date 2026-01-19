<?php
/**
 * 解析器系統使用示例
 * 
 * 此文件展示了如何使用增強版解析器系統
 */

require_once __DIR__ . '/parser_bootstrap.php';

// ============================================
// 示例 1: 基本使用 - 自動檢測格式並解析
// ============================================

function example_basic_usage() {
    echo "=== 示例 1: 基本使用 ===\n";
    
    // 創建解析器註冊表
    $registry = new ParserRegistry();
    
    // 準備文件數組（通常來自上傳）
    $files = [
        [
            'name' => 'invoice_001.json',
            'path' => '/path/to/invoice_001.json',
            // 'content' => [...] // 可選：已讀取的內容
        ],
    ];
    
    try {
        // 自動檢測格式並解析
        $result = $registry->parse($files);
        
        echo "使用的解析器: {$result['parser_name']}\n";
        echo "置信度: {$result['confidence']}\n";
        echo "發票數量: " . count($result['invoices']) . "\n";
        
        foreach ($result['invoices'] as $idx => $invoice) {
            echo "\n發票 #{$idx}:\n";
            echo "  供應商: {$invoice['supplier_name']}\n";
            echo "  日期: {$invoice['invoice_date']}\n";
            echo "  項目數: " . count($invoice['items']) . "\n";
            echo "  總額: {$invoice['calc_total']}\n";
        }
        
    } catch (Exception $e) {
        echo "錯誤: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 示例 2: 使用數據驗證
// ============================================

function example_with_validation() {
    echo "\n=== 示例 2: 使用數據驗證 ===\n";
    
    $registry = new ParserRegistry();
    
    // 設置驗證器
    $validator = new Validators\InvoiceDataValidator();
    $validator->setConfig([
        'tolerance_percent' => 3, // 3% 誤差容許
    ]);
    $registry->setValidator($validator);
    $registry->setAutoValidate(true);
    
    // 模擬一個有問題的發票
    $invoiceData = [
        'source_file' => 'test.json',
        'supplier_name' => '測試供應商',
        'items' => [
            [
                'name' => '產品A',
                'qty' => 2,
                'unit_price' => 100,
                'total' => 200,
            ],
            [
                'name' => '產品B',
                'qty' => 0, // 缺少數量
                'unit_price' => 50,
                'total' => 150,
            ],
        ],
    ];
    
    // 直接使用驗證器
    $result = $validator->validateAndFix($invoiceData);
    
    echo "驗證通過: " . ($result['valid'] ? '是' : '否') . "\n";
    echo "修正數量: " . count($result['fixes']) . "\n";
    echo "警告數量: " . count($result['warnings']) . "\n";
    
    if (!empty($result['fixes'])) {
        echo "\n修正內容:\n";
        foreach ($result['fixes'] as $fix) {
            echo "  - {$fix}\n";
        }
    }
}

// ============================================
// 示例 3: 配置 LLM 解析器
// ============================================

function example_llm_parser() {
    echo "\n=== 示例 3: LLM 解析器配置 ===\n";
    
    $registry = new ParserRegistry();
    
    // 配置 LLM 解析器（需要 API 密鑰）
    $registry->configureLlmParser([
        'provider' => 'anthropic',
        'api_key' => getenv('ANTHROPIC_API_KEY') ?: 'your-api-key-here',
        'model' => 'claude-sonnet-4-20250514',
    ]);
    
    // 檢查是否已啟用
    $llmParser = $registry->getParser('llm_assisted');
    if ($llmParser instanceof Parsers\LlmAssistedParser) {
        echo "LLM 解析器狀態: " . ($llmParser->isEnabled() ? '已啟用' : '未啟用') . "\n";
    }
    
    // 獲取所有解析器信息
    echo "\n可用解析器:\n";
    foreach ($registry->getParsersInfo() as $info) {
        $status = $info['enabled'] ? '✓' : '✗';
        echo "  [{$status}] {$info['name']} ({$info['id']})\n";
        echo "      支持格式: " . implode(', ', $info['extensions']) . "\n";
    }
}

// ============================================
// 示例 4: 創建自定義解析器
// ============================================

function example_custom_parser() {
    echo "\n=== 示例 4: 自定義解析器 ===\n";
    
    // 定義自定義解析器
    $customParser = new class extends Parsers\AbstractParser {
        public function getId(): string {
            return 'my_custom_format';
        }
        
        public function getName(): string {
            return 'My Custom Invoice Format';
        }
        
        public function getSupportedExtensions(): array {
            return ['json', 'xml'];
        }
        
        public function canParse(array $files): float {
            // 檢查是否有特定的標記
            foreach ($files as $file) {
                $json = $this->readJsonFile($file);
                if ($json && isset($json['my_custom_field'])) {
                    return 0.9;
                }
            }
            return 0.0;
        }
        
        public function parse(array $files): array {
            $invoices = [];
            
            foreach ($files as $file) {
                $data = $this->readJsonFile($file);
                if (!$data) continue;
                
                // 自定義解析邏輯
                $invoice = [
                    'source_file' => $file['name'],
                    'supplier_name' => $data['vendor'] ?? '',
                    'items' => $this->parseCustomItems($data),
                ];
                
                $invoices[] = $this->normalizeInvoice($invoice);
            }
            
            return $invoices;
        }
        
        private function parseCustomItems(array $data): array {
            // 自定義項目解析
            return [];
        }
    };
    
    // 註冊自定義解析器
    $registry = new ParserRegistry();
    $registry->register($customParser);
    
    echo "已註冊自定義解析器: {$customParser->getName()}\n";
}

// ============================================
// 示例 5: 批量處理與容錯
// ============================================

function example_batch_processing() {
    echo "\n=== 示例 5: 批量處理 ===\n";
    
    $registry = new ParserRegistry();
    
    // 模擬多個文件
    $files = [
        ['name' => 'invoice1.json', 'path' => '/uploads/invoice1.json'],
        ['name' => 'invoice2.md', 'path' => '/uploads/invoice2.md'],
        ['name' => 'invoice3.txt', 'path' => '/uploads/invoice3.txt'],
    ];
    
    // 使用帶容錯的解析
    try {
        $result = $registry->parseWithFallback($files);
        
        echo "成功解析 {count($result['invoices'])} 個發票\n";
        
        // 檢查是否有回退錯誤
        if (!empty($result['fallback_errors'])) {
            echo "\n部分解析器失敗:\n";
            foreach ($result['fallback_errors'] as $parserId => $error) {
                echo "  {$parserId}: {$error}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "所有解析器都失敗: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 示例 6: 直接使用 Traits
// ============================================

function example_using_traits() {
    echo "\n=== 示例 6: 直接使用 Traits ===\n";
    
    // 創建一個使用 Traits 的匿名類
    $extractor = new class {
        use Parsers\Traits\SmartFieldMapping;
        use Parsers\Traits\TableExtraction;
        use Parsers\Traits\TextBlockParsing;
        
        public function testSmartMapping() {
            $headers = ['產品名稱', '數量', '單價', '金額'];
            $map = $this->mapHeaderRow($headers);
            
            echo "表頭映射結果:\n";
            foreach ($map as $field => $idx) {
                echo "  {$field} => 列 {$idx} ({$headers[$idx]})\n";
            }
        }
        
        public function testTextExtraction() {
            $text = "
供應商: 測試公司
日期: 2025-01-15

產品A x2 @100
產品B x3 @50

合計: 350
";
            $header = $this->extractInvoiceHeader($text);
            $items = $this->extractItemsFromText($text);
            
            echo "\n提取的頭部信息:\n";
            print_r($header);
            
            echo "\n提取的項目:\n";
            print_r($items);
        }
    };
    
    $extractor->testSmartMapping();
    $extractor->testTextExtraction();
}

// ============================================
// 運行示例
// ============================================

// 取消註釋以運行示例
// example_basic_usage();
// example_with_validation();
// example_llm_parser();
// example_custom_parser();
// example_batch_processing();
// example_using_traits();

echo "解析器系統示例文件已準備就緒。\n";
echo "取消註釋相應的函數調用以運行示例。\n";
