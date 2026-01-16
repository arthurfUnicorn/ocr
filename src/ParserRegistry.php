<?php
declare(strict_types=1);

/**
 * ParserRegistry - Manages invoice parsers and auto-detects format
 */
class ParserRegistry {
  /** @var Parsers\ParserInterface[] */
  private array $parsers = [];
  
  /** @var float Minimum confidence score to accept a parser */
  private float $minConfidence = 0.3;

  public function __construct() {
    // Register default parsers in priority order
    $this->register(new Parsers\DocParserJsonParser());
    $this->register(new Parsers\GenericMarkdownParser());
  }

  /**
   * Register a parser
   */
  public function register(Parsers\ParserInterface $parser): void {
    $this->parsers[$parser->getId()] = $parser;
  }

  /**
   * Get all registered parsers
   */
  public function getAllParsers(): array {
    return $this->parsers;
  }

  /**
   * Get parser by ID
   */
  public function getParser(string $id): ?Parsers\ParserInterface {
    return $this->parsers[$id] ?? null;
  }

  /**
   * Set minimum confidence threshold
   */
  public function setMinConfidence(float $confidence): void {
    $this->minConfidence = max(0.0, min(1.0, $confidence));
  }

  /**
   * Detect the best parser for given files
   * 
   * @param array $files Array of file info
   * @return array ['parser' => ParserInterface|null, 'confidence' => float, 'scores' => array]
   */
  public function detectParser(array $files): array {
    $scores = [];
    $best = null;
    $bestScore = 0.0;

    foreach ($this->parsers as $id => $parser) {
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
   * Parse files using auto-detected or specified parser
   * 
   * @param array $files Array of file info
   * @param string|null $forceParserId Force specific parser ID
   * @return array ['invoices' => array, 'parser_used' => string, 'confidence' => float]
   */
  public function parse(array $files, ?string $forceParserId = null): array {
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
          "Available parsers: " . implode(', ', array_keys($detection['scores']))
        );
      }
    }

    return [
      'invoices' => $parser->parse($files),
      'parser_used' => $parser->getId(),
      'parser_name' => $parser->getName(),
      'confidence' => $confidence,
    ];
  }

  /**
   * Get all supported file extensions across all parsers
   */
  public function getAllSupportedExtensions(): array {
    $extensions = [];
    foreach ($this->parsers as $parser) {
      $extensions = array_merge($extensions, $parser->getSupportedExtensions());
    }
    return array_unique($extensions);
  }
}
