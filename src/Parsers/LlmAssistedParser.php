<?php
declare(strict_types=1);

namespace Parsers;

/**
 * LlmAssistedParser - 使用大語言模型輔助提取發票數據
 * 
 * 適用於：
 * - 複雜或不規則格式的發票
 * - 多語言混合發票
 * - 手寫發票
 * - 其他解析器無法處理的情況
 * 
 * 注意：需要配置 API 密鑰才能使用
 */
class LlmAssistedParser extends AbstractParser {

    /**
     * API 配置
     */
    protected array $apiConfig = [
        'provider' => 'anthropic', // 'anthropic' 或 'openai'
        'model' => 'claude-sonnet-4-20250514',
        'api_key' => null,
        'base_url' => 'https://api.anthropic.com/v1/messages',
        'max_tokens' => 4096,
        'temperature' => 0.1,
    ];

    /**
     * 是否啟用
     */
    protected bool $enabled = false;

    /**
     * 構造函數
     */
    public function __construct(?array $config = null) {
        if ($config !== null) {
            $this->configure($config);
        }
    }

    /**
     * 配置 API
     */
    public function configure(array $config): self {
        $this->apiConfig = array_merge($this->apiConfig, $config);
        $this->enabled = !empty($this->apiConfig['api_key']);
        return $this;
    }

    public function getId(): string {
        return 'llm_assisted';
    }

    public function getName(): string {
        return 'LLM-Assisted Parser (AI)';
    }

    public function getSupportedExtensions(): array {
        return ['json', 'md', 'txt'];
    }

    /**
     * 評估是否可以解析
     * 作為後備解析器，只有在啟用且其他解析器都失敗時才使用
     */
    public function canParse(array $files): float {
        if (!$this->enabled) {
            return 0.0;
        }

        // 作為後備解析器，返回較低的置信度
        $hasContent = false;
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['json', 'md', 'txt'])) {
                $hasContent = true;
                break;
            }
        }

        return $hasContent ? 0.3 : 0.0;
    }

    /**
     * 解析文件
     */
    public function parse(array $files): array {
        if (!$this->enabled) {
            throw new \RuntimeException('LLM Parser is not configured. Please set API key.');
        }

        $invoices = [];

        foreach ($files as $file) {
            $content = $this->getFileContent($file);
            if (empty($content)) continue;

            try {
                $invoice = $this->parseWithLlm($content, $file['name']);
                if ($invoice !== null && !empty($invoice['items'])) {
                    $invoices[] = $this->normalizeInvoice($invoice);
                }
            } catch (\Exception $e) {
                // 記錄錯誤但繼續處理其他文件
                error_log("LLM Parser error for {$file['name']}: " . $e->getMessage());
            }
        }

        return $invoices;
    }

    /**
     * 獲取文件內容
     */
    protected function getFileContent(array $file): string {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($ext === 'json') {
            $json = $this->readJsonFile($file);
            if (!$json) return '';
            
            // 如果是 doc_parser 格式，提取文字內容
            if (isset($json['parsing_res_list'])) {
                $texts = [];
                foreach ($json['parsing_res_list'] as $block) {
                    $content = $block['block_content'] ?? '';
                    if (!empty($content)) {
                        $texts[] = strip_tags($content);
                    }
                }
                return implode("\n", $texts);
            }
            
            return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return $this->readTextFile($file) ?? '';
    }

    /**
     * 使用 LLM 解析內容
     */
    protected function parseWithLlm(string $content, string $filename): ?array {
        $prompt = $this->buildPrompt($content);
        $response = $this->callApi($prompt);
        
        if (empty($response)) {
            return null;
        }

        return $this->parseResponse($response, $filename);
    }

    /**
     * 構建提示詞
     */
    protected function buildPrompt(string $content): string {
        return <<<PROMPT
你是一個專業的發票數據提取助手。請分析以下 OCR 提取的發票內容，提取結構化數據。

請嚴格按照以下 JSON 格式返回，不要添加任何其他文字：

```json
{
  "supplier_name": "供應商名稱",
  "customer_name": "客戶名稱（如有）",
  "invoice_date": "YYYY-MM-DD（如有）",
  "invoice_number": "發票號碼（如有）",
  "currency": "貨幣代碼（如 HKD, CNY, USD）",
  "declared_total": 總金額數字,
  "items": [
    {
      "code": "產品代碼",
      "name": "產品名稱",
      "qty": 數量,
      "unit_price": 單價,
      "total": 小計
    }
  ]
}
```

注意：
1. 如果某個字段無法識別，使用 null
2. 數字字段使用純數字，不要包含貨幣符號
3. 日期統一使用 YYYY-MM-DD 格式
4. 如果 qty 無法確定，設為 1
5. 確保 qty * unit_price = total（允許小數點誤差）

以下是發票內容：

---
{$content}
---

請只返回 JSON，不要有任何解釋或其他文字。
PROMPT;
    }

    /**
     * 調用 API
     */
    protected function callApi(string $prompt): ?string {
        if ($this->apiConfig['provider'] === 'anthropic') {
            return $this->callAnthropicApi($prompt);
        } else if ($this->apiConfig['provider'] === 'openai') {
            return $this->callOpenAiApi($prompt);
        }
        
        throw new \RuntimeException("Unknown API provider: {$this->apiConfig['provider']}");
    }

    /**
     * 調用 Anthropic API
     */
    protected function callAnthropicApi(string $prompt): ?string {
        $data = [
            'model' => $this->apiConfig['model'],
            'max_tokens' => $this->apiConfig['max_tokens'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiConfig['api_key'],
            'anthropic-version: 2023-06-01',
        ];

        $response = $this->httpPost($this->apiConfig['base_url'], $data, $headers);
        
        if (isset($response['content'][0]['text'])) {
            return $response['content'][0]['text'];
        }

        return null;
    }

    /**
     * 調用 OpenAI API
     */
    protected function callOpenAiApi(string $prompt): ?string {
        $data = [
            'model' => $this->apiConfig['model'] ?? 'gpt-4o',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => $this->apiConfig['temperature'],
            'max_tokens' => $this->apiConfig['max_tokens'],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiConfig['api_key'],
        ];

        $url = $this->apiConfig['base_url'] ?? 'https://api.openai.com/v1/chat/completions';
        $response = $this->httpPost($url, $data, $headers);
        
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }

        return null;
    }

    /**
     * HTTP POST 請求
     */
    protected function httpPost(string $url, array $data, array $headers): ?array {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("API request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("API returned error {$httpCode}: {$response}");
        }

        return json_decode($response, true);
    }

    /**
     * 解析 LLM 響應
     */
    protected function parseResponse(string $response, string $filename): ?array {
        // 提取 JSON
        $json = null;
        
        // 嘗試直接解析
        $json = json_decode($response, true);
        
        // 如果失敗，嘗試從 markdown 代碼塊中提取
        if ($json === null) {
            if (preg_match('/```(?:json)?\s*\n?([\s\S]*?)\n?```/', $response, $m)) {
                $json = json_decode(trim($m[1]), true);
            }
        }

        // 如果仍然失敗，嘗試找到 JSON 對象
        if ($json === null) {
            if (preg_match('/\{[\s\S]*\}/', $response, $m)) {
                $json = json_decode($m[0], true);
            }
        }

        if (!is_array($json)) {
            return null;
        }

        // 構建標準結構
        return [
            'source_file' => $filename,
            'supplier_name' => $json['supplier_name'] ?? '',
            'customer_name' => $json['customer_name'] ?? '',
            'invoice_date' => $json['invoice_date'] ?? null,
            'invoice_number' => $json['invoice_number'] ?? null,
            'declared_total' => isset($json['declared_total']) ? (float)$json['declared_total'] : null,
            'calc_total' => $this->calculateTotal($json['items'] ?? []),
            'currency' => $json['currency'] ?? null,
            'items' => $this->normalizeItems($json['items'] ?? []),
            'metadata' => ['parser' => 'llm_assisted'],
        ];
    }

    /**
     * 計算項目總額
     */
    protected function calculateTotal(array $items): float {
        $total = 0;
        foreach ($items as $item) {
            $total += (float)($item['total'] ?? 0);
        }
        return round($total, 2);
    }

    /**
     * 標準化項目
     */
    protected function normalizeItems(array $items): array {
        $result = [];
        
        foreach ($items as $item) {
            if (empty($item['name']) && empty($item['code'])) {
                continue;
            }

            $qty = (float)($item['qty'] ?? 1);
            if ($qty <= 0) $qty = 1;

            $unitPrice = (float)($item['unit_price'] ?? 0);
            $total = (float)($item['total'] ?? 0);

            // 計算缺失值
            if ($total <= 0 && $unitPrice > 0) {
                $total = $qty * $unitPrice;
            }
            if ($unitPrice <= 0 && $total > 0) {
                $unitPrice = $total / $qty;
            }

            $result[] = [
                'code' => (string)($item['code'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'qty' => round($qty, 4),
                'unit' => (string)($item['unit'] ?? ''),
                'unit_price' => round($unitPrice, 4),
                'total' => round($total, 2),
                'metadata' => [],
            ];
        }

        return $result;
    }

    /**
     * 檢查是否已配置
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
}
