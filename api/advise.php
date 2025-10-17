<?php
// /api/advise.php — diagnostische API met W11/LINUX-logica in Jip-en-Janneke-taal
error_reporting(E_ALL);
ini_set('display_errors', 0); // fouten gaan als JSON terug
header('Content-Type: application/json; charset=utf-8');

function out($arr){
  echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Kern: bouw advies obv DB-rij uit `models` en prijzen uit `prices`.
 * - Windows 10 komt hier NIET in voor.
 * - Eerst bepalen: kan dit systeem naar W11 (evt. met upgrades)? Zo niet → Linux.
 */
function build_advice($m, $prices){
    // ---------- helpers ----------
    $get = fn($k,$d=null)=> (isset($m[$k]) && $m[$k] !== '') ? $m[$k] : $d;
    $has = fn($v,$s)=> stripos((string)$v, $s) !== false;
    $pick = function($keys) use ($prices){
        foreach ($keys as $k) {
            if (isset($prices[$k]) && (int)$prices[$k] > 0) return (int)$prices[$k];
        }
        return 0;
    };
    $eur = fn($c)=> '€ '.number_format($c/100, 0, ',', '.');

    // ---------- feitelijke DB-velden ----------
    $brand    = (string)$get('brand','');
    $model    = (string)$get('display_model','');
    $arch     = (string)$get('cpu_arch','');            // 'x86-32' of 'x86-64'
    $maxram   = (int)$get('max_ram_gb',0);
    $storage  = (string)$get('storage','');             // 'IDE/PATA', 'SATA', 'NVMe', ...
    $w11flag  = $get('supports_w11',null);              // 1/0/NULL
    // onderstaande zijn optioneel; als ze niet bestaan in DB blijven ze 0
    $cpu_gen  = (int)$get('cpu_gen',0);
    $uefi     = (int)$get('uefi_boot',0);
    $tpm2     = (int)$get('tpm_2',0);
    $tpm_up   = (int)$get('tpm_upgradeable',0);

    // ---------- badges (in gewone taal) ----------
    $badges = [];
    if ($maxram) {
        $badges[] = "Geheugen: maximaal {$maxram} GB";
    }
    if ($storage!=='') {
        if (stripos($storage,'IDE')!==false || stripos($storage,'PATA')!==false) {
            $badges[] = "Harde schijf: oud type (IDE)";
        } elseif (stripos($storage,'NVMe')!==false) {
            $badges[] = "Harde schijf: NVMe SSD (zeer snel)";
        } elseif (stripos($storage,'SATA')!==false) {
            $badges[] = "Harde schijf: SATA (moderner)";
        } else {
            $badges[] = "Harde schijf: {$storage}";
        }
    }
    if ($arch!=='') {
        if (stripos($arch,'32')!==false) {
            $badges[] = "Processor: 32-bit (oudere generatie)";
        } else {
            $badges[] = "Processor: 64-bit (geschikt voor nieuwere systemen)";
        }
    }
    if ($cpu_gen)         $badges[] = "CPU-generatie: {$cpu_gen}";
    if ($uefi)            $badges[] = "Moderne opstartmethode aanwezig (UEFI)";
    if ($tpm2 || $tpm_up) $badges[] = $tpm2 ? "Beveiligingschip aanwezig (TPM 2.0)" : "TPM kan worden toegevoegd";

    // ---------- derived flags ----------
    $cpu32  = $has($arch,'32');
    $isIDE  = $has($storage,'IDE') || $has($storage,'PATA');
    $isSATA = $has($storage,'SATA');
    $isNVMe = $has($storage,'NVMe');

    // ---------- prijzen (flexibele keys) ----------
    $p_ssd_500 = $pick(['ssd_500gb','ssd_upgrade','ssd500']);
    $p_ram_8   = $pick(['ram_8gb','ram8','ram_upgrade_8']);
    $p_linux   = $pick(['linux_install','install_linux']);
    $p_w11     = $pick(['win11_upgrade','windows11_upgrade','w11_upgrade']);
    $p_tpm     = $pick(['tpm_module','tpm2_module']);

    $notes = [];
    $os    = ['win11'=>'','linux'=>'']; // Windows 10 bestaat hier niet

    // ---------- knock-outs voor Windows 11 (J&J-taal) ----------
    $hard_no_w11 = false;
    if ($cpu32) { $notes[]='De processor is een oud 32-bit model. Windows 11 werkt hier niet op.'; $hard_no_w11 = true; }
    if ($isIDE) { $notes[]='De harde schijf gebruikt een oud type aansluiting (IDE/PATA). Te oud voor Windows 11.'; $hard_no_w11 = true; }
    if ($maxram>0 && $maxram<8) { $notes[]='Het RAM geheugen kan niet vermeerderd worden naar 8 GB. Dat is het minimale wat nodig is voor Windows 11.'; $hard_no_w11 = true; }
    if ($w11flag===0) { $notes[]='Volgens onze gegevens is dit model niet geschikt voor Windows 11.'; $hard_no_w11 = true; }

    // ---------- basisvoorwaarden voor Windows 11 ----------
    // Onbekende velden blokkeren niet (cpu_gen/uefi/tpm kunnen 0/NULL zijn)
    $meets_gen = ($cpu_gen>=8) || ($w11flag===1) || ($cpu_gen===0 && $w11flag===null);
    $meets_fw = ($w11flag===1)                      // DB zegt expliciet: W11 ok
         || (($uefi===0 && $tpm2===0 && $tpm_up===0)   // firmware onbekend → niet blokkeren
             ? true
             : ($uefi===1 && ($tpm2===1 || $tpm_up===1)));

    $storage_ok= $isSATA || $isNVMe || ($storage==='');

    // ---------- beslisboom ----------
    $headline=''; $intro=''; $costs=[];

    if (!$hard_no_w11 && $meets_gen && $storage_ok && $meets_fw) {
        // ===== Pad A — Windows 11 is mogelijk (evt. met upgrades) =====
        $headline = 'Windows 11 is mogelijk';
        $intro    = 'Met de juiste upgrade(s) kan dit systeem veilig door op Windows 11.';
        $os['win11'] = 'Aan te raden als u bij Windows wilt blijven.';
        $os['linux'] = 'Alternatief: Linux (stabiel en licht).';

        // toon alleen relevante upgrades
        if ($p_ssd_500 && !$isNVMe)     $costs[] = "SSD 500GB v.a. ".$eur($p_ssd_500);
        if ($p_ram_8 && $maxram<8)      $costs[] = "RAM-upgrade naar 8 GB v.a. ".$eur($p_ram_8);
        if ($p_tpm && !$tpm2 && $tpm_up)$costs[] = "TPM-module v.a. ".$eur($p_tpm);
        if ($p_w11)                      $costs[] = "Windows 11 installatie/upgr. v.a. ".$eur($p_w11);

        // Tips in gewone taal
        if ($isSATA) $notes[]='Er zit een moderne SATA-aansluiting in. Met een SSD harde schijf wordt de laptop voelbaar sneller.';
        if ($isNVMe) $notes[]='Ondersteunt NVMe: dat is zeer snelle opslag voor de beste prestaties.';

    } else {
        // ===== Pad B — Windows 11 niet haalbaar → Linux =====
        $headline = 'Beter naar Linux';
        $intro    = 'Windows 11 is hier niet haalbaar. Linux is veilig en snel voor dagelijks gebruik.';
        // Begrijpelijke formulering (geen "distro")
        $os['linux'] = $cpu32
            ? 'Gebruik een lichte Linux-versie die 32-bit ondersteunt, zoals antiX of MX (32-bit).'
            : 'Aanbevolen: Zorin OS (Lite) of een lichte Ubuntu-variant.';

        // upgrades zinvol voor Linux: alleen tonen als het géén oud IDE/PATA-systeem is
        if (!$isIDE && $p_ssd_500) $costs[] = "SSD 500GB v.a. ".$eur($p_ssd_500);
        if ($p_linux)              $costs[] = "Linux-installatie v.a. ".$eur($p_linux);

        // GEEN IDE→SATA-advies meer toevoegen (bewust weggelaten)
        if ($isSATA) $notes[]='Deze laptop heeft een SATA-aansluiting. Met een SSD wordt Linux merkbaar sneller.';
        if ($isNVMe) $notes[]='Ondersteunt NVMe (zeer snelle opslag). Linux werkt hierop uitstekend.';
    }

    if (!empty($m['notes'])) $notes[] = trim((string)$m['notes']);

    return [
        'headline'     => $headline,
        'intro'        => $intro,
        'badges'       => array_values(array_unique($badges)),
        'os'           => $os,                            // alleen win11 + linux
        'what_we_see'  => array_values(array_unique($notes)),
        'costs'        => $costs
    ];
}

try {
  // 0) Ping
  if (isset($_GET['ping'])) out(['ok'=>true,'where'=>'/api/advise.php']);

  // 1) DB-verbinding
  require __DIR__.'/conn.php'; // moet $pdo aanmaken; geen output!

  // 2) Parameters
  $brand = isset($_GET['brand']) ? trim($_GET['brand']) : null;
  $model = isset($_GET['model']) ? trim($_GET['model']) : null;
  $q     = isset($_GET['q'])     ? trim($_GET['q'])     : null;

  if ((!$brand || !$model) && !$q) {
    out(['ok'=>false,'error'=>'Parameters ontbreken (brand+model of q).']);
  }

  // 3) Prijzen ophalen
  $prices = [];
  try {
    $st = $pdo->query("SELECT pkey, value_cents FROM prices");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
      $prices[$p['pkey']] = (int)$p['value_cents'];
    }
  } catch(Throwable $e) {
    $prices = []; // prijzen optioneel
  }

  // 4) Model zoeken
  $row = null;
  if ($brand && $model) {
    // exacte match
    $st = $pdo->prepare("SELECT * FROM models WHERE active=1 AND brand=? AND display_model=? LIMIT 1");
    $st->execute([$brand, $model]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    // fallback: LIKE
    if (!$row) {
      $st = $pdo->prepare("SELECT * FROM models WHERE active=1 AND brand=? AND display_model LIKE ? ORDER BY id DESC LIMIT 1");
      $st->execute([$brand, "%$model%"]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
    }
  }
  if (!$row && $q) {
    // losse zoekterm
    $st = $pdo->prepare("SELECT * FROM models WHERE active=1 AND CONCAT(brand,' ',display_model) LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute(["%$q%"]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }
  if (!$row) out(['ok'=>false,'error'=>'Model niet gevonden in database.']);

  // 5) Advies bouwen
  $advice = build_advice($row, $prices);

  // 6) Output
  out([
    'ok'    => true,
    'model' => [
      'brand'          => (string)$row['brand'],
      'display_model'  => (string)$row['display_model'],
      'cpu_arch'       => isset($row['cpu_arch']) ? (string)$row['cpu_arch'] : null,
      'storage'        => isset($row['storage']) ? (string)$row['storage'] : null,
      'max_ram_gb'     => isset($row['max_ram_gb']) ? (int)$row['max_ram_gb'] : null,
      'supports_w11'   => isset($row['supports_w11']) ? (int)$row['supports_w11'] : null,
    ],
    'advice'=> $advice
  ]);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'Fatal: '.$e->getMessage()]);
}
