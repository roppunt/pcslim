<?php
require __DIR__.'/config.php';

$brand = strtolower(trim($_GET['brand'] ?? ''));
$model = strtolower(trim($_GET['model'] ?? ''));

if ($brand === '' || $model === '') json_out(['ok'=>false,'error'=>'brand and model required'], 400);

$stmt = $pdo->prepare("SELECT * FROM models WHERE active=1 AND LOWER(brand)=?");
$stmt->execute([$brand]);
$rows = $stmt->fetchAll();

$found = null;
foreach ($rows as $e) {
  $disp = strtolower(trim($e['display_model'] ?? ''));
  if ($disp && $disp === $model) { $found = $e; break; }

  $rxJson = $e['model_regex'];
  if ($rxJson) {
    $arr = json_decode($rxJson, true);
    if (is_array($arr)) {
      foreach ($arr as $rx) {
        try {
          if (@preg_match('/'.$rx.'/i', $model)) { $found = $e; break 2; }
        } catch (\Throwable $th) {}
      }
    }
  }
}
json_out(['ok'=>true,'match'=>$found]);
