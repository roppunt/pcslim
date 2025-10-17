<?php
// web/advise.php
declare(strict_types=1);

// CORS & JSON headers (desgewenst aanpassen)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // ── 1) DB-verbinding
    // Verwacht: web/api/conn.php zet een PDO in $pdo (bijv. $pdo = new PDO(...))
require_once __DIR__ . '/api/conn.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection ($pdo) not found in api/conn.php']);
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function buildAdviceResponse(array $row, string $source = 'models'): array
{
    $supports = $row['supports_w11'] ?? null;
    if ($supports === '' || $supports === 'unknown') {
        $supports = null;
    } elseif (is_string($supports)) {
        if ($supports === '1' || strtolower($supports) === 'yes') {
            $supports = 1;
        } elseif ($supports === '0' || strtolower($supports) === 'no') {
            $supports = 0;
        } else {
            $supports = null;
        }
    }

    if ($supports === null || $supports === '') {
        $advice = 'Onzeker: Windows 11 niet bevestigd. Linux als veilig alternatief aanbevelen.';
    } elseif ((int)$supports === 1) {
        $advice = 'Windows 11 mogelijk. Controleer wel RAM/SSD en maak een back-up.';
    } else {
        $advice = 'Windows 11 niet haalbaar → Linux aanbevolen (bijv. Linux Mint of Zorin OS).';
    }

    $hints = [];
    if (!empty($row['ram_installed_gb']) && !empty($row['ram_max_gb'])) {
        $installed = (int)$row['ram_installed_gb'];
        $max       = (int)$row['ram_max_gb'];
        if ($installed < min(8, $max)) {
            $hints[] = "RAM-upgrade aanbevolen (minimaal 8 GB).";
        }
    }
    if (!empty($row['storage_interface']) && stripos((string)$row['storage_interface'], 'NVMe') === false) {
        $hints[] = "Overweeg een SSD-upgrade (liefst NVMe als er M.2 NVMe aanwezig is).";
    }

    return [
        'found'  => true,
        'match'  => [
            'brand'         => $row['brand'] ?? '',
            'display_model' => $row['display_model'] ?? '',
            'year_from'     => $row['year_from'] ?? null,
            'year_to'       => $row['year_to'] ?? null,
            'source'        => $source,
        ],
        'specs'  => [
            'cpu_brand'     => $row['cpu_brand'] ?? null,
            'cpu_family'    => $row['cpu_family'] ?? null,
            'cpu_model'     => $row['cpu_model'] ?? null,
            'cpu_gen'       => $row['cpu_gen'] ?? null,
            'ram_installed' => $row['ram_installed_gb'] ?? null,
            'ram_max'       => $row['ram_max_gb'] ?? null,
            'ram_type'      => $row['ram_type'] ?? null,
            'storage_iface' => $row['storage_interface'] ?? null,
            'storage_type'  => $row['storage_type'] ?? null,
            'storage_bays'  => $row['storage_bays'] ?? null,
            'gpu'           => $row['gpu'] ?? null,
            'tpm'           => $row['tpm'] ?? null,
            'uefi_secureboot' => $row['uefi_secureboot'] ?? null,
        ],
        'supports_w11' => is_null($supports) ? null : (int)$supports,
        'advice'       => $advice,
        'upgrade_hints'=> $hints,
    ];
}

    // ── 2) Input lezen
    // Voorbeeld: /advise.php?brand=HP&model=ProBook%20645%20G2
    $brand = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
    $model = isset($_GET['model']) ? trim((string)$_GET['model']) : '';
    if ($brand === '' || $model === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters: brand and model']);
        exit;
    }

    // Optioneel: strengere matching (exact display_model) via ?strict=1
    $strict = isset($_GET['strict']) && $_GET['strict'] === '1';

    // ── 3) Zoeken in models
    // Normaliter: brand = ?  EN  'modelstring' REGEXP model_regex
    // NB: we matchen de GEBRUIKERS-input (modelstring) tegen de opgeslagen regex.
    if ($strict) {
        // strikt: match op brand + exact display_model
        $sql = "SELECT *
                FROM models
                WHERE brand = :brand
                  AND display_model = :display_model
                ORDER BY COALESCE(updated_at, last_enriched_at, created_at) DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':brand' => $brand,
            ':display_model' => $model,
        ]);
    } else {
        // flexibel: brand + REGEXP op model_regex
        $sql = "SELECT *
                FROM models
                WHERE brand = :brand
                  AND :modelstr REGEXP model_regex
                ORDER BY COALESCE(updated_at, last_enriched_at, created_at) DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':brand'    => $brand,
            ':modelstr' => $model,
        ]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // fallback naar models_ai_stage
        if ($strict) {
            $stageSql = "SELECT *
                         FROM models_ai_stage
                         WHERE brand = :brand
                           AND display_model = :display_model
                         ORDER BY created_at DESC
                         LIMIT 1";
            $stageStmt = $pdo->prepare($stageSql);
            $stageStmt->execute([
                ':brand' => $brand,
                ':display_model' => $model,
            ]);
        } else {
            $stageSql = "SELECT *
                         FROM models_ai_stage
                         WHERE brand = :brand
                           AND :modelstr REGEXP model_regex
                         ORDER BY created_at DESC
                         LIMIT 1";
            $stageStmt = $pdo->prepare($stageSql);
            $stageStmt->execute([
                ':brand' => $brand,
                ':modelstr' => $model,
            ]);
        }

        $stageRow = $stageStmt->fetch(PDO::FETCH_ASSOC);
        if ($stageRow) {
            $normalized = [
                'brand'             => $stageRow['brand'] ?? $brand,
                'display_model'     => $stageRow['display_model'] ?? $model,
                'year_from'         => $stageRow['year_from'] ?? null,
                'year_to'           => $stageRow['year_to'] ?? null,
                'cpu_brand'         => $stageRow['cpu_brand'] ?? null,
                'cpu_family'        => $stageRow['cpu_family'] ?? null,
                'cpu_model'         => $stageRow['cpu_model'] ?? null,
                'cpu_gen'           => $stageRow['cpu_gen'] ?? null,
                'ram_installed_gb'  => $stageRow['ram_installed_gb'] ?? null,
                'ram_max_gb'        => $stageRow['ram_max_gb'] ?? null,
                'ram_type'          => $stageRow['ram_type'] ?? null,
                'storage_type'      => $stageRow['storage_type'] ?? null,
                'storage_interface' => $stageRow['storage_interface'] ?? null,
                'storage_bays'      => $stageRow['storage_bays'] ?? null,
                'gpu'               => $stageRow['gpu'] ?? null,
                'tpm'               => $stageRow['tpm'] ?? null,
                'uefi_secureboot'   => $stageRow['uefi_secureboot'] ?? null,
                'supports_w11'      => ($stageRow['supports_w11'] === '1' ? 1 : ($stageRow['supports_w11'] === '0' ? 0 : null)),
            ];

            echo json_encode(
                buildAdviceResponse($normalized, 'models_ai_stage'),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        // kijk of merk überhaupt in de DB zit
        $brandExists = $pdo->prepare("SELECT 1 FROM models WHERE brand=? LIMIT 1");
        $brandExists->execute([$brand]);
        $brandKnown = (bool)$brandExists->fetchColumn();

        $reason = 'unknown_model';
        $message = 'We hebben dit model nog niet in de database.';
        if (!$brandKnown) {
            $reason = 'unknown_brand';
            $message = 'We herkennen het gekozen merk niet in onze database.';
        } elseif ($strict) {
            $reason = 'no_strict_match';
            $message = 'Geen exacte match gevonden voor dit model.';
        }

        // Suggesties verzamelen ter ondersteuning van de chat/fallback.
        $suggestions = [];
        if ($brandKnown) {
            $suggestStmt = $pdo->prepare(
                "SELECT display_model, cpu_brand, cpu_model, supports_w11
                   FROM models
                  WHERE brand = :brand
                    AND (display_model LIKE :needle OR :input REGEXP model_regex)
                  ORDER BY LENGTH(display_model)
                  LIMIT 6"
            );
            $suggestStmt->execute([
                ':brand' => $brand,
                ':needle' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $model) . '%',
                ':input' => $model,
            ]);
            $suggestions = $suggestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($suggestions) {
                foreach ($suggestions as &$s) {
                    $s['brand'] = $brand;
                }
                unset($s);
            }
        } elseif ($model !== '') {
            $crossStmt = $pdo->prepare(
                "SELECT brand, display_model, supports_w11
                   FROM models
                  WHERE display_model LIKE :needle
                  ORDER BY LENGTH(display_model)
                  LIMIT 6"
            );
            $crossStmt->execute([
                ':needle' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $model) . '%',
            ]);
            $suggestions = $crossStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Stel vervolgvragen (voor seed-formulier) beschikbaar voor de frontend.
        $ask = [
            'brandKnown' => $brandKnown,
            'questions' => [
                ['name' => 'display_model', 'label' => 'Volledige modelnaam (stickertje onderop of systeeminfo)', 'type' => 'text', 'required' => true],
                ['name' => 'year', 'label' => 'Bouwjaar (bij benadering)', 'type' => 'number', 'min' => 2008, 'max' => 2025],
                ['name' => 'cpu', 'label' => 'CPU (bv. Intel Core i5-8250U of AMD Ryzen 5 2500U)', 'type' => 'text'],
                ['name' => 'ram_type', 'label' => 'RAM-type (DDR3/DDR4/LPDDR3/LPDDR4)', 'type' => 'select', 'options' => ['DDR3', 'DDR4', 'LPDDR3', 'LPDDR4', 'onbekend']],
                ['name' => 'storage_interface', 'label' => 'Opslaginterface (2.5\" SATA / M.2 SATA / NVMe / onbekend)', 'type' => 'select', 'options' => ['2.5\" SATA', 'M.2 SATA', 'NVMe', 'onbekend']],
            ],
        ];

        http_response_code(404);
        echo json_encode([
            'found' => false,
            'reason' => $reason,
            'message' => $message,
            'brand_known' => $brandKnown,
            'suggestions' => $suggestions,
            'ask' => $ask,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(
        buildAdviceResponse($row, 'models'),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
