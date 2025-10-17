<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__.'/conn.php';   // <- dit moet bestaan en géén output doen

try {
    $ok = $pdo->query('SELECT 1')->fetchColumn();
    echo json_encode(['ok'=>true,'db'=>$ok]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
