<?php
declare(strict_types=1);

final class CsvWriter {
  public static function append(string $file, array $header, array $rows): void {
    $exists = file_exists($file);
    $fp = fopen($file, 'ab');
    if (!$fp) throw new RuntimeException("Cannot write: $file");

    if (!$exists) {
      fputcsv($fp, $header);
    }
    foreach ($rows as $r) {
      $line = [];
      foreach ($header as $col) $line[] = $r[$col] ?? '';
      fputcsv($fp, $line);
    }
    fclose($fp);
  }
}
