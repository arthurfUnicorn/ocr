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

  public function saveRawUpload(string $runId, string $zipPath, string $extractDir): void {
    $dir = $this->runDir($runId);
    Util::ensureDir($dir);

    $meta = [
      'zip_path' => $zipPath,
      'extract_dir' => $extractDir,
      'saved_at' => Util::nowSql(),
    ];
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  }
}
