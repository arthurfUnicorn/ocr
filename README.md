# Invoice Importer - Flexible Multi-Format Parser

## 目錄結構

```
invoice-importer/
├── config.php          # 配置文件
├── public/
│   ├── index.php       # 上傳頁面 (支持文件夾上傳)
│   ├── preview.php     # 預覽和編輯頁面
│   └── .htaccess
├── src/
│   ├── Db.php
│   ├── Util.php
│   ├── CsvWriter.php
│   ├── FileScanner.php      # 文件掃描器 (JSON/MD)
│   ├── ParserRegistry.php   # 解析器註冊表
│   ├── RunStore.php
│   ├── PurchaseImporter.php
│   ├── SaleImporter.php
│   └── Parsers/
│       ├── ParserInterface.php       # 解析器接口
│       ├── AbstractParser.php        # 抽象基類
│       ├── DocParserJsonParser.php   # PaddleOCR doc_parser 格式
│       └── GenericMarkdownParser.php # 通用 Markdown 格式
└── storage/
    ├── uploads/
    ├── exports/
    └── logs/
```

## 新功能

### 1. 文件夾上傳支持
- 可以直接上傳文件夾
- 自動識別 JSON 和 MD 文件
- 忽略其他文件類型

### 2. 靈活的解析器架構
系統使用註冊表模式，可以輕鬆添加新的發票格式支持。

### 3. 自動格式檢測
系統會自動檢測最適合的解析器，選擇置信度最高的。

## 添加新的解析器

### 步驟 1: 創建解析器類

在 `src/Parsers/` 目錄下創建新文件，例如 `MyNewParser.php`：

```php
<?php
namespace Parsers;

class MyNewParser extends AbstractParser {
    public function getId(): string {
        return 'my_new_parser';
    }

    public function getName(): string {
        return 'My New Invoice Format';
    }

    public function getSupportedExtensions(): array {
        return ['json', 'xml'];
    }

    public function canParse(array $files): float {
        // 返回 0.0 - 1.0 的置信度
        $score = 0.0;
        foreach ($files as $file) {
            $json = $this->readJsonFile($file);
            if ($json && isset($json['my_specific_field'])) {
                $score += 0.5;
            }
        }
        return min(1.0, $score);
    }

    public function parse(array $files): array {
        $invoices = [];
        foreach ($files as $file) {
            $data = $this->readJsonFile($file);
            $invoice = [
                'source_file' => $file['name'],
                'supplier_name' => $data['supplier'] ?? '',
                'items' => [],
                // ... 其他字段
            ];
            $invoices[] = $this->normalizeInvoice($invoice);
        }
        return $invoices;
    }
}
```

### 步驟 2: 註冊解析器

在 `src/ParserRegistry.php` 的構造函數中添加：

```php
$this->register(new Parsers\MyNewParser());
```

### 步驟 3: 引入文件

在 `public/index.php` 頂部添加：

```php
require_once __DIR__ . '/../src/Parsers/MyNewParser.php';
```

## 標準化發票結構

所有解析器都應該返回以下標準結構：

```php
[
    'source_file' => 'invoice.json',
    'supplier_name' => '供應商名稱',
    'customer_name' => '客戶名稱',
    'invoice_date' => '2025-01-10',
    'invoice_number' => '45009',
    'declared_total' => 3135.00,
    'calc_total' => 3135.00,
    'items' => [
        [
            'code' => 'os838',
            'name' => '頭層牛皮女包',
            'qty' => 4,
            'unit_price' => 145.00,
            'total' => 580.00,
        ],
    ],
]
```

## 現有解析器

### 1. DocParserJsonParser (doc_parser_json)
- 支持 PaddleOCR doc_parser 輸出的 JSON 格式
- 檢測 `parsing_res_list` 數組
- 自動提取表格數據

### 2. GenericMarkdownParser (generic_markdown)
- 支持通用 Markdown 文件
- 解析 HTML 表格和 Markdown 表格
- 支持合併文件的分割解析
