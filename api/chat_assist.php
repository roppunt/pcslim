<?php
declare(strict_types=1);

/**
 * chat_assist.php
 *
 * Backendendpoint dat het gesprek met de supportchat afhandelt wanneer het
 * formulier geen direct advies kan geven. Het verzamelt relevante context
 * uit de database en vraagt (optioneel) een taalmodel om vervolgadvies.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/conn.php';

/**
 * Schrijft een JSON-response en stopt de request.
 */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Vroege exit bij fout.
 */
function fail(string $message, int $status = 400, array $extra = []): void
{
    respond(['ok' => false, 'error' => $message] + $extra, $status);
}

/**
 * Escape helper voor LIKE.
 */
function likePattern(string $value): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $value) . '%';
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: 'null', true);
if (!is_array($payload)) {
    fail('Ongeldige JSON body');
}

$messages = $payload['messages'] ?? null;
if (!is_array($messages) || $messages === []) {
    fail('messages (array) is verplicht');
}

// Filter en normaliseer inkomende berichten
$normalizedMessages = [];
foreach ($messages as $msg) {
    if (!is_array($msg)) {
        continue;
    }
    $role = $msg['role'] ?? '';
    $content = $msg['content'] ?? '';
    if (!is_string($role) || !is_string($content)) {
        continue;
    }
    $role = strtolower(trim($role));
    if (!in_array($role, ['system', 'user', 'assistant'], true)) {
        continue;
    }
    $normalizedMessages[] = [
        'role' => $role,
        'content' => trim($content),
    ];
}

if ($normalizedMessages === []) {
    fail('Geen geldige berichten ontvangen');
}

$brandInput = trim((string)($payload['brand'] ?? ''));
$modelInput = trim((string)($payload['model'] ?? ''));
$reason = trim((string)($payload['reason'] ?? ''));
$lastError = trim((string)($payload['last_error'] ?? ''));

// Verzamel context uit de database
$context = [
    'brand_input' => $brandInput,
    'model_input' => $modelInput,
    'reason' => $reason,
    'last_error' => $lastError,
    'brand_known' => false,
    'brand_suggestions' => [],
    'brand_stats' => null,
    'models_for_brand' => [],
    'cross_brand_matches' => [],
];

// 1) Controleer of het merk bestaat
if ($brandInput !== '') {
    $brandCheck = $pdo->prepare('SELECT COUNT(*) FROM models WHERE brand = :brand LIMIT 1');
    $brandCheck->execute([':brand' => $brandInput]);
    $context['brand_known'] = (bool)$brandCheck->fetchColumn();

    if (!$context['brand_known']) {
        $brandSuggest = $pdo->prepare(
            'SELECT DISTINCT brand
               FROM models
              WHERE brand LIKE :needle
              ORDER BY brand ASC
              LIMIT 6'
        );
        $brandSuggest->execute([':needle' => likePattern($brandInput)]);
        $context['brand_suggestions'] = $brandSuggest->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } else {
        // verzamel simpele statistiek voor bekende merken
        $brandStats = $pdo->prepare(
            'SELECT COUNT(*) AS total_models,
                    MIN(year_from) AS oldest,
                    MAX(year_to) AS newest
               FROM models
              WHERE brand = :brand'
        );
        $brandStats->execute([':brand' => $brandInput]);
        $context['brand_stats'] = $brandStats->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// 2) Zoek modellen binnen het merk
if ($context['brand_known']) {
    $args = [':brand' => $brandInput];
    $querySuffix = '';
    if ($modelInput !== '') {
        $querySuffix = ' AND (display_model LIKE :needle OR :userModel REGEXP model_regex)';
        $args[':needle'] = likePattern($modelInput);
        $args[':userModel'] = $modelInput;
    }
    $brandModels = $pdo->prepare(
        "SELECT display_model, cpu_brand, cpu_model, cpu_gen,
                ram_installed_gb, ram_max_gb, ram_type,
                storage_interface, gpu, supports_w11
           FROM models
          WHERE brand = :brand
          $querySuffix
          ORDER BY
              CASE WHEN display_model LIKE :prefix THEN 0 ELSE 1 END,
              LENGTH(display_model)
          LIMIT 8"
    );
    $brandModels->bindValue(':brand', $brandInput);
    if ($modelInput !== '') {
        $brandModels->bindValue(':needle', likePattern($modelInput));
        $brandModels->bindValue(':userModel', $modelInput);
        $brandModels->bindValue(':prefix', $modelInput . '%');
    } else {
        $brandModels->bindValue(':prefix', '');
    }
    $brandModels->execute();
    $context['models_for_brand'] = $brandModels->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($modelInput !== '') {
    // 3) Kruismerk-zoektocht op modelnaam
    $cross = $pdo->prepare(
        'SELECT brand, display_model, supports_w11,
                cpu_brand, cpu_model, cpu_gen,
                ram_installed_gb, ram_max_gb, storage_interface
           FROM models
          WHERE display_model LIKE :needle
          ORDER BY LENGTH(display_model)
          LIMIT 6'
    );
    $cross->execute([':needle' => likePattern($modelInput)]);
    $context['cross_brand_matches'] = $cross->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Bouw een contextstring voor het taalmodel.
 */
function renderContextSummary(array $ctx): string
{
    $lines = [];
    if ($ctx['brand_input'] !== '') {
        $lines[] = "Ingevoerde merk: {$ctx['brand_input']} (bekend: " . ($ctx['brand_known'] ? 'ja' : 'nee') . ")";
    }
    if (!$ctx['brand_known'] && $ctx['brand_suggestions']) {
        $lines[] = 'Mogelijke merken: ' . implode(', ', $ctx['brand_suggestions']);
    }
    if ($ctx['brand_stats']) {
        $lines[] = sprintf(
            'Merkstatistiek: %d modellen (bouwjaren %s-%s).',
            (int)($ctx['brand_stats']['total_models'] ?? 0),
            $ctx['brand_stats']['oldest'] ?? '?',
            $ctx['brand_stats']['newest'] ?? '?'
        );
    }
    if ($ctx['models_for_brand']) {
        $lines[] = 'Modellen bij dit merk:';
        foreach ($ctx['models_for_brand'] as $row) {
            $lines[] = sprintf(
                '- %s | CPU: %s %s (%s) | RAM: %s / %s GB %s | Opslag: %s | Windows 11: %s',
                $row['display_model'],
                $row['cpu_brand'] ?? '?',
                $row['cpu_model'] ?? '',
                $row['cpu_gen'] ? 'gen ' . $row['cpu_gen'] : 'gen onbekend',
                $row['ram_installed_gb'] ?? '?',
                $row['ram_max_gb'] ?? '?',
                $row['ram_type'] ?? '',
                $row['storage_interface'] ?? '?',
                is_null($row['supports_w11']) ? 'onbekend' : ((int)$row['supports_w11'] === 1 ? 'ja' : 'nee')
            );
        }
    } elseif ($ctx['cross_brand_matches']) {
        $lines[] = 'Modellen met vergelijkbare naam:';
        foreach ($ctx['cross_brand_matches'] as $row) {
            $lines[] = sprintf(
                '- %s %s | CPU: %s %s (%s) | Windows 11: %s',
                $row['brand'],
                $row['display_model'],
                $row['cpu_brand'] ?? '?',
                $row['cpu_model'] ?? '',
                $row['cpu_gen'] ? 'gen ' . $row['cpu_gen'] : 'gen onbekend',
                is_null($row['supports_w11']) ? 'onbekend' : ((int)$row['supports_w11'] === 1 ? 'ja' : 'nee')
            );
        }
    }
    if ($ctx['last_error'] !== '') {
        $lines[] = 'Laatste foutmelding van het formulier: ' . $ctx['last_error'];
    }
    if ($ctx['reason'] !== '') {
        $lines[] = 'Aanleiding: ' . $ctx['reason'];
    }
    return implode("\n", $lines);
}

/**
 * Filtert directive-parameters uit een string zoals brand="HP" strict=1.
 *
 * @return array<string,string>
 */
function parseDirectiveParams(string $body): array
{
    $params = [];
    if (preg_match_all('/(\w+)=("([^"]*)"|\'([^\']*)\'|[^\s]+)/', $body, $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $pair) {
            $key = strtolower($pair[1]);
            if (isset($pair[3]) && $pair[3] !== '') {
                $val = $pair[3];
            } elseif (isset($pair[4]) && $pair[4] !== '') {
                $val = $pair[4];
            } else {
                $val = $pair[2] ?? '';
            }
            $params[$key] = trim($val, "\"'");
        }
    }
    return $params;
}

function normalizeBoolean($value): ?int
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    $v = strtolower(trim((string)$value));
    if ($v === '') {
        return null;
    }
    if (in_array($v, ['1', 'true', 'yes', 'y', 'ja'], true)) {
        return 1;
    }
    if (in_array($v, ['0', 'false', 'no', 'n', 'nee'], true)) {
        return 0;
    }
    return null;
}

function normalizeSupports($value): ?int
{
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        return $value === 0 ? 0 : ($value === 1 ? 1 : null);
    }
    $v = strtolower(trim((string)$value));
    if ($v === '') {
        return null;
    }
    if (in_array($v, ['1', 'true', 'yes', 'ja', 'supported', 'compatible', 'w11'], true)) {
        return 1;
    }
    if (in_array($v, ['0', 'false', 'no', 'nee', 'unsupported', 'not_supported'], true)) {
        return 0;
    }
    return null;
}

function normalizeSupportsStage($value): string
{
    $mapped = normalizeSupports($value);
    if ($mapped === 1) {
        return '1';
    }
    if ($mapped === 0) {
        return '0';
    }
    return 'unknown';
}

function parseIntOrNull($value): ?int
{
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return null;
    }
    return (int)round((float)$v);
}

function normalizeYesNoUnknown($value): ?string
{
    if ($value === null) {
        return null;
    }
    $v = strtolower(trim((string)$value));
    if ($v === '') {
        return null;
    }
    if (in_array($v, ['yes', 'ja', 'true', '1'], true)) {
        return 'yes';
    }
    if (in_array($v, ['no', 'nee', 'false', '0'], true)) {
        return 'no';
    }
    return 'unknown';
}

function parseYearRange(?string $value): array
{
    $yearFrom = null;
    $yearTo   = null;
    if ($value !== null) {
        $clean = trim($value);
        if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $clean, $m)) {
            $yearFrom = (int)$m[1];
            $yearTo   = (int)$m[2];
        } elseif (preg_match('/^\d{4}$/', $clean)) {
            $yearFrom = (int)$clean;
            $yearTo   = $yearFrom;
        }
    }
    return [$yearFrom, $yearTo];
}

function makeModelRegex(string $displayModel): string
{
    $escaped = preg_quote($displayModel, '/');
    return '(?i)^' . $escaped . '(\\b|[^A-Za-z0-9_].*)';
}

/**
 * Bouwt dezelfde adviesstructuur als advise.php uit een DB-rij.
 */
function hydrateAdviceFromRow(array $row): array
{
    $supports = $row['supports_w11'] ?? null;
    if ($supports === '') {
        $supports = null;
    }

    if ($supports === null) {
        $advice = 'Onzeker: Windows 11 niet bevestigd. Linux als veilig alternatief aanbevelen.';
    } elseif ((string)$supports === '1') {
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
        'found' => true,
        'match'  => [
            'brand'         => $row['brand'] ?? '',
            'display_model' => $row['display_model'] ?? '',
            'year_from'     => $row['year_from'] ?? null,
            'year_to'       => $row['year_to'] ?? null,
        ],
        'specs'  => [
            'cpu_brand'       => $row['cpu_brand'] ?? null,
            'cpu_family'      => $row['cpu_family'] ?? null,
            'cpu_model'       => $row['cpu_model'] ?? null,
            'cpu_gen'         => $row['cpu_gen'] ?? null,
            'ram_installed'   => $row['ram_installed_gb'] ?? null,
            'ram_max'         => $row['ram_max_gb'] ?? null,
            'ram_type'        => $row['ram_type'] ?? null,
            'storage_iface'   => $row['storage_interface'] ?? null,
            'gpu'             => $row['gpu'] ?? null,
            'tpm'             => $row['tpm'] ?? null,
            'uefi_secureboot' => $row['uefi_secureboot'] ?? null,
        ],
        'supports_w11' => is_null($supports) ? null : (int)$supports,
        'advice'       => $advice,
        'upgrade_hints'=> $hints,
    ];
}

function hydrateAdviceFromStageRow(array $row): array
{
    $converted = [
        'brand'             => $row['brand'] ?? null,
        'display_model'     => $row['display_model'] ?? null,
        'year_from'         => $row['year_from'] ?? null,
        'year_to'           => $row['year_to'] ?? null,
        'cpu_brand'         => $row['cpu_brand'] ?? null,
        'cpu_family'        => $row['cpu_family'] ?? null,
        'cpu_model'         => $row['cpu_model'] ?? null,
        'cpu_arch'          => $row['cpu_arch'] ?? null,
        'cpu_gen'           => $row['cpu_gen'] ?? null,
        'ram_installed_gb'  => $row['ram_installed_gb'] ?? null,
        'ram_max_gb'        => $row['ram_max_gb'] ?? null,
        'ram_type'          => $row['ram_type'] ?? null,
        'storage_type'      => $row['storage_type'] ?? null,
        'storage_interface' => $row['storage_interface'] ?? null,
        'storage_bays'      => $row['storage_bays'] ?? null,
        'gpu'               => $row['gpu'] ?? null,
        'tpm'               => $row['tpm'] ?? null,
        'uefi_secureboot'   => $row['uefi_secureboot'] ?? null,
        'supports_w11'      => normalizeSupports($row['supports_w11'] ?? null),
    ];

    $advice = hydrateAdviceFromRow($converted);
    $advice['match']['source'] = 'models_ai_stage';
    return $advice;
}

function attemptAdvice(PDO $pdo, string $brand, string $model, bool $strict = false): ?array
{
    if ($brand === '' || $model === '') {
        return null;
    }

    if ($strict) {
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
        return attemptAdviceFromStage($pdo, $brand, $model, $strict);
    }

    $advice = hydrateAdviceFromRow($row);
    $advice['match']['source'] = 'models';
    return $advice;
}

function attemptAdviceFromStage(PDO $pdo, string $brand, string $model, bool $strict = false): ?array
{
    if ($brand === '' || $model === '') {
        return null;
    }

    if ($strict) {
        $sql = "SELECT *
                FROM models_ai_stage
                WHERE brand = :brand
                  AND display_model = :display_model
                ORDER BY created_at DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':brand' => $brand,
            ':display_model' => $model,
        ]);
    } else {
        $sql = "SELECT *
                FROM models_ai_stage
                WHERE brand = :brand
                  AND :modelstr REGEXP model_regex
                ORDER BY created_at DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':brand'    => $brand,
            ':modelstr' => $model,
        ]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return hydrateAdviceFromStageRow($row);
}

function handleSeedDirective(PDO $pdo, array $params): array
{
    $brand = trim((string)($params['brand'] ?? ''));
    $model = trim((string)($params['model'] ?? ''));
    if ($brand === '' || $model === '') {
        return ['ok' => false, 'error' => 'brand en model zijn verplicht voor SEED'];
    }

    $displayModel = trim((string)($params['display_model'] ?? $model));

    // jaarinformatie
    [$yearFromDirective, $yearToDirective] = parseYearRange($params['year'] ?? null);
    $yearFrom = parseIntOrNull($params['year_from'] ?? null) ?? $yearFromDirective;
    $yearTo   = parseIntOrNull($params['year_to'] ?? null) ?? $yearToDirective;
    if ($yearFrom !== null && $yearTo === null) {
        $yearTo = $yearFrom;
    }

    // Seed-gegevens (minimale velden)
    $seedData = [
        'brand' => $brand,
        'model' => $displayModel,
    ];
    if ($yearFrom !== null) {
        $seedData['year'] = $yearFrom;
    }

    $cpuString = $params['cpu'] ?? null;
    if (!$cpuString) {
        $cpuPieces = array_filter([
            $params['cpu_brand'] ?? null,
            $params['cpu_family'] ?? null,
            $params['cpu_model'] ?? null,
        ], fn($v) => $v !== null && trim((string)$v) !== '');
        $cpuString = trim(implode(' ', $cpuPieces));
        if ($cpuString !== '' && isset($params['cpu_gen']) && trim((string)$params['cpu_gen']) !== '') {
            $cpuString .= ' (gen ' . trim((string)$params['cpu_gen']) . ')';
        }
    }
    if ($cpuString && trim($cpuString) !== '') {
        $seedData['cpu'] = trim($cpuString);
    }

    if (!empty($params['ram_type'])) {
        $seedData['ram_type'] = trim((string)$params['ram_type']);
    }
    $ramInstalled = parseIntOrNull($params['ram_installed'] ?? $params['ram_installed_gb'] ?? null);
    if ($ramInstalled !== null) {
        $seedData['ram_installed_gb'] = $ramInstalled;
    }
    $ramMax = parseIntOrNull($params['ram_max'] ?? $params['ram_max_gb'] ?? null);
    if ($ramMax !== null) {
        $seedData['ram_max_gb'] = $ramMax;
    }

    if (!empty($params['storage_type'])) {
        $seedData['storage_type'] = trim((string)$params['storage_type']);
    }
    if (!empty($params['storage_interface'])) {
        $seedData['storage_interface'] = trim((string)$params['storage_interface']);
    }
    if (!empty($params['storage_primary'])) {
        $seedData['storage_primary'] = trim((string)$params['storage_primary']);
    }
    if (!empty($params['storage_secondary'])) {
        $seedData['storage_secondary'] = trim((string)$params['storage_secondary']);
    }

    $hasM2 = normalizeBoolean($params['has_m2'] ?? null);
    if ($hasM2 !== null) {
        $seedData['has_m2'] = $hasM2;
    }
    $hasSata = normalizeBoolean($params['has_2_5_sata'] ?? $params['has_25_sata'] ?? null);
    if ($hasSata !== null) {
        $seedData['has_2_5_sata'] = $hasSata;
    }

    if (!empty($params['gpu'])) {
        $seedData['gpu'] = trim((string)$params['gpu']);
    }
    if (!empty($params['tpm_version'])) {
        $seedData['tpm_version'] = trim((string)$params['tpm_version']);
    } elseif (!empty($params['tpm'])) {
        $seedData['tpm_version'] = trim((string)$params['tpm']);
    }
    if (!empty($params['secure_boot'])) {
        $seedData['secure_boot'] = trim((string)$params['secure_boot']);
    }
    if (!empty($params['uefi'])) {
        $seedData['uefi'] = trim((string)$params['uefi']);
    }
    if (!empty($params['source'])) {
        $seedData['source_url'] = trim((string)$params['source']);
    }
    if (!empty($params['notes'])) {
        $seedData['notes_raw'] = trim((string)$params['notes']);
    }

    // filter nulls behalve keys die verplicht zijn
    foreach ($seedData as $key => $value) {
        if (($key === 'brand' || $key === 'model') && $value === '') {
            continue;
        }
        if ($value === null) {
            unset($seedData[$key]);
        }
    }

    // insert/update pcslim_models_seed
    $seedColumns = array_keys($seedData);
    $seedPlaceholders = array_map(fn($c) => ':seed_' . $c, $seedColumns);
    $seedUpdates = array_filter($seedColumns, fn($c) => !in_array($c, ['brand', 'model'], true));
    $seedUpdateClause = $seedUpdates
        ? implode(',', array_map(fn($c) => "`$c` = VALUES(`$c`)", $seedUpdates))
        : "`model` = VALUES(`model`)";

    $sqlSeed = "INSERT INTO pcslim_models_seed (" . implode(',', array_map(fn($c) => "`$c`", $seedColumns)) . ")
                VALUES (" . implode(',', $seedPlaceholders) . ")
                ON DUPLICATE KEY UPDATE $seedUpdateClause";
    $stmtSeed = $pdo->prepare($sqlSeed);
    foreach ($seedColumns as $col) {
        $value = $seedData[$col];
        if (($col === 'has_m2' || $col === 'has_2_5_sata') && $value !== null) {
            $value = (int)$value;
        }
        $stmtSeed->bindValue(':seed_' . $col, $value);
    }
    $stmtSeed->execute();

    $seedId = (int)$pdo->lastInsertId();
    if ($seedId === 0) {
        $stmtId = $pdo->prepare("SELECT id FROM pcslim_models_seed WHERE brand = :brand AND model = :model LIMIT 1");
        $stmtId->execute([':brand' => $brand, ':model' => $displayModel]);
        $seedId = (int)$stmtId->fetchColumn();
    }

    // stage data voor directe adviezen
    $stageData = [
        'seed_id'       => $seedId,
        'brand'         => $brand,
        'display_model' => $displayModel,
        'model_regex'   => $params['model_regex'] ?? makeModelRegex($displayModel),
    ];
    if ($yearFrom !== null) {
        $stageData['year_from'] = $yearFrom;
    }
    if ($yearTo !== null) {
        $stageData['year_to'] = $yearTo;
    }

    foreach (['cpu_brand','cpu_family','cpu_model','cpu_arch','ram_type','storage_type','storage_interface','storage_bays','gpu','notes'] as $key) {
        if (!empty($params[$key])) {
            $stageData[$key] = trim((string)$params[$key]);
        }
    }
    $cpuGen = parseIntOrNull($params['cpu_gen'] ?? null);
    if ($cpuGen !== null) {
        $stageData['cpu_gen'] = $cpuGen;
    }
    $ramInstalled = parseIntOrNull($params['ram_installed'] ?? $params['ram_installed_gb'] ?? null);
    if ($ramInstalled !== null) {
        $stageData['ram_installed_gb'] = $ramInstalled;
    }
    $ramMax = parseIntOrNull($params['ram_max'] ?? $params['ram_max_gb'] ?? null);
    if ($ramMax !== null) {
        $stageData['ram_max_gb'] = $ramMax;
    }
    $ramSlots = parseIntOrNull($params['ram_slots'] ?? null);
    if ($ramSlots !== null) {
        $stageData['ram_slots'] = $ramSlots;
    }
    $ramSoldered = parseIntOrNull($params['ram_soldered'] ?? $params['ram_soldered_gb'] ?? null);
    if ($ramSoldered !== null) {
        $stageData['ram_soldered_gb'] = $ramSoldered;
    }
    $storageInstalled = parseIntOrNull($params['storage_installed'] ?? $params['storage_installed_gb'] ?? null);
    if ($storageInstalled !== null) {
        $stageData['storage_installed_gb'] = $storageInstalled;
    }

    $stageData['tpm'] = $params['tpm'] ?? $params['tpm_version'] ?? null;
    if ($stageData['tpm'] !== null) {
        $stageData['tpm'] = normalizeYesNoUnknown($stageData['tpm']);
    }
    $stageData['uefi_secureboot'] = $params['secure_boot'] ?? null;
    if ($stageData['uefi_secureboot'] !== null) {
        $stageData['uefi_secureboot'] = normalizeYesNoUnknown($stageData['uefi_secureboot']);
    }
    $stageData['supports_w11'] = normalizeSupportsStage($params['supports_w11'] ?? null);
    if (!empty($params['notes'])) {
        $stageData['notes'] = trim((string)$params['notes']);
    }
    if (!empty($params['explain_ai'])) {
        $stageData['explain_ai'] = trim((string)$params['explain_ai']);
    }

    foreach ($stageData as $key => $value) {
        if ($value === null) {
            unset($stageData[$key]);
        }
    }

    $stageColumns = array_keys($stageData);
    $stagePlaceholders = array_map(fn($c) => ':stage_' . $c, $stageColumns);
    $stageUpdates = array_filter($stageColumns, fn($c) => $c !== 'seed_id');
    $stageUpdateClause = $stageUpdates
        ? implode(',', array_map(fn($c) => "`$c` = VALUES(`$c`)", $stageUpdates))
        : "`seed_id` = VALUES(`seed_id`)";

    $sqlStage = "INSERT INTO models_ai_stage (" . implode(',', array_map(fn($c) => "`$c`", $stageColumns)) . ")
                 VALUES (" . implode(',', $stagePlaceholders) . ")
                 ON DUPLICATE KEY UPDATE $stageUpdateClause";
    $stmtStage = $pdo->prepare($sqlStage);
    foreach ($stageColumns as $col) {
        $stmtStage->bindValue(':stage_' . $col, $stageData[$col]);
    }
    $stmtStage->execute();

    $stageId = (int)$pdo->lastInsertId();
    if ($stageId === 0) {
        $stmtStageId = $pdo->prepare("SELECT id FROM models_ai_stage WHERE seed_id = :seed_id LIMIT 1");
        $stmtStageId->execute([':seed_id' => $seedId]);
        $stageId = (int)$stmtStageId->fetchColumn();
    }

    // probeer direct advies te bepalen
    $advice = attemptAdvice($pdo, $brand, $displayModel, true);
    if (!$advice) {
        $advice = attemptAdvice($pdo, $brand, $displayModel, false);
    }

    return [
        'ok' => true,
        'seed_id' => $seedId,
        'stage_id' => $stageId,
        'brand' => $brand,
        'model' => $displayModel,
        'year_from' => $yearFrom,
        'year_to' => $yearTo,
        'supports_w11' => normalizeSupports($params['supports_w11'] ?? null),
        'advice' => $advice,
    ];
}

$contextSummary = renderContextSummary($context);

/**
 * Roept een extern taalmodel aan.
 */
function callLanguageModel(array $messages, string $contextSummary): array
{
    $apiKey = getenv('OPENAI_API_KEY') ?: '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';
    $model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
    $baseUrl = rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/');

    $systemPrompt = "Je bent de pcslim.ai support assistent. "
        . "Je eerste doel is vaststellen of het opgegeven systeem Windows 11 kan draaien op basis van de database. "
        . "Stel uitsluitend vragen die helpen om het juiste merk, model, bouwjaar of hardwarekenmerken te achterhalen. "
        . "Vraag nooit naar gebruiksvoorkeuren, gewenste upgrades, budgetten of andere wensen van de klant. "
        . "Gebruik de meegeleverde context (inclusief specs en suggesties) om conclusies te trekken. "
        . "Volg deze volgorde:\n"
        . "1) Probeer altijd eerst het exacte modelnummer/productcode te identificeren (stickers onderop, BIOS, Windows systeeminfo, verpakking). Noem zo mogelijk suggesties uit de context.\n"
        . "2) Pas als het model na je navraag nog onduidelijk is, vraag je naar hardwaregegevens zoals CPU, RAM of opslag. Vraag één concreet item per beurt en leg uit waar de klant die info vindt.\n"
        . "Als de informatie onvoldoende is voor een uitspraak, begin je antwoord dan met 'Status: Onbekend – ik heb meer details nodig.' gevolgd door duidelijke instructies hoe de klant de gevraagde details kan vinden. "
        . "Pas wanneer je zeker weet welke hardware er in het systeem zit en je een databasecheck hebt uitgevoerd, geef je een uitspraak zoals 'Status: Windows 11 mogelijk' of 'Status: Windows 11 niet mogelijk' met een korte motivatie. "
        . "Als Windows 11 niet mogelijk is, leg vervolgens uit of een hardware-upgrade kan helpen; zo niet, adviseer Linux als alternatief. "
        . "Zodra je genoeg gegevens hebt voor een concrete databasecheck, voeg dan een regel toe in het formaat [[ADVISE brand=\"MERK\" model=\"MODEL\" strict=0]]. "
        . "Gebruik strict=1 alleen bij een exacte bevestigde match; laat de directive weg als je twijfelt. "
        . "Wanneer je voldoende betrouwbare specificaties hebt verzameld (minimaal CPU, RAM, opslaginterface en een Windows 11-inschatting), plaats daarnaast een [[SEED ...]]-directive met velden zoals brand, model, year, cpu_brand, cpu_model, cpu_gen, ram_installed, ram_max, ram_type, storage_interface, has_m2, supports_w11, tpm, secure_boot, notes. "
        . "Gebruik onderstaande context voor je antwoord, maar verzin geen feiten:";

    $lmMessages = [
        [
            'role' => 'system',
            'content' => $systemPrompt . "\n\nContext:\n" . ($contextSummary ?: 'Geen extra context beschikbaar.'),
        ],
    ];

    foreach ($messages as $msg) {
        $lmMessages[] = [
            'role' => $msg['role'],
            'content' => $msg['content'],
        ];
    }

    if ($apiKey === '') {
        return [
            'provider' => 'fallback',
            'content' => "Ik heb geen toegang tot de AI-service. "
                . "Gebruik de bovenstaande context om de klant verder te helpen, of schakel een medewerker in.",
        ];
    }

    $payload = [
        'model' => $model,
        'messages' => $lmMessages,
        'temperature' => 0.35,
        'max_tokens' => 600,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'provider' => 'openai',
            'content' => 'Fout bij het aanroepen van de AI-service: ' . ($curlErr ?: 'onbekende fout'),
            'error' => true,
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['choices'][0]['message']['content'])) {
        $detail = $decoded['error']['message'] ?? 'Onbekende respons van de AI-service';
        return [
            'provider' => 'openai',
            'content' => 'AI-respons niet bruikbaar: ' . $detail,
            'error' => true,
            'http_status' => $httpStatus,
        ];
    }

    return [
        'provider' => 'openai',
        'content' => trim((string)$decoded['choices'][0]['message']['content']),
        'usage' => $decoded['usage'] ?? null,
        'http_status' => $httpStatus,
    ];
}

$lmResult = callLanguageModel($normalizedMessages, $contextSummary);

$rawReply = isset($lmResult['content']) ? (string)$lmResult['content'] : '';
$replyText = trim($rawReply);
$directives = [];
if ($rawReply !== '' && preg_match_all('/\[\[(\w+)\s+([^\]]+)\]\]/i', $rawReply, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $directives[] = [
            'name' => strtoupper($match[1]),
            'body' => $match[2],
        ];
    }
    $replyText = trim(preg_replace('/\s*\[\[(\w+)\s+[^\]]+\]\]\s*/i', ' ', $replyText));
}

$resolvedAdvice = null;
$resolutionMeta = null;
$seedResult = null;

foreach ($directives as $directive) {
    $params = parseDirectiveParams($directive['body']);
    switch ($directive['name']) {
        case 'ADVISE':
            $brandCandidate = $params['brand'] ?? ($context['brand_input'] ?? '');
            $modelCandidate = $params['model'] ?? ($context['model_input'] ?? '');
            $strictFlag = isset($params['strict']) ? (bool)((int)$params['strict']) : false;

            $resolutionMeta = [
                'attempted' => true,
                'brand' => $brandCandidate,
                'model' => $modelCandidate,
                'strict' => $strictFlag,
                'found' => false,
            ];

            if ($brandCandidate !== '' && $modelCandidate !== '') {
                $resolvedAdvice = attemptAdvice($pdo, $brandCandidate, $modelCandidate, $strictFlag);
                if (!$resolvedAdvice && !$strictFlag) {
                    $resolvedAdvice = attemptAdvice($pdo, $brandCandidate, $modelCandidate, true);
                }
                if ($resolvedAdvice) {
                    $resolutionMeta['found'] = true;
                }
            }
            break;

        case 'SEED':
            $seedResult = handleSeedDirective($pdo, $params);
            if (!empty($seedResult['advice'])) {
                $resolvedAdvice = $seedResult['advice'];
                $resolutionMeta = [
                    'attempted' => true,
                    'brand' => $seedResult['brand'] ?? null,
                    'model' => $seedResult['model'] ?? null,
                    'strict' => true,
                    'found' => (bool)($seedResult['advice']['found'] ?? false),
                ];
            }
            break;
    }
}

if ($replyText === '') {
    $replyText = $rawReply !== '' ? $rawReply : 'Ik heb geen compleet antwoord kunnen formuleren.';
}

$payload = [
    'ok' => true,
    'reply' => $replyText,
    'provider' => $lmResult['provider'] ?? 'unknown',
    'context' => $context,
];

if ($resolvedAdvice) {
    $payload['resolved_advice'] = $resolvedAdvice;
}
if ($resolutionMeta) {
    $payload['resolution_meta'] = $resolutionMeta;
}
if ($seedResult) {
    $payload['seed_result'] = $seedResult;
}

if (!empty($lmResult['error'])) {
    $payload['warning'] = true;
}
if (!empty($lmResult['usage'])) {
    $payload['usage'] = $lmResult['usage'];
}

respond($payload);
