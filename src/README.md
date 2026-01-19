# 增強型發票解析系統 v2.0

## 概述

此系統提供了四種增強功能，可以處理各種不同格式的發票 JSON/MD 文件，確保從 PaddleOCR 提取的數據能正確存入數據庫。

## 目錄結構

```
src/
├── Parsers/
│   ├── Traits/
│   │   ├── SmartFieldMapping.php    # 智能字段映射
│   │   ├── TableExtraction.php      # 表格提取增強
│   │   └── TextBlockParsing.php     # 文字區塊解析
│   ├── ParserInterface.php          # 解析器接口
│   ├── AbstractParser.php           # 抽象基類
│   ├── DocParserJsonParser.php      # PaddleOCR JSON 解析器
│   ├── GenericMarkdownParser.php    # 通用 MD 解析器
│   ├── TextBlockParser.php          # 無表格發票解析器
│   └── LlmAssistedParser.php        # LLM 輔助解析器
├── Validators/
│   └── InvoiceDataValidator.php     # 數據驗證器
├── ParserRegistry.php               # 解析器註冊表
├── parser_bootstrap.php             # 自動加載
└── examples/
    └── parser_examples.php          # 使用示例
```

## 四大核心功能

### 1. 智能字段映射 (SmartFieldMapping)

自動識別各種格式的表頭，支持：
- 中文/英文/繁體/簡體
- 模糊匹配
- 多種表頭命名方式

**支持的字段：**
| 字段 | 匹配示例 |
|------|----------|
| code | 款号、貨號、SKU、Art No. |
| name | 品名、產品、Description |
| qty | 數量、Qty、Quantity、pcs |
| unit_price | 單價、Unit Price、Rate |
| total | 金額、Total、Amount |

### 2. 文字區塊解析 (TextBlockParser)

處理沒有表格結構的發票：
- 手寫發票
- 簡單格式發票
- OCR 無法識別表格的發票

**支持的格式：**
```
產品A x2 @100
產品B 3pcs @ $50
```

### 3. 數據驗證層 (InvoiceDataValidator)

確保數據質量：
- 修正 OCR 錯誤（O→0, l→1）
- 計算缺失值（qty × unit_price = total）
- 驗證數據範圍
- 標準化格式

### 4. LLM 輔助解析 (LlmAssistedParser)

使用 AI 處理複雜發票：
- 支持 Anthropic Claude API
- 支持 OpenAI API
- 自動結構化提取

## 標準輸出格式

所有解析器輸出統一格式，與數據庫兼容：

```php
[
    'source_file' => 'invoice_001.json',
    'supplier_name' => '供應商名稱',      // → suppliers 表
    'customer_name' => '客戶名稱',        // → customers 表
    'invoice_date' => '2025-01-16',       // → purchases/sales 表
    'invoice_number' => 'INV-001',
    'declared_total' => 1000.00,
    'calc_total' => 1000.00,
    'currency' => 'HKD',
    'items' => [
        [
            'code' => 'SKU001',           // → products 表
            'name' => '產品名稱',
            'qty' => 10,                  // → product_purchases/sales 表
            'unit_price' => 100.00,
            'total' => 1000.00,
            'metadata' => [],
        ],
    ],
]
```

## 快速開始

### 1. 基本使用

```php
require_once 'src/parser_bootstrap.php';

// 創建解析器
$registry = new ParserRegistry();

// 準備文件
$files = [
    ['name' => 'invoice.json', 'path' => '/path/to/file.json'],
];

// 自動檢測並解析
$result = $registry->parse($files);

// 結果
print_r($result['invoices']);
```

### 2. 使用驗證器

```php
$registry = new ParserRegistry();

// 啟用驗證
$validator = new Validators\InvoiceDataValidator();
$registry->setValidator($validator);
$registry->setAutoValidate(true);

$result = $registry->parse($files);

// 查看驗證結果
print_r($result['validation']);
```

### 3. 配置 LLM 解析

```php
$registry = new ParserRegistry();

// 配置 Claude API
$registry->configureLlmParser([
    'provider' => 'anthropic',
    'api_key' => 'your-api-key',
    'model' => 'claude-sonnet-4-20250514',
]);

// 或 OpenAI
$registry->configureLlmParser([
    'provider' => 'openai',
    'api_key' => 'your-openai-key',
    'model' => 'gpt-4o',
]);
```

## 集成到現有系統

### 步驟 1: 複製文件

將以下目錄複製到你的項目：
- `src/Parsers/` → 你的 `src/Parsers/`
- `src/Validators/` → 你的 `src/Validators/`
- `src/ParserRegistry.php` → 你的 `src/`

### 步驟 2: 更新 autoload

在 `public/index.php` 頂部添加：

```php
require_once __DIR__ . '/../src/parser_bootstrap.php';
```

或如果使用 Composer：

```json
{
    "autoload": {
        "psr-4": {
            "Parsers\\": "src/Parsers/",
            "Validators\\": "src/Validators/"
        }
    }
}
```

### 步驟 3: 使用新的 ParserRegistry

現有的 `ParserRegistry` 已經升級，無需修改調用代碼。

## 數據庫兼容性

輸出格式完全兼容現有的 `PurchaseImporter` 和 `SaleImporter`：

| 解析器輸出 | 數據庫表 | 字段映射 |
|-----------|---------|---------|
| supplier_name | suppliers | name |
| customer_name | customers | name |
| invoice_date | purchases/sales | created_at |
| items[].code | products | code |
| items[].name | products | name |
| items[].qty | product_purchases | qty |
| items[].unit_price | product_purchases | net_unit_cost |
| items[].total | product_purchases | total |

## 處理不同發票格式

### 格式 A: 標準表格發票
```json
{
  "parsing_res_list": [
    {"block_label": "table", "block_content": "<table>...</table>"}
  ]
}
```
→ 使用 `DocParserJsonParser`

### 格式 B: Markdown 表格
```markdown
| 品名 | 數量 | 單價 | 金額 |
|------|------|------|------|
| 產品A | 10 | 100 | 1000 |
```
→ 使用 `GenericMarkdownParser`

### 格式 C: 無表格純文字
```
產品A x10 @100
產品B 5pcs @ $50
合計: 1250
```
→ 使用 `TextBlockParser`

### 格式 D: 複雜/不規則格式
→ 使用 `LlmAssistedParser`（需要 API）

## 錯誤處理

```php
try {
    $result = $registry->parse($files);
} catch (RuntimeException $e) {
    // 沒有找到合適的解析器
    echo "解析失敗: " . $e->getMessage();
}

// 使用帶容錯的解析
$result = $registry->parseWithFallback($files);
```

## 配置選項

### 驗證器配置

```php
$validator->setConfig([
    'min_item_name_length' => 2,      // 最小名稱長度
    'max_item_name_length' => 200,    // 最大名稱長度
    'max_qty' => 100000,              // 最大數量
    'max_unit_price' => 10000000,     // 最大單價
    'tolerance_percent' => 5,         // 計算誤差容許 %
]);
```

### 解析器選擇

```php
// 強制使用特定解析器
$result = $registry->parse($files, 'doc_parser_json');

// 設置最低置信度
$registry->setMinConfidence(0.5);
```

## 常見問題

### Q: 如何添加自定義解析器？

```php
class MyParser extends Parsers\AbstractParser {
    public function getId(): string { return 'my_parser'; }
    public function getName(): string { return 'My Custom Parser'; }
    public function getSupportedExtensions(): array { return ['json']; }
    public function canParse(array $files): float { return 0.8; }
    public function parse(array $files): array { /* ... */ }
}

$registry->register(new MyParser());
```

### Q: 為什麼 LLM 解析器不工作？

確保已配置 API 密鑰：
```php
$registry->configureLlmParser(['api_key' => 'your-key']);
```

### Q: 如何處理 OCR 錯誤？

驗證器會自動修正常見錯誤。手動使用：
```php
$validator = new Validators\InvoiceDataValidator();
$result = $validator->validateAndFix($invoiceData);
print_r($result['fixes']); // 查看修正內容
```

## 版本歷史

- v2.0: 增強版，四大功能
- v1.0: 基礎版本

## 授權

MIT License
