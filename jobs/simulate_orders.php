<?php
/*
 * simulate_orders.php
 * Simulator de comenzi pentru demo: rulează o dată pe oră (cron sau Task Scheduler)
 * și imită o oră de lucru într-un depozit:
 *   1. creează comenzi noi pe cele trei fluxuri (inbound, pick & pack, outbound);
 *   2. avansează comenzile deschise din ultimele zile: unele se finalizează, altele întârzie.
 *
 * Rulare, din folderul proiectului:
 *   php jobs/simulate_orders.php           doar în programul depozitului (06:00–22:00)
 *   php jobs/simulate_orders.php --force   oricând, pentru test
 *
 * Fiecare oră se simulează o singură dată: tabelul simulator_runs are cheia primară
 * pe ora rulării, deci a doua rulare în aceeași oră e refuzată de baza de date.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acest script se rulează doar din linia de comandă.\n");
}

require_once __DIR__ . '/../config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const WORK_START_HOUR     = 6;   // prima oră a programului depozitului
const WORK_END_HOUR       = 22;  // programul se termină la 22:00
const ADVANCE_WINDOW_DAYS = 2;   // se avansează doar comenzile din ultimele 2 zile; restanțele mai vechi rămân

// Probabilitățile pe oră pentru o comandă deschisă
const P_INBOUND_FINISH  = 0.5;   // recepția se finalizează
const P_PICK_ASSIGN     = 0.7;   // comanda primește un operator
const P_PICK_FINISH     = 0.5;   // operatorul termină comanda
const P_SHIP_LATE_ORDER = 0.6;   // o expediere care a depășit ora planificată pleacă în ora asta
const P_SHIP_EARLY      = 0.15;  // o expediere pleacă înainte de ora planificată

$suppliers    = ['Nordic Goods', 'Urban Trade', 'EuroShip', 'Fresh Import', 'TransLog Expert', 'Atlas Trade', 'Danube Cargo'];
$operators    = ['Vlad Ionescu', 'Irina S', 'Monica D', 'Paul R', 'Carmen N', 'Dan V', 'Sorin L'];
$destinations = ['București', 'Timișoara', 'Cluj-Napoca', 'Oradea', 'Brașov', 'Sibiu', 'Ploiești'];

function sim_pick(array $items): string
{
    return $items[array_rand($items)];
}

function sim_chance(float $probability): bool
{
    return mt_rand() / mt_getrandmax() < $probability;
}

// Un moment aleator între începutul orei curente și acum
function sim_time_this_hour(DateTime $now): string
{
    $start = (clone $now)->setTime((int)$now->format('G'), 0, 0);
    $seconds = mt_rand(0, $now->getTimestamp() - $start->getTimestamp());
    return $start->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
}

function sim_next_order_number(mysqli $db, string $table): string
{
    $row = $db->query("SELECT COALESCE(MAX(CAST(order_number AS UNSIGNED)), 1000) + 1 FROM `{$table}`")->fetch_row();
    return (string)$row[0];
}

$now   = new DateTime('now');
$force = in_array('--force', $argv, true);
$hour  = (int)$now->format('G');

if (!$force && ($hour < WORK_START_HOUR || $hour >= WORK_END_HOUR)) {
    echo "În afara programului depozitului ({$now->format('H:i')}); nu s-a simulat nimic.\n";
    exit(0);
}

$nowStr      = $now->format('Y-m-d H:i:s');
$runHour     = $now->format('Y-m-d H:00:00');
// Comenzile create după ora 18 au termen a doua zi
$dueDate     = $hour < 18 ? $now->format('Y-m-d') : (clone $now)->modify('+1 day')->format('Y-m-d');
$windowStart = (clone $now)->modify('-' . ADVANCE_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');

$conn->begin_transaction();

try {
    // 1. Marchează ora ca simulată. Dacă ora există deja, cheia primară oprește rularea (eroarea 1062).
    $mark = $conn->prepare("INSERT INTO simulator_runs (run_hour, created_orders, advanced_orders, ran_at) VALUES (?, 0, 0, ?)");
    $mark->bind_param("ss", $runHour, $nowStr);
    $mark->execute();
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    if ($e->getCode() === 1062) {
        echo "Ora {$runHour} a fost deja simulată; nu s-a schimbat nimic.\n";
        exit(0);
    }
    throw $e;
}

try {
    $created  = 0;
    $advanced = 0;

    // 2. Comenzi noi
    $insertInbound = $conn->prepare(
        "INSERT INTO inbound_orders (supplier_name, reception_number, status, priority, created_at, expected_finished_date)
         VALUES (?, ?, 'pending', ?, ?, ?)"
    );
    $inboundCount = mt_rand(1, 2);
    for ($i = 1; $i <= $inboundCount; $i++) {
        $supplier  = sim_pick($suppliers);
        $reception = 'SIM-' . $now->format('Ymd-H') . '-' . $i;
        $priority  = sim_pick(['low', 'medium', 'medium', 'high']);
        $createdAt = sim_time_this_hour($now);
        $insertInbound->bind_param("sssss", $supplier, $reception, $priority, $createdAt, $dueDate);
        $insertInbound->execute();
        $created++;
    }

    // Comenzile pick & pack noi nu au încă operator (șir gol = „neînceput” în aplicație)
    $insertPickpack = $conn->prepare(
        "INSERT INTO pickpack_orders (order_number, operator_name, items_count, status, created_at, expected_finished_date)
         VALUES (?, '', ?, 'not_started', ?, ?)"
    );
    $pickpackCount = mt_rand(1, 3);
    for ($i = 1; $i <= $pickpackCount; $i++) {
        $orderNumber = sim_next_order_number($conn, 'pickpack_orders');
        $items       = mt_rand(1, 40);
        $createdAt   = sim_time_this_hour($now);
        $insertPickpack->bind_param("siss", $orderNumber, $items, $createdAt, $dueDate);
        $insertPickpack->execute();
        $created++;
    }

    $insertOutbound = $conn->prepare(
        "INSERT INTO outbound_orders (order_number, destination, planned_date, delay_minutes, status, created_at)
         VALUES (?, ?, ?, 0, 'ready', ?)"
    );
    $outboundCount = mt_rand(1, 2);
    for ($i = 1; $i <= $outboundCount; $i++) {
        $orderNumber = sim_next_order_number($conn, 'outbound_orders');
        $destination = sim_pick($destinations);
        $planned     = (clone $now)->modify('+' . mt_rand(120, 480) . ' minutes')->format('Y-m-d H:i:s');
        $createdAt   = sim_time_this_hour($now);
        $insertOutbound->bind_param("ssss", $orderNumber, $destination, $planned, $createdAt);
        $insertOutbound->execute();
        $created++;
    }

    // 3. Avansează comenzile deschise create înainte de ora curentă, în fereastra de 2 zile
    $openInbound = $conn->prepare(
        "SELECT id, status FROM inbound_orders
         WHERE finished_at IS NULL AND created_at >= ? AND created_at < ?"
    );
    $openInbound->bind_param("ss", $windowStart, $runHour);
    $openInbound->execute();
    $rows = $openInbound->get_result()->fetch_all(MYSQLI_ASSOC);
    $finishInbound  = $conn->prepare("UPDATE inbound_orders SET status = 'completed', finished_at = ? WHERE id = ?");
    $processInbound = $conn->prepare("UPDATE inbound_orders SET status = 'processing' WHERE id = ?");
    foreach ($rows as $order) {
        $id = (int)$order['id'];
        if (sim_chance(P_INBOUND_FINISH)) {
            $finishInbound->bind_param("si", $nowStr, $id);
            $finishInbound->execute();
            $advanced++;
        } elseif ($order['status'] === 'pending') {
            $processInbound->bind_param("i", $id);
            $processInbound->execute();
            $advanced++;
        }
    }

    $openPickpack = $conn->prepare(
        "SELECT id, operator_name FROM pickpack_orders
         WHERE finished_at IS NULL AND created_at >= ? AND created_at < ?"
    );
    $openPickpack->bind_param("ss", $windowStart, $runHour);
    $openPickpack->execute();
    $rows = $openPickpack->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignPickpack = $conn->prepare("UPDATE pickpack_orders SET operator_name = ?, status = 'in_progress' WHERE id = ?");
    $finishPickpack = $conn->prepare("UPDATE pickpack_orders SET status = 'completed', finished_at = ? WHERE id = ?");
    foreach ($rows as $order) {
        $id = (int)$order['id'];
        if ($order['operator_name'] === '') {
            if (sim_chance(P_PICK_ASSIGN)) {
                $operator = sim_pick($operators);
                $assignPickpack->bind_param("si", $operator, $id);
                $assignPickpack->execute();
                $advanced++;
            }
        } elseif (sim_chance(P_PICK_FINISH)) {
            $finishPickpack->bind_param("si", $nowStr, $id);
            $finishPickpack->execute();
            $advanced++;
        }
    }

    $openOutbound = $conn->prepare(
        "SELECT id, planned_date FROM outbound_orders
         WHERE finished_at IS NULL AND created_at >= ? AND created_at < ?"
    );
    $openOutbound->bind_param("ss", $windowStart, $runHour);
    $openOutbound->execute();
    $rows = $openOutbound->get_result()->fetch_all(MYSQLI_ASSOC);
    $shipOutbound  = $conn->prepare("UPDATE outbound_orders SET status = 'shipped', finished_at = ?, delay_minutes = ? WHERE id = ?");
    $delayOutbound = $conn->prepare("UPDATE outbound_orders SET status = 'delayed', delay_minutes = ? WHERE id = ?");
    foreach ($rows as $order) {
        $id          = (int)$order['id'];
        $planned     = new DateTime($order['planned_date']);
        $lateMinutes = intdiv($now->getTimestamp() - $planned->getTimestamp(), 60);

        if ($lateMinutes >= 0) {
            if (sim_chance(P_SHIP_LATE_ORDER)) {
                $shipOutbound->bind_param("sii", $nowStr, $lateMinutes, $id);
                $shipOutbound->execute();
            } else {
                $delayOutbound->bind_param("ii", $lateMinutes, $id);
                $delayOutbound->execute();
            }
            $advanced++;
        } elseif (sim_chance(P_SHIP_EARLY)) {
            $onTime = 0;
            $shipOutbound->bind_param("sii", $nowStr, $onTime, $id);
            $shipOutbound->execute();
            $advanced++;
        }
    }

    // 4. Salvează rezultatul orei și confirmă totul deodată
    $summary = $conn->prepare("UPDATE simulator_runs SET created_orders = ?, advanced_orders = ? WHERE run_hour = ?");
    $summary->bind_param("iis", $created, $advanced, $runHour);
    $summary->execute();

    $conn->commit();
    echo "Ora {$runHour}: {$created} comenzi noi, {$advanced} comenzi avansate.\n";
} catch (Throwable $e) {
    // Orice eroare anulează toată ora, inclusiv marcajul, ca rularea următoare să o poată relua
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // păstrăm eroarea originală, nu pe cea de la rollback
    }
    throw $e;
}
