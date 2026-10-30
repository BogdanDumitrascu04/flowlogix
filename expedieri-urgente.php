<?php
session_start();

// PROTECȚIE: Doar role 'tura' poate accesa
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'tura') {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

require_once 'config.php';
require_once 'flow_helpers.php';

$currentPage = 'expedieri-urgente';

$period = isset($_GET['period']) ? $_GET['period'] : 'today';
// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
$dateCondition = "created_at BETWEEN ? AND ?";
$dateParams = flx_period_params('today');

if ($period === '7d') {
  $dateParams = flx_period_params('7d');
} elseif ($period === '30d') {
  $dateParams = flx_period_params('30d');
} elseif ($period === 'month') {
  $dateParams = flx_period_params('month');
} else {
  $period = 'today';
}

/* Outbound KPIs use planned_date; shipped-late uses finished_at */
$plannedDateCondition = "planned_date BETWEEN ? AND ?";
$plannedParams = flx_period_params('today');
$finishedAtCondition = "finished_at BETWEEN ? AND ?";
$finishedParams = flx_period_params('today');
if ($period === '7d') {
    $plannedParams = flx_period_params('7d');
    $finishedParams = flx_period_params('7d');
} elseif ($period === '30d') {
    $plannedParams = flx_period_params('30d');
    $finishedParams = flx_period_params('30d');
} elseif ($period === 'month') {
    $plannedParams = flx_period_params('month');
    $finishedParams = flx_period_params('month');
}

/* CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvQuery = "SELECT order_number, destination, created_at, planned_date, finished_at
                 FROM outbound_orders WHERE $plannedDateCondition ORDER BY planned_date ASC";
    $csvResult = flx_query($conn, $csvQuery, $plannedParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expedieri-urgente-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    flx_csv_row($output, ['Nr. comandă', 'Destinație', 'Status derivat', 'Data creare', 'Data planificată', 'Data finalizare', 'Zile restanță']);
    if ($csvResult) {
        while ($row = $csvResult->fetch_assoc()) {
            $derivedStatus = flx_outbound_status($row);
            $delayDays     = flx_outbound_delay_days($row);
            flx_csv_row($output, [
                $row['order_number'],
                $row['destination'],
                $derivedStatus,
                $row['created_at'],
                $row['planned_date'] ?? '',
                $row['finished_at'] ?? '',
                $delayDays,
            ]);
        }
    }
    fclose($output);
    exit;
}

// Query-uri pentru outbound urgent (derived logic — fără dependență de status/delay_minutes stocate)
$readyQuery = "SELECT COUNT(*) as total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) >= CURDATE() AND $plannedDateCondition";
$delayedQuery = "SELECT COUNT(*) as total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $plannedDateCondition";
$priorityQuery = "SELECT COUNT(*) as total FROM outbound_orders WHERE finished_at IS NOT NULL AND DATE(finished_at) > DATE(planned_date) AND $finishedAtCondition";
$avgDelayQuery = "SELECT ROUND(AVG(
    CASE
        WHEN finished_at IS NOT NULL AND DATE(finished_at) > DATE(planned_date)
             THEN DATEDIFF(DATE(finished_at), DATE(planned_date))
        WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE()
             THEN DATEDIFF(CURDATE(), DATE(planned_date))
        ELSE NULL
    END
), 0) as avg_delay FROM outbound_orders WHERE $plannedDateCondition";

// Tabel expedieri urgente
$tableQuery = "SELECT
    order_number,
    destination,
    status,
    delay_minutes,
    created_at,
    planned_date,
    finished_at,
    TIMEDIFF(NOW(), created_at) as time_in_system
FROM outbound_orders
WHERE $plannedDateCondition
ORDER BY
    CASE WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE() THEN 0 ELSE 1 END ASC,
    planned_date ASC";

// Grafic: distribuție status derivat
$statusQuery = "SELECT
    CASE
        WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE() THEN 'backlog'
        WHEN finished_at IS NULL THEN 'not_shipped'
        WHEN DATE(finished_at) <= DATE(planned_date) THEN 'shipped_on_time'
        ELSE 'shipped_late'
    END as derived_status,
    COUNT(*) as total
FROM outbound_orders
WHERE $plannedDateCondition
GROUP BY derived_status";

$readyResult = flx_query($conn, $readyQuery, $plannedParams);
$delayedResult = flx_query($conn, $delayedQuery, $plannedParams);
$priorityResult = flx_query($conn, $priorityQuery, $finishedParams);
$avgDelayResult = flx_query($conn, $avgDelayQuery, $plannedParams);
$tableResult = flx_query($conn, $tableQuery, $plannedParams);
$statusResult = flx_query($conn, $statusQuery, $plannedParams);

$ready = ($readyResult && $readyResult->num_rows > 0) ? (int)$readyResult->fetch_assoc()['total'] : 0;
$delayed = ($delayedResult && $delayedResult->num_rows > 0) ? (int)$delayedResult->fetch_assoc()['total'] : 0;
$priority = ($priorityResult && $priorityResult->num_rows > 0) ? (int)$priorityResult->fetch_assoc()['total'] : 0;
$avgDelay = ($avgDelayResult && $avgDelayResult->num_rows > 0) ? round((float)$avgDelayResult->fetch_assoc()['avg_delay']) : 0;

// Prelucrează date pentru grafic (status derivat cu etichete românești)
$statusLabels = [];
$statusData = [];
$statusTranslationsOutbound = [
    'backlog'         => 'Restanțe',
    'not_shipped'     => 'Neexpediate',
    'shipped_on_time' => 'Expediate la timp',
    'shipped_late'    => 'Expediate cu întârziere',
];
if ($statusResult && $statusResult->num_rows > 0) {
    while ($row = $statusResult->fetch_assoc()) {
        $key = $row['derived_status'] ?? '';
        $statusLabels[] = $statusTranslationsOutbound[$key] ?? ucfirst(str_replace('_', ' ', $key));
        $statusData[] = $row['total'];
    }
}
$tableStatusRo  = [
    'backlog'         => 'Restanță',
    'not_shipped'     => 'Neexpediat',
    'shipped_on_time' => 'Expediat la timp',
    'shipped_late'    => 'Expediat cu întârziere',
];
$tableStatusCss = [
    'backlog'         => 'badge-backlog',
    'not_shipped'     => 'badge-not_shipped',
    'shipped_on_time' => 'badge-shipped_on_time',
    'shipped_late'    => 'badge-shipped_late',
];
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowLogix | Expedieri urgente</title>
    <link rel="stylesheet" href="css/sidebar.css?v=14">
    <link rel="stylesheet" href="css/styles.css?v=14">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="expedieri-urgente" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Expedieri urgente</strong>
      </div>

      <div class="topbar role-topbar role-tura">
        <div class="topbar-left">
          <h1>Expedieri urgente</h1>
          <p>Controlul plecărilor curente, întârzierilor active și comenzilor cu risc de depășire ETA.</p>
        </div>

        <div class="topbar-right">
          <form method="GET" action="" style="display:flex; gap:12px; align-items:center;">
            <select class="control-select" name="period" onchange="this.form.submit()">
              <option value="today" <?php echo ($period === 'today') ? 'selected' : ''; ?>>Astăzi</option>
              <option value="7d" <?php echo ($period === '7d') ? 'selected' : ''; ?>>Ultimele 7 zile</option>
              <option value="30d" <?php echo ($period === '30d') ? 'selected' : ''; ?>>Ultimele 30 zile</option>
              <option value="month" <?php echo ($period === 'month') ? 'selected' : ''; ?>>Luna curentă</option>
            </select>
          </form>

          <a href="expedieri-urgente.php?period=<?php echo urlencode($period); ?>&export=csv" class="control-button primary">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>
      </div>

      <div class="cards-grid kpi-grid">
                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Gata de expediat</span>
                        <span class="kpi-icon">📤</span>
                    </div>
                    <div class="kpi-value"><?php echo $ready; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Întârziate</span>
                        <span class="kpi-icon">⏱️</span>
                    </div>
                    <div class="kpi-value"><?php echo $delayed; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Expediate cu întârziere</span>
                        <span class="kpi-icon">🔴</span>
                    </div>
                    <div class="kpi-value"><?php echo $priority; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Întârziere medie</span>
                        <span class="kpi-icon">⏳</span>
                    </div>
                    <div class="kpi-value"><?php echo $avgDelay; ?><span style="font-size: 0.6em;"> zile</span></div>
                </div>
            </div>

      <div class="monitoring-grid">
        <!-- Tabel expedieri -->
        <div class="monitoring-table">
          <div class="card">
            <div class="card-header">
              <div class="card-title-wrap">
                <h3>Comenzi în monitorizare</h3>
              </div>
            </div>
            <div class="table-wrap">
              <table class="data-table" id="table-expedieri-urgente">
                <thead>
                  <tr>
                    <th>Comandă</th>
                    <th>Destinație</th>
                    <th>Status</th>
                    <th>Întârziere</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($tableResult && $tableResult->num_rows > 0): ?>
                    <?php $totalTableRows = $tableResult->num_rows; $rowIndex = 0; ?>
                    <?php while ($row = $tableResult->fetch_assoc()): $rowIndex++; ?>
                      <?php
                        $derivedStatus = flx_outbound_status($row);
                        $delayDays     = flx_outbound_delay_days($row);
                        $rowClass = $rowIndex > 10 ? 'status-' . $derivedStatus . ' extra-row hidden-row' : 'status-' . $derivedStatus;
                      ?>
                      <tr class="<?php echo $rowClass; ?>">
                        <td><strong><?php echo $row['order_number']; ?></strong></td>
                        <td><?php echo htmlspecialchars($row['destination']); ?></td>
                        <td>
                          <span class="badge <?php echo $tableStatusCss[$derivedStatus] ?? 'badge-secondary'; ?>">
                            <?php echo $tableStatusRo[$derivedStatus] ?? ucfirst(str_replace('_', ' ', $derivedStatus)); ?>
                          </span>
                        </td>
                        <td>
                          <?php if ($delayDays > 0): ?>
                            <span style="color: #dc2626; font-weight: bold;">+<?php echo $delayDays; ?> zile</span>
                          <?php else: ?>
                            <span style="color: #16a34a;">La timp</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="4" style="text-align: center; color: #999;">Nicio expediere în sistem</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <?php if (!empty($totalTableRows) && $totalTableRows > 10): ?>
              <div class="table-actions">
                <p class="table-extra-note">Se afișează primele 10 expedieri din <?php echo $totalTableRows; ?> din perioada selectată.</p>
                <button type="button" class="toggle-table-btn" data-table-target="table-expedieri-urgente" data-expanded="0">Afișează toate</button>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Grafic status -->
        <div class="monitoring-chart">
          <div class="card">
            <div class="card-header">
              <div class="card-title-wrap">
                <h3>Distribuție status</h3>
              </div>
            </div>
            <div class="chart-wrap">
              <canvas id="statusChart"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    const statusLabels = <?php echo json_encode($statusLabels); ?>;
    const statusData = <?php echo json_encode($statusData); ?>;

    if (statusLabels.length > 0) {
      const ctx = document.getElementById('statusChart').getContext('2d');
      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: statusLabels,
          datasets: [{
            data: statusData,
            backgroundColor: ['#22c55e', '#dc2626', '#f59e0b', '#3b82f6'],
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              position: 'bottom'
            }
          }
        }
      });
    }
  </script>

  <style>
    .status-ready { background-color: rgba(34, 197, 94, 0.05); }
    .status-shipped { background-color: rgba(59, 130, 246, 0.05); }
    .status-delayed { background-color: rgba(220, 38, 38, 0.05); }
    .status-backlog { background-color: rgba(220, 38, 38, 0.05); }
    .status-not_shipped { background-color: rgba(156, 163, 175, 0.05); }
    .status-shipped_on_time { background-color: rgba(34, 197, 94, 0.05); }
    .status-shipped_late { background-color: rgba(220, 38, 38, 0.05); }
    .badge-backlog { background-color: #dc2626; color: #fff; }
    .badge-not_shipped { background-color: #6b7280; color: #fff; }
    .badge-shipped_on_time { background-color: #16a34a; color: #fff; }
    .badge-shipped_late { background-color: #dc2626; color: #fff; }
    .hidden-row { display: none; }
    .table-actions { display: flex; align-items: center; gap: 12px; padding: 10px 16px 4px; flex-wrap: wrap; }
    .table-extra-note { font-size: 0.82rem; color: #6b7280; margin: 0; }
    .toggle-table-btn { font-size: 0.82rem; padding: 4px 12px; border: 1px solid var(--primary, #3b82f6); background: transparent; color: var(--primary, #3b82f6); border-radius: 6px; cursor: pointer; }
    .toggle-table-btn:hover { background: var(--primary, #3b82f6); color: #fff; }
  </style>

  <script>
    document.querySelectorAll('.toggle-table-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var expanded = btn.getAttribute('data-expanded') === '1';
        var tableId  = btn.getAttribute('data-table-target');
        var table    = document.getElementById(tableId);
        if (!table) return;
        table.querySelectorAll('.extra-row').forEach(function (row) {
          row.classList.toggle('hidden-row', expanded);
        });
        expanded = !expanded;
        btn.setAttribute('data-expanded', expanded ? '1' : '0');
        btn.textContent = expanded ? 'Afișează mai puține' : 'Afișează toate';
      });
    });
  </script>
</body>
</html>
