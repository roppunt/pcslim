<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/../lib/Advice.php';

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pdo_boot(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (function_exists('pdo')) return pdo();

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'pcslim';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $dsn  = "mysql:host=$host;dbname=$name;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** Proxy naar chat_assist.php bij geen match */
function proxy_chat_assist(string $brand, string $model): array {
    $url = getenv('CHAT_ASSIST_URL');
    if (!$url) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/advise.php'), '/');
        $url    = $scheme . '://' . $host . $base . '/chat_assist.php';
    }

    $payload = [
        'message' => trim($brand . ' ' . $model),
        'intent'  => 'spec_lookup_and_advice'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = $res === false ? curl_error($ch) : null;
    curl_close($ch);

    if ($res === false || $code < 200 || $code >= 300) {
        return [
            'found'  => false,
            'error'  => 'Fallback via chat_assist mislukt',
            'detail' => $err ?: ('HTTP '.$code),
        ];
    }

    $json = json_decode($res, true);
    if (!is_array($json)) {
        return ['found'=>false,'error'=>'Ongeldig antwoord van chat_assist'];
    }

    $json['proxied_from_chat_assist'] = true;
    return $json;
}

try {
    $brand = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
    $model = isset($_GET['model']) ? trim((string)$_GET['model']) : '';
    if ($brand === '' || $model === '') {
        json_out(['found'=>false,'error'=>'Ontbrekende parameters (?brand=&model=)'], 400);
    }

    $pdo = pdo_boot();

    // 1️⃣ Zoek in models
    $sql = "SELECT * FROM models
            WHERE brand=:b AND (display_model=:m OR display_model LIKE :l)
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':b'=>$brand, ':m'=>$model, ':l'=>'%'.$model.'%']);
    $row = $stmt->fetch();

    // 2️⃣ Geen match? → kijk in pcslim_models_seed (AI-wachtkamer)
    if (!$row) {
        $seed = $pdo->prepare("SELECT * FROM pcslim_models_seed
                               WHERE brand=:b AND (display_model=:m OR display_model LIKE :l)
                               ORDER BY created_at DESC LIMIT 1");
        $seed->execute([':b'=>$brand, ':m'=>$model, ':l'=>'%'.$model.'%']);
        $seedRow = $seed->fetch();

        if ($seedRow) {
            $adv = Advice::build($seedRow);
            json_out([
                'found'                => true,
                'supports_w11'         => $adv['supports_w11_value'],
                'pcslim_label'         => $adv['pcslim_label'],
                'requires_ram_upgrade' => (bool)$adv['requires_ram_upgrade'],
                'advice'               => $adv['advice_text'],
                'upgrade_hints'        => Advice::storageUpgradeTips($seedRow),
                'specs'                => [
                    'brand'            => $seedRow['brand'],
                    'display_model'    => $seedRow['display_model'],
                    'cpu_brand'        => $seedRow['cpu_brand'] ?? null,
                    'cpu_family'       => $seedRow['cpu_family'] ?? null,
                    'cpu_model'        => $seedRow['cpu_model'] ?? null,
                    'cpu_gen'          => $seedRow['cpu_gen'] ?? null,
                    'ram_installed_gb' => $seedRow['ram_installed_gb'] ?? null,
                    'ram_max_gb'       => $seedRow['ram_max_gb'] ?? null,
                    'ram_type'         => $seedRow['ram_type'] ?? null,
                    'ram_slots'        => $seedRow['ram_slots'] ?? null,
                    'storage_type'     => $seedRow['storage_type'] ?? null,
                    'storage_interface'=> $seedRow['storage_interface'] ?? null,
                    'storage_bays'     => $seedRow['storage_bays'] ?? null,
                    'tpm'              => $seedRow['tpm'] ?? null,
                    'uefi_secureboot'  => $seedRow['uefi_secureboot'] ?? null,
                ],
                'source'               => 'pcslim_models_seed',
                'notice'               => 'Voorlopige AI-gegevens, nog niet gevalideerd.',
                'cta_chat'             => [
                    'enabled' => true,
                    'text'    => 'Heeft u nog vragen? Ik help graag verder in de chat.',
                ],
            ]);
        }
    }

    // 3️⃣ Nog steeds niks? → AI-fallback
    if (!$row) {
        $fallback = proxy_chat_assist($brand, $model);

        // Filter NVMe uit AI-hints
        $rawHints = (array)($fallback['upgrade_hints'] ?? []);
        $cleanHints = array_values(array_filter($rawHints, fn($h)=>!preg_match('/\bnvme\b|m\.?2/i',(string)$h)));

        $resp = [
            'found'                => (bool)($fallback['found'] ?? true),
            'supports_w11'         => $fallback['supports_w11'] ?? null,
            'pcslim_label'         => $fallback['pcslim_label'] ?? null,
            'requires_ram_upgrade' => (bool)($fallback['requires_ram_upgrade'] ?? false),
            'advice'               => $fallback['advice'] ?? ($fallback['message'] ?? 'Voorlopig advies op basis van AI.'),
            'specs'                => $fallback['specs'] ?? ['brand'=>$brand,'display_model'=>$model],
            'upgrade_hints'        => $cleanHints ?: Advice::storageUpgradeTips($fallback['specs'] ?? []),
            'source'               => 'ai-fallback',
            'cta_chat'             => [
                'enabled' => true,
                'text'    => 'Heeft u nog vragen? Ik help graag verder in de chat.',
            ],
        ];

        json_out($resp, isset($fallback['error']) ? 502 : 200);
    }

    // 4️⃣ Match in models → bouw advies en update supports_w11
    $adv = Advice::build($row);

    if ($adv['supports_w11_value'] !== null) {
        $newVal = (int)$adv['supports_w11_value'];
        $curVal = $row['supports_w11'];
        if ($curVal === null || (int)$curVal !== $newVal) {
            $upd = $pdo->prepare("UPDATE models SET supports_w11=:v, updated_at=NOW() WHERE id=:id");
            $upd->execute([':v'=>$newVal, ':id'=>(int)$row['id']]);
        }
    }

    $response = [
        'found'                => true,
        'supports_w11'         => $adv['supports_w11_value'],
        'pcslim_label'         => $adv['pcslim_label'],
        'requires_ram_upgrade' => (bool)$adv['requires_ram_upgrade'],
        'advice'               => $adv['advice_text'],
        'upgrade_hints'        => Advice::storageUpgradeTips($row),
        'specs'                => [
            'brand'            => $row['brand'],
            'display_model'    => $row['display_model'],
            'cpu_brand'        => $row['cpu_brand'] ?? null,
            'cpu_family'       => $row['cpu_family'] ?? null,
            'cpu_model'        => $row['cpu_model'] ?? null,
            'cpu_gen'          => $row['cpu_gen'] ?? null,
            'ram_installed_gb' => $row['ram_installed_gb'] ?? null,
            'ram_max_gb'       => $row['ram_max_gb'] ?? null,
            'ram_type'         => $row['ram_type'] ?? null,
            'ram_slots'        => $row['ram_slots'] ?? null,
            'storage_type'     => $row['storage_type'] ?? null,
            'storage_interface'=> $row['storage_interface'] ?? null,
            'storage_bays'     => $row['storage_bays'] ?? null,
            'tpm'              => $row['tpm'] ?? null,
            'uefi_secureboot'  => $row['uefi_secureboot'] ?? null,
        ],
        'source'               => 'models',
    ];

    json_out($response);

} catch (Throwable $e) {
    json_out(['found'=>false,'error'=>'Interne fout','detail'=>$e->getMessage()], 500);
}
