<?php
declare(strict_types=1);

final class Util {
  public static function ensureDir(string $path): void {
    if (!is_dir($path)) mkdir($path, 0775, true);
  }

  public static function listFiles(string $dir, array $exts): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
      if (!$file->isFile()) continue;
      $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
      if (in_array($ext, $exts, true)) $out[] = $file->getPathname();
    }
    sort($out);
    return $out;
  }

  public static function readJson(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException("Cannot read: $path");
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("Invalid JSON: $path");
    return $data;
  }

  public static function nowSql(): string {
    return date('Y-m-d H:i:s');
  }

  public static function slug(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/\s+/u', '', $s);
    $s = preg_replace('/[^a-z0-9\x{4e00}-\x{9fff}]/u', '', $s);
    return $s ?: 'unknown';
  }

  public static function money(string $s): float {
    $t = preg_replace('/[^\d\.,-]/', '', $s);
    $t = str_replace(',', '', $t);
    return is_numeric($t) ? (float)$t : 0.0;
  }
}
