<?php
declare(strict_types=1);

/**
 * FileScanner - Scans uploaded folders and discovers invoice files
 */
class FileScanner {
  /** @var string[] Extensions to scan for */
  private array $targetExtensions;
  
  /** @var string[] Patterns to ignore */
  private array $ignorePatterns = [
    '/^\./',           // Hidden files
    '/^__/',           // Python cache etc
    '/\.pyc$/',        // Python compiled
    '/thumbs\.db$/i',  // Windows thumbs
    '/desktop\.ini$/i',// Windows desktop config
    '/\.ds_store$/i',  // Mac folder config
  ];

  public function __construct(array $targetExtensions = ['json', 'md']) {
    $this->targetExtensions = array_map('strtolower', $targetExtensions);
  }

  /**
   * Set extensions to scan for
   */
  public function setExtensions(array $extensions): void {
    $this->targetExtensions = array_map('strtolower', $extensions);
  }

  /**
   * Add patterns to ignore
   */
  public function addIgnorePattern(string $pattern): void {
    $this->ignorePatterns[] = $pattern;
  }

  /**
   * Scan a directory for target files
   * 
   * @param string $dir Directory to scan
   * @return array Array of file info ['path', 'name', 'ext', 'size', 'relative_path']
   */
  public function scanDirectory(string $dir): array {
    if (!is_dir($dir)) {
      throw new RuntimeException("Not a directory: {$dir}");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if (!$file->isFile()) continue;
      
      $name = $file->getFilename();
      
      // Check ignore patterns
      $skip = false;
      foreach ($this->ignorePatterns as $pattern) {
        if (preg_match($pattern, $name)) {
          $skip = true;
          break;
        }
      }
      if ($skip) continue;

      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      
      // Check if extension is in target list
      if (!in_array($ext, $this->targetExtensions, true)) {
        continue;
      }

      $path = $file->getPathname();
      $relativePath = substr($path, strlen(rtrim($dir, '/\\')) + 1);

      $files[] = [
        'path' => $path,
        'name' => $name,
        'ext' => $ext,
        'size' => $file->getSize(),
        'relative_path' => $relativePath,
        'base_name' => pathinfo($name, PATHINFO_FILENAME),
      ];
    }

    // Sort by name for consistent ordering
    usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

    return $files;
  }

  /**
   * Process uploaded files from $_FILES (folder upload)
   * 
   * @param array $uploadedFiles $_FILES array with multiple files
   * @param string $targetDir Directory to save files to
   * @return array Scan results
   */
  public function processUploadedFiles(array $uploadedFiles, string $targetDir): array {
    Util::ensureDir($targetDir);
    
    $savedFiles = [];
    $errors = [];
    
    // Handle both single file and multiple files upload
    $names = is_array($uploadedFiles['name']) ? $uploadedFiles['name'] : [$uploadedFiles['name']];
    $tmpNames = is_array($uploadedFiles['tmp_name']) ? $uploadedFiles['tmp_name'] : [$uploadedFiles['tmp_name']];
    $errors_upload = is_array($uploadedFiles['error']) ? $uploadedFiles['error'] : [$uploadedFiles['error']];
    
    for ($i = 0; $i < count($names); $i++) {
      $name = $names[$i];
      $tmpName = $tmpNames[$i];
      $error = $errors_upload[$i];
      
      if ($error !== UPLOAD_ERR_OK) {
        $errors[] = ['file' => $name, 'error' => $this->uploadErrorMessage($error)];
        continue;
      }
      
      if (empty($name)) continue;
      
      // Preserve directory structure from webkitRelativePath
      // The name might include path like "folder/subfolder/file.json"
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      
      // Skip non-target files
      if (!in_array($ext, $this->targetExtensions, true)) {
        continue;
      }
      
      // Skip ignored files
      $skip = false;
      foreach ($this->ignorePatterns as $pattern) {
        if (preg_match($pattern, basename($name))) {
          $skip = true;
          break;
        }
      }
      if ($skip) continue;
      
      // Create subdirectory if needed
      $targetPath = $targetDir . '/' . $name;
      $targetSubDir = dirname($targetPath);
      Util::ensureDir($targetSubDir);
      
      // Move uploaded file
      if (!move_uploaded_file($tmpName, $targetPath)) {
        $errors[] = ['file' => $name, 'error' => 'Failed to move uploaded file'];
        continue;
      }
      
      $savedFiles[] = [
        'path' => $targetPath,
        'name' => basename($name),
        'ext' => $ext,
        'size' => filesize($targetPath),
        'relative_path' => $name,
        'base_name' => pathinfo(basename($name), PATHINFO_FILENAME),
      ];
    }

    return [
      'files' => $savedFiles,
      'count' => count($savedFiles),
      'errors' => $errors,
      'target_dir' => $targetDir,
    ];
  }

  /**
   * Group files by their base name (without extension and common suffixes)
   * 
   * @param array $files Array of file info
   * @return array Grouped files ['base_name' => ['json' => file, 'md' => file, ...]]
   */
  public function groupByBaseName(array $files): array {
    $groups = [];
    
    foreach ($files as $file) {
      $base = $file['base_name'];
      
      // Remove common suffixes
      $base = preg_replace('/_(res|output|result|parsed|ocr)$/i', '', $base);
      
      if (!isset($groups[$base])) {
        $groups[$base] = [];
      }
      
      $ext = $file['ext'];
      $groups[$base][$ext] = $file;
    }
    
    return $groups;
  }

  /**
   * Load file contents for parsing
   * 
   * @param array $files Array of file info
   * @return array Files with content loaded
   */
  public function loadFileContents(array $files): array {
    $loaded = [];
    
    foreach ($files as $file) {
      $content = null;
      
      if ($file['ext'] === 'json') {
        $raw = file_get_contents($file['path']);
        if ($raw !== false) {
          $content = json_decode($raw, true);
        }
      } else {
        $content = file_get_contents($file['path']);
      }
      
      $loaded[] = array_merge($file, ['content' => $content]);
    }
    
    return $loaded;
  }

  /**
   * Get summary of scanned files
   */
  public function summarize(array $files): array {
    $byExt = [];
    $totalSize = 0;
    
    foreach ($files as $file) {
      $ext = $file['ext'];
      if (!isset($byExt[$ext])) {
        $byExt[$ext] = 0;
      }
      $byExt[$ext]++;
      $totalSize += $file['size'];
    }
    
    return [
      'total_files' => count($files),
      'by_extension' => $byExt,
      'total_size' => $totalSize,
      'total_size_human' => $this->formatBytes($totalSize),
    ];
  }

  private function uploadErrorMessage(int $code): string {
    return match($code) {
      UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
      UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
      UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
      UPLOAD_ERR_NO_FILE => 'No file was uploaded',
      UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
      UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
      UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
      default => "Unknown error ({$code})",
    };
  }

  private function formatBytes(int $bytes): string {
    if ($bytes < 1024) return "{$bytes} B";
    if ($bytes < 1048576) return round($bytes / 1024, 1) . " KB";
    return round($bytes / 1048576, 1) . " MB";
  }
}
