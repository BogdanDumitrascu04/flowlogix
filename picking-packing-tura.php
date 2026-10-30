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

$currentPage = 'picking-packing-tura';

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

$finishedAtCondition = "finished_at BETWEEN ? AND ?";
$finishedParams = flx_period_params('today');
if ($period === '7d') {
    $finishedParams = flx_period_params('7d');
} elseif ($period === '30d') {
    $finishedParams = flx_period_params('30d');
} elseif ($period === 'month') {
    $finishedParams = flx_period_params('month');
}

/* CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvQuery = "SELECT order_number, operator_name, items_count, created_at, finished_at, expected_finished_date
                 FROM pickpack_orders WHERE $dateCondition ORDER BY created_at DESC";
    $csvResult = flx_query($conn, $csvQuery, $dateParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="picking-packing-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    fputcsv($output, ['Nr. comandă', 'Operator', 'Status derivat', 'Număr articole', 'Data creare', 'Termen estimat', 'Data finalizare', 'Zile restanță'], ';');
    if ($csvResult) {
        while ($row = $csvResult->fetch_assoc()) {
            $derivedStatus = flx_pickpack_status($row);
            $operator = !empty($row['operator_name']) ? $row['operator_name'] : 'Neatribuit';
            $daysOverdue = 0;
            if (empty($row['finished_at']) && !empty($row['expected_finished_date'])) {
                $due   = strtotime(date('Y-m-d', strtotime($row['expected_finished_date'])));
                $today = strtotime(date('Y-m-d'));
                if ($today > $due) {
                    $daysOverdue = (int)(($today - $due) / 86400);
                }
            }
            fputcsv($output, [
                $row['order_number'],
                $operator,
                $derivedStatus,
                $row['items_count'],
                $row['created_at'],
                $row['expected_finished_date'] ?? '',
                $row['finished_at'] ?? '',
                $daysOverdue,
            ], ';');
        }
    }
    fclose($output);
    exit;
}

function formatElapsed(?string $t): string {
    if ($t === null || $t === '') return '—';
    $t = ltrim($t, '-');
    $parts = explode(':', $t);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    if ($h === 0 && $m === 0) return '< 1m';
    if ($h >= 24) { $d = intdiv($h, 24); return $d . ($d === 1 ? ' zi' : ' zile'); }
    return $h > 0 ? ($m > 0 ? $h . 'h ' . $m . 'm' : $h . 'h') : $m . 'm';
}
$statusRo  = ['backlog' => 'Restanță', 'in_progress' => 'În lucru', 'completed' => 'Finalizat', 'not_started' => 'Neînceput'];
$statusCss = ['backlog' => 'badge-danger', 'in_progress' => 'badge-primary', 'completed' => 'badge-success', 'not_started' => 'badge-warning'];

// Query-uri pentru pick/pack (derived logic — fără dependență de status stocat)
$inWorkQuery = "SELECT COUNT(*) as total FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND $dateCondition";
$completedPeriodQuery = "SELECT COUNT(*) as total FROM pickpack_orders WHERE finished_at IS NOT NULL AND $finishedAtCondition";
$priorityQuery = "SELECT COUNT(*) as total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition";
$totalItemsQuery = "SELECT SUM(items_count) as total FROM pickpack_orders WHERE ($dateCondition) OR ($finishedAtCondition)";

// Tabel comenzi pick/pack
$tableQuery = "SELECT
    order_number,
    operator_name,
    items_count,
    status,
    created_at,
    finished_at,
    expected_finished_date,
    CASE
        WHEN finished_at IS NOT NULL THEN TIMEDIFF(finished_at, created_at)
        WHEN operator_name IS NOT NULL AND operator_name <> '' THEN TIMEDIFF(NOW(), created_at)
        ELSE NULL
    END as processing_time
FROM pickpack_orders
WHERE $dateCondition
ORDER BY created_at DESC";

// Grafic: comenzi pe operator
$operatorQuery = "SELECT
    operator_name,
    COUNT(*) as total,
    SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) as completed
FROM pickpack_orders
WHERE $dateCondition
GROUP BY operator_name
ORDER BY total DESC";

$inWorkResult = flx_query($conn, $inWorkQuery, $dateParams);
$completedPeriodResult = flx_query($conn, $completedPeriodQuery, $finishedParams);
$priorityResult = flx_query($conn, $priorityQuery, $dateParams);
$itemsResult = flx_query($conn, $totalItemsQuery, array_merge($dateParams, $finishedParams));
$tableResult = flx_query($conn, $tableQuery, $dateParams);
$operatorResult = flx_query($conn, $operatorQuery, $dateParams);

$inWork = ($inWorkResult && $inWorkResult->num_rows > 0) ? (int)$inWorkResult->fetch_assoc()['total'] : 0;
$completedPeriod = ($completedPeriodResult && $completedPeriodResult->num_rows > 0) ? (int)$completedPeriodResult->fetch_assoc()['total'] : 0;
$priority = ($priorityResult && $priorityResult->num_rows > 0) ? (int)$priorityResult->fetch_assoc()['total'] : 0;
$totalItems = ($itemsResult && $itemsResult->num_rows > 0) ? (int)$itemsResult->fetch_assoc()['total'] : 0;

// Prelucrează date pentru grafic
$operatorLabels = [];
$operatorData = [];
if ($operatorResult && $operatorResult->num_rows > 0) {
    while ($row = $operatorResult->fetch_assoc()) {
        $operatorLabels[] = $row['operator_name'];
        $operatorData[] = $row['completed'];
    }
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowLogix | Pick/Pack operațional</title>
    <link rel="stylesheet" href="css/sidebar.css?v=14">
    <link rel="stylesheet" href="css/styles.css?v=14">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="picking-packing-tura" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Pick/Pack operațional</strong>
      </div>

      <div class="topbar role-topbar role-tura">
        <div class="topbar-left">
          <h1>Pick / Pack operațional</h1>
          <p>Control comenzilor în lucru, productivității operatorilor și excepțiilor de procesare.</p>
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

          <a href="picking-packing-tura.php?period=<?php echo urlencode($period); ?>&export=csv" class="control-button primary">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>
      </div>

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">În lucru</span>
            <span class="kpi-icon"><i class='bx bx-package'></i></span>
          </div>
          <div class="kpi-value"><?php echo $inWork; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Finalizate în perioadă</span>
            <span class="kpi-icon"><i class='bx bx-check-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $completedPeriod; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Restante</span>
            <span class="kpi-icon"><i class='bx bx-star'></i></span>
          </div>
          <div class="kpi-value"><?php echo $priority; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Articole total</span>
            <span class="kpi-icon"><i class='bx bx-list-check'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalItems; ?></div>
        </div>
      </div>

      <div class="cards-grid two-col">
        <!-- Tabel comenzi -->
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Comenzi în lucru</h3>
            </div>
          </div>
          <div class="table-wrap">
            <table class="data-table" id="table-pickpack-tura">
              <thead>
                <tr>
                  <th>Comandă</th>
                  <th>Operator</th>
                  <th>Articole</th>
                  <th>Status</th>
                  <th>Vechime</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($tableResult && $tableResult->num_rows > 0): ?>
                  <?php $totalTableRows = $tableResult->num_rows; $rowIndex = 0; ?>
                  <?php while ($row = $tableResult->fetch_assoc()): $rowIndex++; ?>
                  <?php
                    $derivedStatus = flx_pickpack_status($row);
                    $rowClass = $rowIndex > 10 ? 'status-' . $derivedStatus . ' extra-row hidden-row' : 'status-' . $derivedStatus;
                  ?>
                    <tr class="<?php echo $rowClass; ?>">
                      <td><strong><?php echo $row['order_number']; ?></strong></td>
                      <td><?php echo htmlspecialchars($row['operator_name']); ?></td>
                      <td><?php echo $row['items_count']; ?></td>
                      <td>
                        <span class="badge <?php echo $statusCss[$derivedStatus] ?? 'badge-secondary'; ?>">
                          <?php echo $statusRo[$derivedStatus] ?? ucfirst(str_replace('_', ' ', $derivedStatus)); ?>
                        </span>
                      </td>
                      <td><?php echo $row['processing_time'] ? formatElapsed($row['processing_time']) : '—'; ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" style="text-align: center; color: #999;">Nicio comandă în curs</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($totalTableRows) && $totalTableRows > 10): ?>
            <div class="table-actions">
              <p class="table-extra-note">Se afișează primele 10 comenzi din <?php echo $totalTableRows; ?> din perioada selectată.</p>
              <button type="button" class="toggle-table-btn" data-table-target="table-pickpack-tura" data-expanded="0">Afișează toate</button>
            </div>
          <?php endif; ?>
        </div>

        <!-- Grafic operatori -->
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Performanță operatori</h3>
            </div>
          </div>
          <div class="chart-wrap">
            <canvas id="operatorChart"></canvas>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    const operatorLabels = <?php echo json_encode($operatorLabels); ?>;
    const operatorData = <?php echo json_encode($operatorData); ?>;

    if (operatorLabels.length > 0) {
      const ctx = document.getElementById('operatorChart').getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: operatorLabels,
          datasets: [{
            label: 'Comenzi finalizate',
            data: operatorData,
            backgroundColor: '#3b82f6',
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: { display: false }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { stepSize: 1 }
            }
          }
        }
      });
    }
  </script>

  <style>
    .status-in_progress { background-color: rgba(59, 130, 246, 0.05); }
    .status-completed { background-color: rgba(34, 197, 94, 0.05); }
    .status-not_started { background-color: rgba(156, 163, 175, 0.05); }
    .status-backlog { background-color: rgba(220, 38, 38, 0.05); }
    .badge-backlog { background-color: #dc2626; color: #fff; }
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
