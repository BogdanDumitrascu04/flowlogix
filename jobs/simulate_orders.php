<?php
/*
 * Demo order simulator, run once an hour by cron. Each run simulates one hour of
 * warehouse work: it creates new orders on the three flows and moves open orders
 * from the last days forward, so some finish and others fall behind.
 *
 *   php jobs/simulate_orders.php           only during working hours (06:00-22:00)
 *   php jobs/simulate_orders.php --force   any time, for tests
 *
 * An hour is simulated at most once: simulator_runs has the hour as its primary key,
 * so a second run in the same hour is refused by the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acest script se rulează doar din linia de comandă.\n");
}

require_once __DIR__ . '/../config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const WORK_START_HOUR     = 6;
const WORK_END_HOUR       = 22;
const ADVANCE_WINDOW_DAYS = 2;   // older open orders are never advanced, so the backlog stays visible

// Hourly probabilities for an open order
const P_INBOUND_FINISH  = 0.5;
const P_PICK_ASSIGN     = 0.7;
const P_PICK_FINISH     = 0.5;
const P_SHIP_LATE_ORDER = 0.6;   // shipment already past its planned time
const P_SHIP_EARLY      = 0.15;  // shipment leaves before its planned time

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

// A random moment between the start of the current hour and now
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
// Orders created after 18:00 are due the next day
$dueDate     = $hour < 18 ? $now->format('Y-m-d') : (clone $now)->modify('+1 day')->format('Y-m-d');
$windowStart = (clone $now)->modify('-' . ADVANCE_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');

$conn->begin_transaction();

try {
    // Claim the hour first; a duplicate key (error 1062) means it was already simulated.
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

    // New orders
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

    // New pick/pack orders have no operator yet; the app reads an empty name as "not started".
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

    // Advance open orders created before this hour, within the advance window
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

    $summary = $conn->prepare("UPDATE simulator_runs SET created_orders = ?, advanced_orders = ? WHERE run_hour = ?");
    $summary->bind_param("iis", $created, $advanced, $runHour);
    $summary->execute();

    $conn->commit();
    echo "Ora {$runHour}: {$created} comenzi noi, {$advanced} comenzi avansate.\n";
} catch (Throwable $e) {
    // Any error rolls back the whole hour, including its claim, so the next run can retry it.
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // keep the original error, not one from the rollback
    }
    throw $e;
}
