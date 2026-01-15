<?php
declare(strict_types=1);

final class Db {
  public static function pdo(array $db): PDO {
    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
  }
}
