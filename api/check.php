<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/conn.php';   // of ../conn.php

// JSON body lezen
$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];

$brand        = trim($in['brand']        ?? '');
$model        = trim($in['model']        ?? '');
$ram          = (int)($in['ram']         ?? 0);
$storage_type = trim($in['storage_type'] ?? '');
$storage_gb   = (int)($in['storage_gb']  ?? 0);
$cpu_year     = trim($in['cpu_year']     ?? '');
$use          = trim($in['use']          ?? 'basis');

if ($brand === '' && $model === '') {
  echo json_encode(['ok'=>false,'error'=>'Onvoldoende invoer']); exit;
}

function norm($s){ return mb_strtolower(trim($s)); }

try {
  // 1) Kandidaten op merk ophalen
  $stmt = $pdo->prepare("SELECT * FROM models WHERE active=1 AND brand LIKE ? LIMIT 200");
  $stmt->execute([$brand !== '' ? $brand : '%']);
  $rows = $stmt->fetchAll();

  $picked = null;

  // 2) Proberen regex uit DB te matchen
  if ($model !== '') {
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
        // preg delimiters + case-insensitive
        $rx = '/'.$pat.'/i';
        if (@preg_match($rx, $mnorm)) {
          if (preg_match($rx, $mnorm)) { $picked = $r; break 2; }
        }
      }
    }
  }

  // 3) Fallback: LIKE op display_model
  if (!$picked && $model !== '') {
    $stmt2 = $pdo->prepare("SELECT * FROM models 
                            WHERE active=1 AND brand LIKE ? AND display_model LIKE ? 
                            ORDER BY id DESC LIMIT 1");
    $stmt2->execute([$brand, "%$model%"]);
    $picked = $stmt2->fetch() ?: null;
  }

  // 4) Helemaal niets gevonden → neutraal advies
  if (!$picked) {
    echo json_encode([
      'ok'=>true,
      'badge'=>['tone'=>'warn','text'=>'Geen directe match gevonden'],
      'title'=>'Algemeen advies',
      'summary'=>'We konden dit model niet in onze database vinden. Wel kunnen we op basis van jouw gegevens een veilig advies geven.',
      'kpis'=>array_filter([
        $brand ? "Merk: $brand" : null,
        $model ? "Model: $model" : null,
        $ram   ? "RAM: {$ram}GB" : null,
        $storage_type ? "Opslag: $storage_type" : null,
      ]),
      'tips'=>[
        'Vul het exacte modelnummer in (bijv. “ProBook 450 G5” of “Inspiron 5570”).',
        'We kunnen het apparaat ook gratis checken op afstand.'
      ],
      'actions'=>['Plan een korte intake, dan checken wij het exacte type.'],
      'prices'=>['Linux-installatie v.a. € 79', 'Windows 11 upgrade v.a. € 59']
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 5) Advies op basis van gevonden rij
  $w11     = $picked['supports_w11'] ?? null; // 1|0|null
  $maxRam  = (int)($picked['max_ram_gb'] ?? 0);
  $storage = $picked['storage'] ?? '';
  $cpuArch = $picked['cpu_arch'] ?? '';

  $issues = [];
  $tips   = [];
  $kpis   = [];

  if ($w11 === '1') {
    $kpis[] = 'Windows 11: mogelijk';
  } elseif ($w11 === '0') {
    $kpis[] = 'Windows 11: niet mogelijk';
    $issues[] = 'Dit model ondersteunt Windows 11 niet officieel.';
    $tips[] = 'Overweeg Linux: veilig, snel en geschikt voor dagelijks gebruik.';
  } else {
    $kpis[] = 'Windows 11: onbekend';
  }

  if ($maxRam) {
    $kpis[] = "Max RAM: {$maxRam}GB";
    if ($ram && $maxRam > $ram) $tips[] = "Uitbreiden naar {$maxRam}GB RAM geeft merkbaar verschil.";
  }

  if ($storage) $kpis[] = "Opslag-bay: $storage";
  if ($storage_type === 'HDD') {
    $issues[] = 'HDD is traag voor hedendaags gebruik.';
    $tips[]   = 'Upgrade naar SSD (500GB is meestal voldoende).';
  }

  if ($cpuArch) $kpis[] = "CPU-arch: $cpuArch";

  $badgeTone = ($w11 === '1') ? 'ok' : (($w11 === '0') ? 'bad' : 'warn');
  $badgeText = ($w11 === '1') ? 'Goed nieuws – Windows 11 kan' : (($w11 === '0') ? 'Beter naar Linux' : 'Nog even checken');

  $summary = ($w11 === '1')
    ? 'Dit model kan Windows 11 aan. Met een SSD en voldoende RAM voelt het weer snel.'
    : (($w11 === '0')
      ? 'Windows 11 is hier niet aan te raden. Linux is een veilige en snelle keuze, zeker met een SSD.'
      : 'We kunnen dit model mogelijk geschikt maken, maar controleren het graag even.');

  $prices = ['SSD 500GB v.a. € 39','Linux-installatie v.a. € 79','Windows 11 upgrade v.a. € 59'];

  echo json_encode([
    'ok'=>true,
    'badge'=>['tone'=>$badgeTone,'text'=>$badgeText],
    'title'=>$picked['brand'].' '.$picked['display_model'],
    'summary'=>$summary,
    'kpis'=>$kpis,
    'issues'=>$issues,
    'tips'=>$tips,
    'actions'=>['Plan een afspraak of doe een snelle remote check.'],
    'prices'=>$prices,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server-fout']);
}
