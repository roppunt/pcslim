<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/conn.php'; // of ../conn.php

$brand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$model = isset($_GET['model']) ? trim($_GET['model']) : '';

if ($brand === '' || $model === '') {
  echo json_encode(['ok'=>false,'error'=>'brand/model ontbreekt']); exit;
}

function norm($s){ return mb_strtolower(trim($s)); }

try{
  // 1) Kandidaten op merk
  $stmt = $pdo->prepare("SELECT * FROM models WHERE active=1 AND brand LIKE ? LIMIT 200");
  $stmt->execute([$brand]);
  $rows = $stmt->fetchAll();

  $picked = null;

  // 2) Regex proberen
  $mnorm = norm($model);
  foreach ($rows as $r) {
    $patterns = [];
    if (!empty($r['model_regex'])) {
      $decoded = json_decode($r['model_regex'], true);
      if (is_array($decoded)) $patterns = $decoded;
    }
    foreach ($patterns as $pat) {
      $pat = trim($pat);
      if ($pat === '') continue;
      $rx = '/'.$pat.'/i';
      if (@preg_match($rx, $mnorm) && preg_match($rx, $mnorm)) { $picked = $r; break 2; }
    }
  }

  // 3) Fallback: LIKE op display_model
  if (!$picked) {
    $stmt2 = $pdo->prepare("SELECT * FROM models 
                            WHERE active=1 AND brand LIKE ? AND display_model LIKE ?
                            ORDER BY id DESC LIMIT 1");
    $stmt2->execute([$brand, "%$model%"]);
    $picked = $stmt2->fetch() ?: null;
  }

  if(!$picked){
    echo json_encode(['ok'=>true,'spec'=>null]); exit;
  }

  // alleen relevante velden teruggeven
  $spec = [
    'id'           => (int)$picked['id'],
    'brand'        => $picked['brand'],
    'display_model'=> $picked['display_model'],
    'max_ram_gb'   => isset($picked['max_ram_gb']) ? (int)$picked['max_ram_gb'] : null,
    'supports_w11' => isset($picked['supports_w11']) ? (int)$picked['supports_w11'] : null,
    'storage'      => $picked['storage'] ?? null,
    'cpu_arch'     => $picked['cpu_arch'] ?? null,
    'notes'        => $picked['notes'] ?? null,
  ];

  echo json_encode(['ok'=>true,'spec'=>$spec], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server-fout']);
}
