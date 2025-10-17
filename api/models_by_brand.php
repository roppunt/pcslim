<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/conn.php';

$brand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$q     = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($brand === '') { echo json_encode([]); exit; }

$params = [':brand' => $brand];
$filter = '';
if ($q !== '') {
    $filter = ' AND display_model LIKE :pattern';
    $params[':pattern'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
}

$sql = "SELECT DISTINCT display_model
        FROM models
        WHERE brand = :brand
        $filter
        ORDER BY display_model
        LIMIT 25";
$st = $pdo->prepare($sql);
$st->execute($params);
echo json_encode($st->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
