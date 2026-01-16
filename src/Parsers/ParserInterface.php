<?php
declare(strict_types=1);

namespace Parsers;

interface ParserInterface {
  /**
   * Check if this parser can handle the given files
   * @param array $files Array of file info ['path' => string, 'name' => string, 'content' => mixed]
   * @return float Confidence score 0.0 - 1.0 (0 = cannot parse, 1 = definitely can parse)
   */
  public function canParse(array $files): float;

  /**
   * Get parser identifier
   */
  public function getId(): string;

  /**
   * Get human-readable name
   */
  public function getName(): string;

  /**
   * Parse the files and return normalized invoice data
   * @param array $files Array of file info
   * @return array Normalized invoice structure
   */
  public function parse(array $files): array;

  /**
   * Get the file extensions this parser is interested in
   */
  public function getSupportedExtensions(): array;
}
