<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/conn.php';

$stmt = $pdo->query("SELECT DISTINCT brand FROM models ORDER BY brand");
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
