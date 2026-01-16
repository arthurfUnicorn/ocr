<?php
declare(strict_types=1);

namespace Parsers;

abstract class AbstractParser implements ParserInterface {
  
  protected function groupFilesByBaseName(array $files): array {
    $groups = [];
    
    foreach ($files as $file) {
      // Get base name without extension
      $name = $file['name'];
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $base = pathinfo($name, PATHINFO_FILENAME);
      
      // Remove common suffixes like _res, _output, etc.
      $base = preg_replace('/_(res|output|result|parsed)$/i', '', $base);
      
      if (!isset($groups[$base])) {
        $groups[$base] = [];
      }
      $groups[$base][$ext] = $file;
    }
    
    return $groups;
  }

  protected function filterByExtensions(array $files, array $extensions): array {
    return array_filter($files, function($file) use ($extensions) {
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      return in_array($ext, $extensions, true);
    });
  }

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
   * Normalize invoice data to standard structure
   */
  protected function normalizeInvoice(array $data): array {
    return [
      'source_file' => $data['source_file'] ?? 'unknown',
      'format_detected' => $this->getId(),
      'supplier_name' => $data['supplier_name'] ?? '',
      'customer_name' => $data['customer_name'] ?? '',
      'invoice_date' => $data['invoice_date'] ?? null,
      'invoice_number' => $data['invoice_number'] ?? null,
      'declared_total' => $data['declared_total'] ?? null,
      'calc_total' => (float)($data['calc_total'] ?? 0),
      'currency' => $data['currency'] ?? null,
      'items' => array_map(function($item) {
        return [
          'code' => (string)($item['code'] ?? ''),
          'name' => (string)($item['name'] ?? ''),
          'description' => (string)($item['description'] ?? ''),
          'qty' => (float)($item['qty'] ?? 1),
          'unit' => (string)($item['unit'] ?? ''),
          'unit_price' => (float)($item['unit_price'] ?? 0),
          'total' => (float)($item['total'] ?? 0),
          'metadata' => $item['metadata'] ?? [],
        ];
      }, $data['items'] ?? []),
      'metadata' => $data['metadata'] ?? [],
    ];
  }
}
