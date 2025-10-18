<?php
/**
 * PC-Slim – chat_assist.php
 * Werkt zonder Composer. Leest ../.env, roept OpenAI Chat Completions aan via cURL
 * en geeft een compact JSON-antwoord terug dat je frontend direct kan gebruiken.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Optionele CORS (handig voor lokale tests; pas aan naar je domein indien nodig)
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------- Helpers ---------- */

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Eenvoudige .env loader (zonder Composer).
 * Leest KEY=VALUE per regel; negeert lege regels en # comments.
 */
function load_env_simple(string $envPath): void {
    if (!is_readable($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === '') continue;
        // Strip eventuele quotes rondom waarde
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
}

/**
 * Kleine sanity checks en nette foutberichten.
 */
function require_extension(string $ext): void {
    if (!extension_loaded($ext)) {
        json_out([
            'ok'    => false,
            'error' => "PHP-extensie ontbreekt: {$ext}. Installeer/activeer deze op je NAS."
        ], 500);
    }
}

/**
 * Super simpele guard voor API keys (vermijdt typefouten).
 */
function looks_like_openai_key(string $key): bool {
    return str_starts_with($key, 'sk-') && strlen($key) > 20;
}

/* ---------- Init ---------- */

// .env in projectroot (één map omhoog vanaf /api)
$projectRoot = realpath(__DIR__ . '/../') ?: dirname(__DIR__);
load_env_simple($projectRoot . '/.env');

$apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
if (!looks_like_openai_key($apiKey)) {
    json_out([
        'ok'    => false,
        'error' => 'OPENAI_API_KEY ontbreekt of is ongeldig. Zet in ../.env: OPENAI_API_KEY=sk-....'
    ], 500);
}

require_extension('curl');

/* ---------- Input ---------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Sta een eenvoudige GET healthcheck toe
if ($method === 'GET') {
    json_out([
        'ok'      => true,
        'message' => 'chat_assist is actief. Doe een POST met {"messages":[...]} of {"brand":"...","model":"..."}'
    ]);
}

if ($method !== 'POST') {
    json_out(['ok' => false, 'error' => 'Gebruik POST (Content-Type: application/json).'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);

if (!is_array($input)) {
    json_out(['ok' => false, 'error' => 'Ongeldige JSON payload.'], 400);
}

/**
 * Voorzie fallback prompt op basis van brand/model als er geen messages zijn.
 * Frontend mag ook direct messages sturen in ChatML-vorm:
 *  messages: [{role:"system"|"user"|"assistant", content:"..."}]
 */
$messages = [];
if (!empty($input['messages']) && is_array($input['messages'])) {
    // Sanitize minimáál: alleen role/content doorlaten
    foreach ($input['messages'] as $m) {
        if (!is_array($m)) continue;
        $role = $m['role'] ?? '';
        $content = $m['content'] ?? '';
        if (!$role || !$content) continue;
        $messages[] = ['role' => $role, 'content' => $content];
    }
}

if (!$messages) {
    $brand = trim((string)($input['brand'] ?? ''));
    $model = trim((string)($input['model'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $hint = $brand || $model ? "Gebruiker gaf op: merk '{$brand}', model '{$model}'." : "Geen merk/model opgegeven.";

    $system = "Je bent een beknopte assistent voor PC-Slim. Antwoord in het Nederlands, helder en praktisch. 
- Als er te weinig gegevens zijn voor een model-advies, vraag exact één gerichte vervolgvraag (bijv. waar modelcode te vinden is).
- Als je genoeg weet voor een beknopt advies, geef het direct.
- Geen lange inleidingen, geen verkooppraat.";
    $user = "Kun je helpen bij het bepalen of Windows 11 mogelijk is en anders Linux aanraden?\n{$hint}\nReden van geen match (indien van toepassing): {$reason}.";

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/* ---------- OpenAI API call ---------- */

/**
 * Je kunt hier het gewenste model aanpassen.
 * gpt-4o-mini is snel/goedkoop; wil je hoger, kies een zwaarder model.
 */
$model = 'gpt-4o-mini';

// Optionele temperatuur/instellingen
$payload = [
    'model'       => $model,
    'messages'    => $messages,
    'temperature' => 0.3,
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0) {
    json_out([
        'ok'    => false,
        'error' => "Netwerkfout (cURL errno {$errno}): {$error}",
    ], 502);
}

if ($http >= 400 || !$response) {
    json_out([
        'ok'       => false,
        'error'    => "OpenAI HTTP-fout: {$http}",
        'response' => $response,
    ], 502);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    json_out([
        'ok'       => false,
        'error'    => 'Ongeldig antwoord van OpenAI (geen JSON).',
        'response' => $response,
    ], 502);
}

$reply = $data['choices'][0]['message']['content'] ?? null;
if (!$reply) {
    json_out([
        'ok'       => false,
        'error'    => 'OpenAI gaf geen inhoudelijk antwoord terug.',
        'raw'      => $data,
    ], 502);
}

/* ---------- Succes ---------- */

json_out([
    'ok'    => true,
    'reply' => $reply,
    // Voor compatibiliteit met je frontend kun je hier later nog
    // 'resolved_advice', 'suggestions', 'ask' toevoegen als die logica komt.
]);
