<?php
// chat_assist.php — PC-Slim vriendelijke AI (zonder Composer, met .env)

header('Content-Type: application/json; charset=utf-8');

// ---------- 1) .env laden (alleen lezen) ----------
$envPath = __DIR__ . '/.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if (!getenv($k)) { putenv("$k=$v"); }
        }
    }
}

// ---------- 2) Config uit env ----------
$API_KEY   = getenv('OPENAI_API_KEY') ?: '';
$MODEL     = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
$API_BASE  = rtrim(getenv('OPENAI_API_BASE') ?: 'https://api.openai.com/v1', '/');

if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'OPENAI_API_KEY ontbreekt. Zet die in .env']);
    exit;
}

// ---------- 3) Input binnenhalen ----------
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$userMessage = $payload['message'] ?? ($_POST['message'] ?? '');
$context     = $payload['context'] ?? null;

if (!$userMessage) {
    echo json_encode(['reply' => 'Vertel kort wat je zoekt of wat je laptop is (merk + model).']);
    exit;
}

// ---------- 4) System prompt ----------
$systemPrompt = <<<TXT
Jij bent een vriendelijke gids voor mensen die weinig van computers weten. Doel: helpen bij Windows 11-geschiktheid. Als dat niet kan: Linux (Zorin/Mint/Ubuntu) aanraden, eventueel met RAM/SSD-upgrade.

Stijl:
- Korte, begrijpelijke zinnen. Geen jargon; leg termen zo nodig in 1 korte zin uit.
- Begin nooit over BIOS/UEFI of functietoetsen. Eerst oplossingen via Windows: “Systeeminformatie”, “Instellingen”, “Taakbeheer”.
- 3–5 stappen. Sluit af met precies één concrete vervolgvraag.
- Niet bang maken; wel back-up en veiligheid benoemen als relevant.

Triage (eerst proberen via Windows):
1) Vraag naar merk+model, RAM (GB), opslagtype (HDD/SSD) en grootte, en gebruik (basis/dagelijks/foto’s/lichte games).
2) Laat vinden via: Systeeminformatie → Systeemmodel; Instellingen > Systeem > Info → Geïnstalleerd RAM; Taakbeheer > Prestaties → HDD/SSD; Verkenner > Deze pc → schijfgrootte.
3) Pas als laatste redmiddel: sticker onderop of BIOS/UEFI.

Beslislogica (praattaal):
- <8 GB RAM of HDD = upgrade aanraden: 8–16 GB RAM + SSD (min. 240–480 GB).
- CPU/TPM blokkeert Windows 11 of CPU ~<2015 = Linux (Zorin/Mint) als snelle, veilige optie die op Windows lijkt.
- Twijfel: eerst SSD-upgrade, dan keuze: Windows 10 gehardend of Linux.

Antwoordstructuur:
1) Korte geruststelling.
2) 3–5 duidelijke stappen.
3) Eén vraag.
4) Optioneel: mini-tip (back-up of foto van scherm).
TXT;

// ---------- 5) (optioneel) context meegeven ----------
$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
];

if ($context && is_string($context) && strlen($context) > 0) {
    $messages[] = ['role' => 'system', 'content' => "Context (samenvatting van de situatie): " . substr($context, 0, 1500)];
}

$messages[] = ['role' => 'system', 'content' => 'Controleer je antwoord: maximaal ~120 woorden, eerst Windows-routes, BIOS alleen op expliciet verzoek, eindig met precies één vervolgvraag.'];
$messages[] = ['role' => 'user',   'content' => $userMessage];

// ---------- 6) API-call (Chat Completions compatible) ----------
$body = [
    'model'       => $MODEL,
    'messages'    => $messages,
    'temperature' => 0.3,
    'max_tokens'  => 400,
];

$ch = curl_init("$API_BASE/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $API_KEY",
        "Content-Type: application/json",
    ],
    CURLOPT_POST       => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT    => 30,
    CURLOPT_POSTFIELDS => json_encode($body),
]);

$result = curl_exec($ch);
$errno  = curl_errno($ch);
$error  = curl_error($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno || $code >= 400 || !$result) {
    http_response_code(502);
    echo json_encode([
        'error' => 'AI-aanroep mislukte',
        'detail' => $error ?: "HTTP $code",
    ]);
    exit;
}

$data = json_decode($result, true);
$reply = $data['choices'][0]['message']['content'] ?? 'Er ging iets mis. Probeer het zo meteen nog eens.';

// ---------- 7) Antwoord terug ----------
echo json_encode([
    'reply'   => $reply,
    'model'   => $MODEL,
    'tokens'  => $data['usage'] ?? null,
], JSON_UNESCAPED_UNICODE);
