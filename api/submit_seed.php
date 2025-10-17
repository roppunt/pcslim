<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/conn.php';

$inp = json_decode(file_get_contents('php://input'), true) ?? [];

// minimale velden
$brand  = trim($inp['brand']  ?? '');
$model  = trim($inp['display_model'] ?? '');
$year   = isset($inp['year']) ? (int)$inp['year'] : null;
$cpu    = trim($inp['cpu'] ?? '');
$ramt   = trim($inp['ram_type'] ?? '');
$iface  = trim($inp['storage_interface'] ?? '');

if ($brand==='' || $model==='') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'brand en display_model zijn verplicht']); exit;
}

// normaliseer veelvoorkomende varianten
$ramt = $ramt ?: null;
$iface = $iface ?: null;
if (strcasecmp($ramt,'onbekend')===0) $ramt = null;

// dubbel voorkomen? (zachte check)
$chk = $pdo->prepare("SELECT id FROM pcslim_models_seed WHERE brand=? AND model=? LIMIT 1");
$chk->execute([$brand,$model]);
if ($chk->fetchColumn()) {
  echo json_encode(['ok'=>true,'message'=>'Bestond al; pipeline pakt hem op']); exit;
}

// insert seed
$sql = "INSERT INTO pcslim_models_seed
        (brand, model, year, cpu, ram_type, storage_interface, created_at)
        VALUES (:brand,:model,:year,:cpu,:ram_type,:storage_interface,NOW())";
$st = $pdo->prepare($sql);
$st->execute([
  ':brand'=>$brand, ':model'=>$model, ':year'=>$year,
  ':cpu'=>$cpu?:null, ':ram_type'=>$ramt?:null, ':storage_interface'=>$iface?:null
]);

echo json_encode(['ok'=>true,'seed_id'=>$pdo->lastInsertId(),'message'=>'Toegevoegd; wordt automatisch verwerkt']);
