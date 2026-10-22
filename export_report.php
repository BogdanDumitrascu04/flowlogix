<?php
session_start();

$allowedRoles = ['depozit', 'manager', 'admin'];
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

require_once 'config.php';
require_once 'flow_helpers.php';

$period = isset($_GET['period']) ? $_GET['period'] : 'all';
$area = isset($_GET['area']) ? $_GET['area'] : 'all';
$detail = isset($_GET['detail']) ? $_GET['detail'] : 'executive';

/* FILTRU PERIOADĂ */
// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
$dateFilter = "created_at BETWEEN ? AND ?";
$dateParams = flx_period_params('all');
$periodLabel = "Toată perioada";

if ($period === 'today') {
    $dateParams = flx_period_params('today');
    $periodLabel = "Astăzi";
} elseif ($period === '7d') {
    $dateParams = flx_period_params('7d');
    $periodLabel = "Ultimele 7 zile";
} elseif ($period === '30d') {
    $dateParams = flx_period_params('30d');
    $periodLabel = "Ultimele 30 zile";
} elseif ($period === 'month') {
    $dateParams = flx_period_params('month');
    $periodLabel = "Luna curentă";
}

/* PREGĂTIRE NUME FIȘIER */
$filename = "flowlogix_raport_" . date("Y-m-d_H-i-s") . ".csv";

/* HEADERS CSV */
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

/* BOM UTF-8 pentru diacritice corecte în Excel */
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

/* FUNCȚIE PENTRU SCRIERE SECȚIUNE */
function writeSectionTitle($output, $title)
{
    fputcsv($output, [$title], ';');
}

function writeEmptyRow($output)
{
    fputcsv($output, [''], ';');
}

/* INFO RAPORT */
fputcsv($output, ['FlowLogix - Raport exportat'], ';');
fputcsv($output, ['Perioadă', $periodLabel], ';');
fputcsv($output, ['Arie', $area], ';');
fputcsv($output, ['Nivel detaliu', $detail], ';');
fputcsv($output, ['Data generării', date('d.m.Y H:i:s')], ';');
writeEmptyRow($output);

/* =========================
   NIVEL EXECUTIV
   ========================= */
if ($detail === 'executive') {

    writeSectionTitle($output, 'Sinteză operațională');
    fputcsv($output, ['Modul', 'Total', 'Finalizate / Expediate', 'În lucru / Pregătite', 'Întârziate / Așteptare / Risc'], ';');

    if ($area === 'all' || $area === 'inbound') {
        $total = 0;
        $completed = 0;
        $processing = 0;
        $pending = 0;

        $q1 = flx_query($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE $dateFilter", $dateParams);
        $q2 = flx_query($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NOT NULL AND $dateFilter", $dateParams);
        $q3 = flx_query($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE()) AND $dateFilter", $dateParams);
        $q4 = flx_query($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND expected_finished_date IS NOT NULL AND expected_finished_date < CURDATE() AND $dateFilter", $dateParams);

        if ($q1) $total = (int)$q1->fetch_assoc()['total'];
        if ($q2) $completed = (int)$q2->fetch_assoc()['total'];
        if ($q3) $processing = (int)$q3->fetch_assoc()['total'];
        if ($q4) $pending = (int)$q4->fetch_assoc()['total'];

        fputcsv($output, ['Inbound', $total, $completed, $processing, $pending], ';');
    }

    if ($area === 'all' || $area === 'pickpack') {
        $total = 0;
        $completed = 0;
        $inProgress = 0;
        $notStarted = 0;

        $q1 = flx_query($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE $dateFilter", $dateParams);
        $q2 = flx_query($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NOT NULL AND $dateFilter", $dateParams);
        $q3 = flx_query($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND $dateFilter", $dateParams);
        $q4 = flx_query($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND (operator_name IS NULL OR operator_name = '') AND $dateFilter", $dateParams);

        if ($q1) $total = (int)$q1->fetch_assoc()['total'];
        if ($q2) $completed = (int)$q2->fetch_assoc()['total'];
        if ($q3) $inProgress = (int)$q3->fetch_assoc()['total'];
        if ($q4) $notStarted = (int)$q4->fetch_assoc()['total'];

        fputcsv($output, ['Pick / Pack', $total, $completed, $inProgress, $notStarted], ';');
    }

    if ($area === 'all' || $area === 'outbound') {
        $total = 0;
        $shipped = 0;
        $ready = 0;
        $delayed = 0;

        $q1 = flx_query($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE $dateFilter", $dateParams);
        $q2 = flx_query($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NOT NULL AND $dateFilter", $dateParams);
        $q3 = flx_query($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) >= CURDATE() AND $dateFilter", $dateParams);
        $q4 = flx_query($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateFilter", $dateParams);

        if ($q1) $total = (int)$q1->fetch_assoc()['total'];
        if ($q2) $shipped = (int)$q2->fetch_assoc()['total'];
        if ($q3) $ready = (int)$q3->fetch_assoc()['total'];
        if ($q4) $delayed = (int)$q4->fetch_assoc()['total'];

        fputcsv($output, ['Outbound', $total, $shipped, $ready, $delayed], ';');
    }

    writeEmptyRow($output);

    writeSectionTitle($output, 'Restanțe operaționale');
    fputcsv($output, ['Modul', 'Referință', 'Context', 'Problemă', 'Scadență', 'Zile restanță'], ';');

    $backlogRows = [];

    if ($area === 'all' || $area === 'inbound') {
        $q = flx_query($conn, "
            SELECT 'inbound' AS module, reception_number AS reference, supplier_name AS context,
              'Recepție nefinalizată' AS problem, expected_finished_date AS due_date,
              DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_overdue
            FROM inbound_orders
            WHERE finished_at IS NULL
              AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
              AND $dateFilter
            ORDER BY days_overdue DESC
        ", $dateParams);
        if ($q) while ($row = $q->fetch_assoc()) $backlogRows[] = $row;
    }

    if ($area === 'all' || $area === 'pickpack') {
        $q = flx_query($conn, "
            SELECT 'pickpack' AS module, CAST(order_number AS CHAR) AS reference,
              COALESCE(operator_name, 'Neatribuit') AS context,
              'Comandă nefinalizată' AS problem, expected_finished_date AS due_date,
              DATEDIFF(CURDATE(), expected_finished_date) AS days_overdue
            FROM pickpack_orders
            WHERE finished_at IS NULL
              AND expected_finished_date < CURDATE()
              AND $dateFilter
            ORDER BY days_overdue DESC
        ", $dateParams);
        if ($q) while ($row = $q->fetch_assoc()) $backlogRows[] = $row;
    }

    if ($area === 'all' || $area === 'outbound') {
        $q = flx_query($conn, "
            SELECT 'outbound' AS module, CAST(order_number AS CHAR) AS reference,
              destination AS context,
              'Expediție neefectuată' AS problem, DATE(planned_date) AS due_date,
              DATEDIFF(CURDATE(), DATE(planned_date)) AS days_overdue
            FROM outbound_orders
            WHERE finished_at IS NULL
              AND DATE(planned_date) < CURDATE()
              AND $dateFilter
            ORDER BY days_overdue DESC
        ", $dateParams);
        if ($q) while ($row = $q->fetch_assoc()) $backlogRows[] = $row;
    }

    if (!empty($backlogRows)) {
        usort($backlogRows, fn($a, $b) => (int)$b['days_overdue'] - (int)$a['days_overdue']);
        foreach ($backlogRows as $row) {
            fputcsv($output, [
                $row['module'],
                $row['reference'],
                $row['context'],
                $row['problem'],
                $row['due_date'] ?? '',
                '+' . (int)$row['days_overdue'] . ' zile'
            ], ';');
        }
    } else {
        fputcsv($output, ['Nu există restanțe operaționale pentru selecția curentă'], ';');
    }
}

/* =========================
   NIVEL DETALIAT
   ========================= */
else {

    if ($area === 'all' || $area === 'inbound') {
        writeSectionTitle($output, 'Inbound - Detaliat');
        fputcsv($output, ['Nr. recepție', 'Furnizor', 'Status', 'Prioritate', 'Creată la', 'Finalizată la'], ';');

        $query = "
            SELECT reception_number, supplier_name, status, priority, created_at, finished_at, expected_finished_date
            FROM inbound_orders
            WHERE $dateFilter
            ORDER BY created_at DESC
        ";
        $result = flx_query($conn, $query, $dateParams);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['reception_number'],
                    $row['supplier_name'],
                    flx_inbound_status($row),
                    flx_inbound_priority($row),
                    $row['created_at'],
                    $row['finished_at']
                ], ';');
            }
        } else {
            fputcsv($output, ['Nu există date inbound pentru selecția curentă'], ';');
        }

        writeEmptyRow($output);
    }

    if ($area === 'all' || $area === 'pickpack') {
        writeSectionTitle($output, 'Pick / Pack - Detaliat');
        fputcsv($output, ['Nr. comandă', 'Operator', 'Articole', 'Status', 'Creată la', 'Finalizată la'], ';');

        $query = "
            SELECT order_number, operator_name, items_count, status, created_at, finished_at, expected_finished_date
            FROM pickpack_orders
            WHERE $dateFilter
            ORDER BY created_at DESC
        ";
        $result = flx_query($conn, $query, $dateParams);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['order_number'],
                    $row['operator_name'],
                    $row['items_count'],
                    flx_pickpack_status($row),
                    $row['created_at'],
                    $row['finished_at']
                ], ';');
            }
        } else {
            fputcsv($output, ['Nu există date pick / pack pentru selecția curentă'], ';');
        }

        writeEmptyRow($output);
    }

    if ($area === 'all' || $area === 'outbound') {
        writeSectionTitle($output, 'Outbound - Detaliat');
        fputcsv($output, ['Nr. comandă', 'Destinație', 'Dată planificată', 'Zile întârziere', 'Status', 'Creată la', 'Finalizată la'], ';');

        $query = "
            SELECT order_number, destination, planned_date, status, created_at, finished_at
            FROM outbound_orders
            WHERE $dateFilter
            ORDER BY created_at DESC
        ";
        $result = flx_query($conn, $query, $dateParams);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['order_number'],
                    $row['destination'],
                    $row['planned_date'],
                    flx_outbound_delay_days($row),
                    flx_outbound_status($row),
                    $row['created_at'],
                    $row['finished_at']
                ], ';');
            }
        } else {
            fputcsv($output, ['Nu există date outbound pentru selecția curentă'], ';');
        }

        writeEmptyRow($output);
    }
}

fclose($output);
$conn->close();
exit;
?>