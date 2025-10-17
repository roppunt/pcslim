<?php
// web/api/conn.php
declare(strict_types=1);

/**
 * PDO-connector met env-overrides.
 * Defaultt naar Docker service "db" en database "pcslim".
 */

$db_host = getenv('DB_HOST') ?: 'db';         // in jouw stack: 'db'
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'pcslim';     // <-- zonder streepje
$db_user = getenv('DB_USER') ?: 'pcslim';
$db_pass = getenv('DB_PASS') ?: 'devpass';
$charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$charset}";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
  PDO::ATTR_PERSISTENT         => false,
];

try {
  $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'error' => 'Database connection failed',
    'detail' => $e->getMessage(),
    'host' => $db_host,
    'db' => $db_name,
  ]);
  exit;
}
