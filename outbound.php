<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$currentPage = 'outbound';
require_once 'config.php';
require_once 'flow_helpers.php';

// The role always comes from the session, never from the query string.
$roleKey = isset($_SESSION['role']) ? $_SESSION['role'] : 'default';
$period = isset($_GET['period']) ? $_GET['period'] : '7d';

$pageTitle = "Outbound";
$pageSubtitle = "Monitorizarea livrărilor, a punctualității, a capacității de expediere și a excepțiilor finale.";
$primaryButtonIcon = "bx-trending-up";
$primaryButtonText = "Analiză livrări";

if ($roleKey === 'tura') {
  $pageTitle = "Expedieri urgente";
  $pageSubtitle = "Controlul plecărilor curente, întârzierilor active și comenzilor aflate aproape de ETA critic.";
  $primaryButtonIcon = "bx-timer";
  $primaryButtonText = "Livrări în risc";
} elseif ($roleKey === 'depozit') {
  $pageTitle = "Outbound";
  $pageSubtitle = "Vizualizare completă a performanței de expediere și a stabilității livrărilor la nivel de depozit.";
  $primaryButtonIcon = "bx-analyse";
  $primaryButtonText = "Analiză outbound";
} elseif ($roleKey === 'manager') {
  $pageTitle = "Outbound overview";
  $pageSubtitle = "Perspectivă sintetică asupra punctualității livrărilor și a impactului asupra serviciului logistic.";
  $primaryButtonIcon = "bx-line-chart";
  $primaryButtonText = "Rezumat livrări";
}

// The period condition is fixed SQL text; only its two bounds are bound as parameters.
$dateCondition = "created_at BETWEEN ? AND ?";
$periodLabel = "Ultimele 7 zile";

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
} else {
  $dateParams = flx_period_params('7d');
  $periodLabel = "Ultimele 7 zile";
}

/* CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvQuery = "SELECT order_number, destination, created_at, planned_date, finished_at
                 FROM outbound_orders WHERE $dateCondition ORDER BY planned_date ASC";
    $csvResult = flx_query($conn, $csvQuery, $dateParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outbound-' . date('Y-m-d') . '.csv"');
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

/* KPI */
$totalQuery   = "SELECT COUNT(*) AS total FROM outbound_orders WHERE $dateCondition";
$readyQuery   = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) >= CURDATE() AND $dateCondition";
$shippedQuery = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NOT NULL AND $dateCondition";
$backlogQuery = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition";
$avgDelayQuery = "SELECT ROUND(AVG(
    CASE
        WHEN finished_at IS NOT NULL AND DATE(finished_at) > DATE(planned_date)
             THEN DATEDIFF(DATE(finished_at), DATE(planned_date))
        WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE()
             THEN DATEDIFF(CURDATE(), DATE(planned_date))
        ELSE NULL
    END
), 0) AS avg_delay FROM outbound_orders WHERE $dateCondition";

$tableQuery = "
  SELECT order_number, destination, planned_date, created_at, finished_at
  FROM outbound_orders
  WHERE $dateCondition
  ORDER BY planned_date DESC
";

$recentQuery = "
  SELECT order_number, destination, planned_date, created_at, finished_at
  FROM outbound_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

$backlogWarningsQuery = "
  SELECT order_number, destination,
    DATEDIFF(CURDATE(), DATE(planned_date)) AS days_overdue
  FROM outbound_orders
  WHERE finished_at IS NULL
    AND DATE(planned_date) < CURDATE()
    AND $dateCondition
  ORDER BY days_overdue DESC
  LIMIT 3
";

$statusChartQuery = "
  SELECT
    CASE
      WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE() THEN 'backlog'
      WHEN finished_at IS NULL THEN 'not_shipped'
      WHEN DATE(finished_at) <= DATE(planned_date) THEN 'shipped_on_time'
      ELSE 'shipped_late'
    END AS derived_status,
    COUNT(*) AS total
  FROM outbound_orders
  WHERE $dateCondition
  GROUP BY derived_status
";

$destinationChartQuery = "
  SELECT destination,
    AVG(
      CASE
        WHEN finished_at IS NOT NULL AND DATE(finished_at) > DATE(planned_date)
          THEN DATEDIFF(DATE(finished_at), DATE(planned_date))
        WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE()
          THEN DATEDIFF(CURDATE(), DATE(planned_date))
        ELSE 0
      END
    ) AS avg_delay_days
  FROM outbound_orders
  WHERE $dateCondition
  GROUP BY destination
  ORDER BY avg_delay_days DESC
  LIMIT 5
";

$totalResult            = flx_query($conn, $totalQuery, $dateParams);
$readyResult            = flx_query($conn, $readyQuery, $dateParams);
$shippedResult          = flx_query($conn, $shippedQuery, $dateParams);
$backlogResult          = flx_query($conn, $backlogQuery, $dateParams);
$avgDelayResult         = flx_query($conn, $avgDelayQuery, $dateParams);
$tableResult            = flx_query($conn, $tableQuery, $dateParams);
$recentResult           = flx_query($conn, $recentQuery, $dateParams);
$backlogWarningsResult  = flx_query($conn, $backlogWarningsQuery, $dateParams);
$statusChartResult      = flx_query($conn, $statusChartQuery, $dateParams);
$destinationChartResult = flx_query($conn, $destinationChartQuery, $dateParams);

$totalOutbound   = ($totalResult    && $totalResult->num_rows    > 0) ? (int)$totalResult->fetch_assoc()['total']           : 0;
$readyOutbound   = ($readyResult    && $readyResult->num_rows    > 0) ? (int)$readyResult->fetch_assoc()['total']           : 0;
$shippedOutbound = ($shippedResult  && $shippedResult->num_rows  > 0) ? (int)$shippedResult->fetch_assoc()['total']         : 0;
$backlogOutbound = ($backlogResult  && $backlogResult->num_rows  > 0) ? (int)$backlogResult->fetch_assoc()['total']         : 0;
$avgDelay        = ($avgDelayResult && $avgDelayResult->num_rows > 0) ? (int)$avgDelayResult->fetch_assoc()['avg_delay']    : 0;

$shippedPercent = ($totalOutbound > 0) ? round(($shippedOutbound / $totalOutbound) * 100) : 0;

$statusLabels = [];
$statusData = [];

if ($statusChartResult && $statusChartResult->num_rows > 0) {
  while ($row = $statusChartResult->fetch_assoc()) {
    $statusLabels[] = translateOutboundStatus($row['derived_status']);
    $statusData[] = (int)$row['total'];
  }
}

$destinationLabels = [];
$destinationData = [];

if ($destinationChartResult && $destinationChartResult->num_rows > 0) {
  while ($row = $destinationChartResult->fetch_assoc()) {
    $destinationLabels[] = $row['destination'];
    $destinationData[] = round((float)$row['avg_delay_days']);
  }
}

function translateOutboundStatus($status) {
  switch ($status) {
    case 'ready': return 'Pregătită';
    case 'shipped': return 'Expediată';
    case 'delayed': return 'Întârziată';
    case 'backlog': return 'Restanță';
    case 'not_shipped': return 'Neexpediat';
    case 'shipped_on_time': return 'Expediat la timp';
    case 'shipped_late': return 'Expediat cu întârziere';
    default: return ucfirst($status);
  }
}

function outboundStatusBadgeClass($status) {
  switch ($status) {
    case 'ready': return 'badge-primary';
    case 'shipped': return 'badge-success';
    case 'delayed': return 'badge-danger';
    case 'backlog': return 'badge-danger';
    case 'not_shipped': return 'badge-warning';
    case 'shipped_on_time': return 'badge-success';
    case 'shipped_late': return 'badge-danger';
    default: return 'badge-primary';
  }
}


function delayBadgeClass($delayDays) {
  if ((int)$delayDays <= 0) return 'badge-success';
  return 'badge-danger';
}

function delayLabel($delayDays) {
  if ((int)$delayDays <= 0) return 'La timp';
  return '+' . (int)$delayDays . ' zile';
}

$recommendations = [];

if ($backlogOutbound > 0) {
  $recommendations[] = [
    'title' => 'Prioritizează comenzile în backlog',
    'text' => 'Există ' . $backlogOutbound . ' comenzi neexpediate cu data planificată depășită. Necesită intervenție imediată.',
    'badge' => 'Urgent',
    'class' => 'badge-danger'
  ];
}

if ($readyOutbound > 0) {
  $recommendations[] = [
    'title' => 'Monitorizează comenzile neexpediate',
    'text' => 'Sunt ' . $readyOutbound . ' comenzi neexpediate cu data planificată în termen. Urmărește expedierea la timp.',
    'badge' => 'Prioritar',
    'class' => 'badge-warning'
  ];
}

if ($avgDelay > 0) {
  $recommendations[] = [
    'title' => 'Reduce întârzierea medie',
    'text' => 'Întârzierea medie actuală este de ' . $avgDelay . ' zile. Se recomandă optimizarea fluxului outbound.',
    'badge' => 'Monitorizare',
    'class' => 'badge-primary'
  ];
}

while (count($recommendations) < 3) {
  $recommendations[] = [
    'title' => 'Menține stabilitatea outbound',
    'text' => 'Fluxul de expediere trebuie să rămână constant pentru susținerea punctualității livrărilor.',
    'badge' => 'Stabil',
    'class' => 'badge-success'
  ];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Outbound</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=14">
  <link rel="stylesheet" href="css/styles.css?v=14">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="js/app.js?v=<?php echo time(); ?>" defer></script>

  <style>
    .card {
      display: flex;
      flex-direction: column;
    }

    .card-header {
      flex-shrink: 0;
    }

    .chart-bar-wrapper,
    .chart-wrap {
      flex: 1;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .chart-bar-wrapper canvas,
    .chart-wrap canvas {
      width: 100% !important;
      height: 100% !important;
    }
  </style>
</head>
<body data-page="outbound">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Outbound</strong>
      </div>

      <div class="topbar">
        <div class="topbar-left">
          <h1><?php echo $pageTitle; ?></h1>
          <p><?php echo $pageSubtitle; ?></p>
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

          <a href="outbound.php?period=<?php echo urlencode($period); ?>&export=csv" class="control-button primary">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>
      </div>

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Comenzi înregistrate</span>
            <span class="kpi-icon"><i class='bx bx-send'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalOutbound; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Expediate</span>
            <span class="kpi-icon"><i class='bx bx-check-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $shippedOutbound; ?></div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo $shippedPercent; ?>%"></div>
          </div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Întârziere medie</span>
            <span class="kpi-icon"><i class='bx bx-stopwatch'></i></span>
          </div>
          <div class="kpi-value"><?php echo $avgDelay; ?><span style="font-size:0.6em;"> zile</span></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">În pregătire</span>
            <span class="kpi-icon"><i class='bx bx-loader-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $readyOutbound; ?></div>
        </div>
      </div>

      <?php if ($backlogWarningsResult && $backlogWarningsResult->num_rows > 0): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Restanțe outbound</h3>
            <p>Elemente calculate automat din datele operaționale.</p>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nr. comandă</th>
                <th>Destinație</th>
                <th>Restanță</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($warn = $backlogWarningsResult->fetch_assoc()): ?>
                <?php
                  $daysOver   = (int)$warn['days_overdue'];
                  $badgeClass = $daysOver >= 3 ? 'badge-danger' : ($daysOver >= 1 ? 'badge-warning' : 'badge-primary');
                ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($warn['order_number']); ?></strong></td>
                  <td><?php echo htmlspecialchars($warn['destination']); ?></td>
                  <td>
                    <span class="small-badge <?php echo $badgeClass; ?>">
                      <?php echo $daysOver > 0 ? '+' . $daysOver . ' zile' : 'Azi'; ?>
                    </span>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="cards-grid two-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Status comenzi outbound</h3>
              <p>Distribuția comenzilor după status pentru <?php echo $periodLabel; ?>.</p>
            </div>
          </div>
          <div class="chart-bar-wrapper">
            <canvas id="outboundMainChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Întârzieri medii pe destinații</h3>
            </div>
          </div>
          <div class="chart-bar-wrapper" style="height: 260px;">
            <canvas id="outboundDaysChart"></canvas>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Comenzi outbound înregistrate</h3>
          </div>
        </div>

        <div class="table-scroll">
          <table class="data-table" id="table-outbound">
            <thead>
              <tr>
                <th>Nr. comandă</th>
                <th>Destinație</th>
                <th>Dată planificată</th>
                <th>Întârziere</th>
                <th>Status</th>
                <th>Creată la</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($tableResult && $tableResult->num_rows > 0): ?>
                <?php $totalTableRows = $tableResult->num_rows; $rowIndex = 0; ?>
                <?php while ($row = $tableResult->fetch_assoc()): $rowIndex++; ?>
                  <?php
                    $derivedStatus = flx_outbound_status($row);
                    $delayDays     = flx_outbound_delay_days($row);
                    $rowClass = $rowIndex > 10 ? ' class="extra-row hidden-row"' : '';
                  ?>
                  <tr<?php echo $rowClass; ?>>
                    <td><?php echo htmlspecialchars($row['order_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['destination']); ?></td>
                    <td><?php echo date('d.m.Y H:i', strtotime($row['planned_date'])); ?></td>
                    <td>
                      <span class="small-badge <?php echo delayBadgeClass($delayDays); ?>">
                        <?php echo delayLabel($delayDays); ?>
                      </span>
                    </td>
                    <td>
                      <span class="small-badge <?php echo outboundStatusBadgeClass($derivedStatus); ?>">
                        <?php echo translateOutboundStatus($derivedStatus); ?>
                      </span>
                    </td>
                    <td><?php echo date('d.m.Y H:i', strtotime($row['created_at'])); ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6">Nu există comenzi în baza de date pentru perioada selectată.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (!empty($totalTableRows) && $totalTableRows > 10): ?>
          <div class="table-actions">
            <p class="table-extra-note">Se afișează primele 10 comenzi din <?php echo $totalTableRows; ?> din perioada selectată.</p>
            <button type="button" class="toggle-table-btn" data-table-target="table-outbound" data-expanded="0">Afișează toate</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <div class="modal" id="globalModal">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title">Analiză outbound</h3>
      </div>
      <div class="modal-body">
        <p><strong>Observație principală:</strong> această pagină este conectată la baza de date MySQL și folosește date reale din tabelul <code>outbound_orders</code>.</p>
        <p>KPI-urile, avertizările de backlog, tabelul și graficele sunt alimentate prin interogări și agregări simple direct din baza de date, filtrate după perioada selectată.</p>
      </div>
      <div class="modal-footer">
        <button class="control-button" id="closeModalBtn">Închide</button>
      </div>
    </div>
  </div>

  <style>
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

  <script>
    window.outboundMainChartData = {
      labels: <?php echo json_encode($statusLabels); ?>,
      data: <?php echo json_encode($statusData); ?>
    };

    window.outboundDaysChartData = {
      labels: <?php echo json_encode($destinationLabels); ?>,
      data: <?php echo json_encode($destinationData); ?>
    };
  </script>

  <!-- Saved color theme -->
  <script>
    (function() {
      const themes = ["theme-flowlogix-classic", "theme-flowlogix-ocean", "theme-flowlogix-orange", "theme-flowlogix-dark", "theme-flowlogix-ice"];
      const savedTheme = localStorage.getItem("flowlogix_theme");
      if (savedTheme && themes.includes(savedTheme)) {
        document.documentElement.setAttribute("data-theme", savedTheme);
        document.body.classList.add(savedTheme);
      }
    })();
  </script>

  <?php $conn->close(); ?>
</body>
</html>