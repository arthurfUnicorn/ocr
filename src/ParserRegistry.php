<?php
declare(strict_types=1);

/**
 * ParserRegistry - 解析器註冊表和自動格式檢測
 * 
 * 增強版：
 * - 支持更多解析器類型
 * - 集成數據驗證
 * - 支持 LLM 輔助解析（可選）
 */
class ParserRegistry {
    
    /** @var Parsers\ParserInterface[] */
    private array $parsers = [];
    
    /** @var float 最低置信度閾值 */
    private float $minConfidence = 0.3;

    /** @var Validators\InvoiceDataValidator|null */
    private ?Validators\InvoiceDataValidator $validator = null;

    /** @var bool 是否自動驗證 */
    private bool $autoValidate = true;

    /**
     * 構造函數 - 註冊默認解析器
     */
    public function __construct() {
        // 按優先順序註冊解析器
        // 1. 專用格式解析器（高優先級）
        $this->register(new Parsers\DocParserJsonParser());
        
        // 2. 通用格式解析器
        $this->register(new Parsers\GenericMarkdownParser());
        
        // 3. 文字區塊解析器（無表格時使用）
        $this->register(new Parsers\TextBlockParser());
        
        // 4. LLM 輔助解析器（後備，需要配置 API）
        $this->register(new Parsers\LlmAssistedParser());
    }

    /**
     * 註冊解析器
     */
    public function register(Parsers\ParserInterface $parser): self {
        $this->parsers[$parser->getId()] = $parser;
        return $this;
    }

    /**
     * 取消註冊解析器
     */
    public function unregister(string $parserId): self {
        unset($this->parsers[$parserId]);
        return $this;
    }

    /**
     * 獲取所有解析器
     */
    public function getAllParsers(): array {
        return $this->parsers;
    }

    /**
     * 獲取指定解析器
     */
    public function getParser(string $id): ?Parsers\ParserInterface {
        return $this->parsers[$id] ?? null;
    }

    /**
     * 設置最低置信度閾值
     */
    public function setMinConfidence(float $confidence): self {
        $this->minConfidence = max(0.0, min(1.0, $confidence));
        return $this;
    }

    /**
     * 設置數據驗證器
     */
    public function setValidator(?Validators\InvoiceDataValidator $validator): self {
        $this->validator = $validator;
        return $this;
    }

    /**
     * 啟用/禁用自動驗證
     */
    public function setAutoValidate(bool $enabled): self {
        $this->autoValidate = $enabled;
        return $this;
    }

    /**
     * 配置 LLM 解析器
     */
    public function configureLlmParser(array $config): self {
        $llmParser = $this->getParser('llm_assisted');
        if ($llmParser instanceof Parsers\LlmAssistedParser) {
            $llmParser->configure($config);
        }
        return $this;
    }

    /**
     * 檢測最適合的解析器
     * 
     * @param array $files 文件數組
     * @return array ['parser' => ParserInterface|null, 'confidence' => float, 'scores' => array]
     */
    public function detectParser(array $files): array {
        $scores = [];
        $best = null;
        $bestScore = 0.0;

        foreach ($this->parsers as $id => $parser) {
            // 跳過未配置的 LLM 解析器
            if ($parser instanceof Parsers\LlmAssistedParser && !$parser->isEnabled()) {
                $scores[$id] = [
                    'parser' => $parser->getName(),
                    'confidence' => 0.0,
                    'note' => 'Not configured',
                ];
                continue;
            }

            $confidence = $parser->canParse($files);
            $scores[$id] = [
                'parser' => $parser->getName(),
                'confidence' => $confidence,
            ];

            if ($confidence > $bestScore) {
                $bestScore = $confidence;
                $best = $parser;
            }
        }

        return [
            'parser' => ($bestScore >= $this->minConfidence) ? $best : null,
            'confidence' => $bestScore,
            'scores' => $scores,
        ];
    }

    /**
     * 解析文件
     * 
     * @param array $files 文件數組
     * @param string|null $forceParserId 強制使用指定解析器
     * @return array ['invoices' => array, 'parser_used' => string, 'parser_name' => string, 'confidence' => float, 'validation' => array|null]
     */
    public function parse(array $files, ?string $forceParserId = null): array {
        // 選擇解析器
        if ($forceParserId !== null) {
            $parser = $this->getParser($forceParserId);
            if (!$parser) {
                throw new RuntimeException("Parser not found: {$forceParserId}");
            }
            $confidence = $parser->canParse($files);
        } else {
            $detection = $this->detectParser($files);
            $parser = $detection['parser'];
            $confidence = $detection['confidence'];

            if (!$parser) {
                throw new RuntimeException(
                    "No suitable parser found. Best confidence was {$detection['confidence']}. " .
                    "Available parsers: " . implode(', ', array_keys($this->parsers))
                );
            }
        }

        // 解析
        $invoices = $parser->parse($files);

        // 可選的數據驗證
        $validationResults = null;
        if ($this->autoValidate && $this->validator !== null) {
            $validationResults = $this->validator->validateBatch($invoices);
            $invoices = $validationResults['invoices'];
        }

        return [
            'invoices' => $invoices,
            'parser_used' => $parser->getId(),
            'parser_name' => $parser->getName(),
            'confidence' => $confidence,
            'validation' => $validationResults,
        ];
    }

    /**
     * 嘗試所有解析器直到成功
     * 
     * @param array $files 文件數組
     * @return array 解析結果
     */
    public function parseWithFallback(array $files): array {
        $errors = [];

        // 按置信度排序解析器
        $detection = $this->detectParser($files);
        $sortedParsers = $detection['scores'];
        arsort($sortedParsers);

        foreach ($sortedParsers as $id => $info) {
            if ($info['confidence'] < $this->minConfidence) {
                continue;
            }

            try {
                $result = $this->parse($files, $id);
                if (!empty($result['invoices'])) {
                    $result['fallback_errors'] = $errors;
                    return $result;
                }
            } catch (\Exception $e) {
                $errors[$id] = $e->getMessage();
            }
        }

        throw new RuntimeException(
            "All parsers failed. Errors: " . json_encode($errors)
        );
    }

    /**
     * 獲取支持的文件擴展名列表
     */
    public function getSupportedExtensions(): array {
        $extensions = [];
        foreach ($this->parsers as $parser) {
            $extensions = array_merge($extensions, $parser->getSupportedExtensions());
        }
        return array_unique($extensions);
    }

    /**
     * 獲取解析器信息（用於 UI 顯示）
     */
    public function getParsersInfo(): array {
        $info = [];
        foreach ($this->parsers as $id => $parser) {
            $enabled = true;
            if ($parser instanceof Parsers\LlmAssistedParser) {
                $enabled = $parser->isEnabled();
            }

            $info[$id] = [
                'id' => $id,
                'name' => $parser->getName(),
                'extensions' => $parser->getSupportedExtensions(),
                'enabled' => $enabled,
            ];
        }
        return $info;
    }
}
