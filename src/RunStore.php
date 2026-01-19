<?php
declare(strict_types=1);

final class RunStore {
  private string $baseDir;

  public function __construct(string $uploadsDir) {
    $this->baseDir = rtrim($uploadsDir, '/\\');
    Util::ensureDir($this->baseDir);
  }

  public function runDir(string $runId): string {
    return $this->baseDir . DIRECTORY_SEPARATOR . $runId;
  }

  public function draftPath(string $runId): string {
    return $this->runDir($runId) . DIRECTORY_SEPARATOR . 'draft.json';
  }

  public function saveDraft(string $runId, array $draft): void {
    $dir = $this->runDir($runId);
    Util::ensureDir($dir);
    $path = $this->draftPath($runId);

    $tmp = $path . '.tmp';
    $json = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Failed to encode draft JSON');

    if (file_put_contents($tmp, $json) === false) throw new RuntimeException('Failed to write draft temp file');
    if (!rename($tmp, $path)) throw new RuntimeException('Failed to finalize draft file');
  }

  public function loadDraft(string $runId): array {
    $path = $this->draftPath($runId);
    if (!file_exists($path)) throw new RuntimeException('Draft not found for run: ' . $runId);
    return Util::readJson($path);
  }

  /**
   * Save metadata about raw upload (now supports both ZIP and folder uploads)
   */
  public function saveRawUpload(string $runId, string $sourcePath, string $filesDir): void {
    $dir = $this->runDir($runId);
    Util::ensureDir($dir);

    $meta = [
      'source_path' => $sourcePath,
      'files_dir' => $filesDir,
      'saved_at' => Util::nowSql(),
      'is_folder_upload' => is_dir($sourcePath),
    ];
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  }

  /**
   * List all runs
   */
  public function listRuns(int $limit = 50): array {
    $runs = [];
    $dirs = glob($this->baseDir . '/20*', GLOB_ONLYDIR);
    
    if ($dirs === false) return [];
    
    // Sort by name descending (newest first)
    rsort($dirs);
    
    $count = 0;
    foreach ($dirs as $dir) {
      $runId = basename($dir);
      
      // Skip _files and _scan directories
      if (preg_match('/_(files|scan|unzipped)$/', $runId)) continue;
      
      $draftPath = $dir . '/draft.json';
      if (!file_exists($draftPath)) continue;
      
      try {
        $draft = Util::readJson($draftPath);
        $runs[] = [
          'run_id' => $runId,
          'type' => $draft['type'] ?? 'unknown',
          'created_at' => $draft['created_at'] ?? '',
          'invoice_count' => count($draft['invoices'] ?? []),
          'source' => $draft['source'] ?? [],
        ];
        
        $count++;
        if ($count >= $limit) break;
      } catch (Throwable $e) {
        // Skip invalid drafts
        continue;
      }
    }
    
    return $runs;
  }

  /**
   * Delete a run and all its files
   */
  public function deleteRun(string $runId): bool {
    $dir = $this->runDir($runId);
    if (!is_dir($dir)) return false;
    
    $this->recursiveDelete($dir);
    
    // Also delete related directories (_files, _scan, etc.)
    $relatedDirs = glob($this->baseDir . "/{$runId}_*", GLOB_ONLYDIR);
    foreach ($relatedDirs as $relDir) {
      $this->recursiveDelete($relDir);
    }
    
    return true;
  }

  private function recursiveDelete(string $dir): void {
    if (!is_dir($dir)) return;
    
    $items = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($items as $item) {
      if ($item->isDir()) {
        rmdir($item->getPathname());
      } else {
        unlink($item->getPathname());
      }
    }
    
    rmdir($dir);
  }
}
