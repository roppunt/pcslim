<?php
require __DIR__.'/config.php';

if (empty($_SESSION['uid'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

$method = $_SERVER['REQUEST_METHOD'];

function read_json() {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

if ($method === 'GET') {
  // optionele filters
  $brand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
  $q     = isset($_GET['q']) ? trim($_GET['q']) : '';
  $sql = "SELECT * FROM models WHERE 1";
  $params = [];
  if ($brand !== '') { $sql .= " AND brand = ?"; $params[] = $brand; }
  if ($q !== '')     { $sql .= " AND (display_model LIKE ? OR notes LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
  $sql .= " ORDER BY brand ASC, display_model ASC";
  $stmt = $pdo->prepare($sql); $stmt->execute($params);
  echo json_encode(['ok'=>true, 'models'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE); exit;
}

if ($method === 'POST') {
  // create
  $j = read_json();
  $stmt = $pdo->prepare("INSERT INTO models
    (brand, display_model, model_regex, max_ram_gb, supports_w11, storage, cpu_arch, notes, active)
    VALUES (?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    trim($j['brand'] ?? ''),
    trim($j['display_model'] ?? ''),
    !empty($j['model_regex']) ? json_encode($j['model_regex'], JSON_UNESCAPED_UNICODE) : null,
    isset($j['max_ram_gb']) ? (int)$j['max_ram_gb'] : null,
    isset($j['supports_w11']) ? (int)!!$j['supports_w11'] : null,
    trim($j['storage'] ?? '') ?: null,
    trim($j['cpu_arch'] ?? '') ?: null,
    trim($j['notes'] ?? '') ?: null,
    isset($j['active']) ? (int)!!$j['active'] : 1
  ]);
  echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId()]); exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
  // update
  parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
  $id = isset($qs['id']) ? (int)$qs['id'] : 0;
  if ($id<=0) { echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
  $j = read_json();

  // kolommen whitelisten
  $cols = ['brand','display_model','model_regex','max_ram_gb','supports_w11','storage','cpu_arch','notes','active'];
  $set = []; $params=[];
  foreach ($cols as $c) {
    if (array_key_exists($c, $j)) {
      if ($c==='model_regex') { $set[]="$c=?"; $params[] = is_array($j[$c]) ? json_encode($j[$c], JSON_UNESCAPED_UNICODE) : null; }
      else if (in_array($c,['max_ram_gb','supports_w11','active'])) { $set[]="$c=?"; $params[] = ($j[$c]===null?'':(int)$j[$c]); }
      else { $set[]="$c=?"; $params[] = ($j[$c]===null? null : trim($j[$c])); }
    }
  }
  if (!$set) { echo json_encode(['ok'=>false,'error'=>'Nothing to update']); exit; }

  $sql="UPDATE models SET ".implode(',', $set)." WHERE id=?"; $params[]=$id;
  $stmt=$pdo->prepare($sql); $stmt->execute($params);
  echo json_encode(['ok'=>true]); exit;
}

if ($method === 'DELETE') {
  parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
  $id = isset($qs['id']) ? (int)$qs['id'] : 0;
  if ($id<=0) { echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
  $pdo->prepare("DELETE FROM models WHERE id=?")->execute([$id]);
  echo json_encode(['ok'=>true]); exit;
}

http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
