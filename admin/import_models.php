<?php require __DIR__.'/guard.php'; ?>
<!doctype html><html lang="nl"><meta charset="utf-8">
<title>CSV Import – Modellen</title>
<style>
body{font-family:sans-serif;max-width:800px;margin:24px auto;padding:0 12px}
pre{background:#f6f8fa;border:1px solid #eaecef;padding:10px;overflow:auto}
.small{font-size:12px;color:#555}
</style>
<h1>CSV import – Modellen</h1>
<p class="small"><a href="/admin/models.php">← terug naar modellen</a></p>
<p>Upload een CSV met de kolommen:</p>
<pre>brand,display_model,model_regex_json,max_ram_gb,supports_w11,storage,cpu_arch,notes,active</pre>
<p class="small">
- <strong>model_regex_json</strong>: JSON-array met regex strings (mag leeg)<br>
- <strong>supports_w11</strong>: 1=ja, 0=nee, leeg=onbekend<br>
- <strong>active</strong>: 1=actief, 0=inactief (default 1)<br>
- Records worden <strong>samengevoegd op (brand + display_model)</strong>: bestaat die al, dan wordt hij geüpdatet, anders aangemaakt.
</p>

<form method="post" enctype="multipart/form-data">
  <label>CSV-bestand: <input type="file" name="csv" accept=".csv" required></label><br><br>
  <label><input type="checkbox" name="dryrun" value="1" checked> Dry-run (alleen tonen wat er zou gebeuren)</label><br><br>
  <button>Uploaden</button>
</form>

<?php
require __DIR__.'/../api/config.php';

function out($s){ echo "<pre>".htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</pre>"; }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['csv'])) {
  $fn = $_FILES['csv']['tmp_name'];
  $dry = !empty($_POST['dryrun']);
  if (!is_uploaded_file($fn)) { out("Upload mislukt"); exit; }

  $f = fopen($fn, 'r');
  $head = fgetcsv($f, 0, ',');
  $req = ['brand','display_model','model_regex_json','max_ram_gb','supports_w11','storage','cpu_arch','notes','active'];
  foreach ($req as $k) if (!in_array($k, $head)) { out("Ontbrekende kolom: $k"); exit; }

  $ix = array_flip($head);
  $inserted=0; $updated=0; $seen=0; $log=[];

  while(($row=fgetcsv($f,0,','))!==false){
    $seen++;
    $brand = trim($row[$ix['brand']] ?? '');
    $model = trim($row[$ix['display_model']] ?? '');
    if (!$brand || !$model) { $log[]="SKIP (regel $seen): brand/model leeg"; continue; }

    $regexJson = trim($row[$ix['model_regex_json']] ?? '');
    $maxram = $row[$ix['max_ram_gb']] !== '' ? (int)$row[$ix['max_ram_gb']] : null;
    $w11    = $row[$ix['supports_w11']] !== '' ? (int)$row[$ix['supports_w11']] : null;
    $storage= trim($row[$ix['storage']] ?? '') ?: null;
    $cpu    = trim($row[$ix['cpu_arch']] ?? '') ?: null;
    $notes  = trim($row[$ix['notes']] ?? '') ?: null;
    $active = $row[$ix['active']] !== '' ? (int)$row[$ix['active']] : 1;

    // validatie regex json
    $regexDb = null;
    if ($regexJson!=='') {
      $arr = json_decode($regexJson, true);
      if (!is_array($arr)) { $log[]="SKIP ($brand $model): ongeldige model_regex_json"; continue; }
      $regexDb = json_encode($arr, JSON_UNESCAPED_UNICODE);
    }

    // bestaat?
    $stmt=$pdo->prepare("SELECT id FROM models WHERE brand=? AND display_model=?");
    $stmt->execute([$brand,$model]);
    $id = $stmt->fetchColumn();

    if ($id) {
      $updated++;
      $log[]="UPDATE $brand $model";
      if (!$dry) {
        $pdo->prepare("UPDATE models SET model_regex=?, max_ram_gb=?, supports_w11=?, storage=?, cpu_arch=?, notes=?, active=?, 
          display_model=? WHERE id=?")->execute([$regexDb, $maxram, $w11, $storage, $cpu, $notes, $active, $model, $id]);
      }
    } else {
      $inserted++;
      $log[]="INSERT $brand $model";
      if (!$dry) {
        $pdo->prepare("INSERT INTO models (brand, display_model, model_regex, max_ram_gb, supports_w11, storage, cpu_arch, notes, active)
          VALUES (?,?,?,?,?,?,?,?,?)")->execute([$brand,$model,$regexDb,$maxram,$w11,$storage,$cpu,$notes,$active]);
      }
    }
  }
  fclose($f);

  out("Verwerkt: $seen regels\nInserts: $inserted\nUpdates: $updated\nDry-run: ".($dry?'JA':'NEE'));
  out(implode("\n",$log));
}
?>
