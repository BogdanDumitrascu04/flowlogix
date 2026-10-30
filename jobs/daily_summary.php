<?php
/*
 * AI summary of the day for the manager dashboard: SQL computes the figures,
 * Claude Haiku turns them into 2-3 sentences. Run by cron (deploy/flowlogix.cron).
 *
 * The model only receives aggregated numbers, no personal data. If the API key is
 * missing or the call fails, nothing is saved and the dashboard keeps the previous summary.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acest script se rulează doar din linia de comandă.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../flow_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const SUMMARY_MODEL = 'claude-haiku-5-5';

const SYSTEM_PROMPT = <<<'TXT'
Ești analist de operațiuni într-un depozit logistic. Primești, în format JSON, cifrele zilei
curente până la ora indicată, calculate direct din baza de date a depozitului.

Scrie pentru managerul logistic un rezumat de 2–3 propoziții, în limba română: ce merge bine,
ce cere atenție și o acțiune concretă pentru restul zilei.

Folosește doar cifrele din JSON. Nu inventa cauze și nu adăuga cifre care nu reies direct din date.
Scrie text simplu, fără titluri, liste sau formatare Markdown.
TXT;

if (empty($anthropicApiKey)) {
    echo "Lipsește cheia API (\$anthropicApiKey în config.php); rezumatul nu a fost generat.\n";
    exit(0);
}

// Same rules as the manager dashboard with period=today
$today = flx_period_params('today');

function sum_row(mysqli $db, string $sql, array $params): array
{
    $row = flx_query($db, $sql, $params)->fetch_assoc();
    return array_map('intval', $row);
}

$facts = [
    'ora_raport' => date('Y-m-d H:i'),
    'receptii_inbound' => sum_row($conn, "
        SELECT COUNT(*) AS total,
               SUM(finished_at IS NOT NULL) AS finalizate,
               SUM(finished_at IS NULL AND expected_finished_date < CURDATE()) AS intarziate,
               SUM(finished_at IS NULL AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE())) AS in_lucru
        FROM inbound_orders WHERE created_at BETWEEN ? AND ?", $today),
    'comenzi_pick_pack' => sum_row($conn, "
        SELECT COUNT(*) AS total,
               SUM(finished_at IS NOT NULL) AS finalizate,
               SUM(finished_at IS NULL AND (operator_name IS NULL OR operator_name = '')) AS fara_operator,
               COALESCE(SUM(items_count), 0) AS articole
        FROM pickpack_orders WHERE created_at BETWEEN ? AND ?", $today),
    'expedieri_outbound' => sum_row($conn, "
        SELECT COUNT(*) AS total,
               SUM(finished_at IS NOT NULL AND finished_at <= planned_date) AS expediate_la_timp,
               SUM(finished_at IS NOT NULL AND finished_at > planned_date) AS expediate_cu_intarziere,
               SUM(finished_at IS NULL AND planned_date < NOW()) AS restante_neplecate
        FROM outbound_orders WHERE created_at BETWEEN ? AND ?", $today),
];

$lateByDestination = flx_query($conn, "
    SELECT destination AS destinatie, COUNT(*) AS expedieri_intarziate
    FROM outbound_orders
    WHERE created_at BETWEEN ? AND ?
      AND ((finished_at IS NULL AND planned_date < NOW()) OR finished_at > planned_date)
    GROUP BY destination
    ORDER BY expedieri_intarziate DESC, destination
    LIMIT 3", $today)->fetch_all(MYSQLI_ASSOC);
$facts['destinatii_cu_intarzieri'] = $lateByDestination;

$client = new Client(apiKey: $anthropicApiKey);

try {
    $message = $client->messages->create(
        model: SUMMARY_MODEL,
        maxTokens: 2000,
        system: SYSTEM_PROMPT,
        messages: [
            ['role' => 'user', 'content' => json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ],
        outputConfig: ['effort' => 'low'],
    );
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Cheia API e invalidă sau a fost revocată.\n");
    exit(1);
} catch (RateLimitException $e) {
    fwrite(STDERR, "Limita de cereri a fost atinsă; se reîncearcă la următoarea rulare.\n");
    exit(1);
} catch (APIStatusException $e) {
    fwrite(STDERR, "API-ul a răspuns cu o eroare: " . $e->getMessage() . "\n");
    exit(1);
} catch (APIConnectionException $e) {
    fwrite(STDERR, "Nu s-a putut contacta API-ul: " . $e->getMessage() . "\n");
    exit(1);
}

$stopReason = $message->stopReason instanceof BackedEnum ? $message->stopReason->value : (string)$message->stopReason;
if ($stopReason !== 'end_turn') {
    fwrite(STDERR, "Răspuns incomplet (stop_reason: {$stopReason}); nu se salvează.\n");
    exit(1);
}

$summary = '';
foreach ($message->content as $block) {
    if ($block->type === 'text') {
        $summary .= $block->text;
    }
}
$summary = trim($summary);
if ($summary === '') {
    fwrite(STDERR, "Răspunsul nu conține text; nu se salvează.\n");
    exit(1);
}

// The dashboard always shows the latest row.
$model         = SUMMARY_MODEL;
$generatedAt   = date('Y-m-d H:i:s');
$inputTokens   = (int)$message->usage->inputTokens;
$outputTokens  = (int)$message->usage->outputTokens;
$insert = $conn->prepare(
    "INSERT INTO daily_summaries (generated_at, summary, model, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?)"
);
$insert->bind_param("sssii", $generatedAt, $summary, $model, $inputTokens, $outputTokens);
$insert->execute();

echo "Rezumat salvat ({$inputTokens} tokeni intrare, {$outputTokens} ieșire):\n{$summary}\n";
