<?php
session_start();

// PROTECȚIE: Doar role 'depozit' poate accesa
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'depozit') {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

$currentPage = 'dashboard-depozit';
require_once 'config.php';
require_once 'flow_helpers.php';

// Utilizează rolul din sesiune, nu din GET
$roleKey = isset($_SESSION['role']) ? $_SESSION['role'] : 'default';
$period = isset($_GET['period']) ? $_GET['period'] : '30d';

$pageTitle = "Dashboard depozit";
$pageSubtitle = "Privire de ansamblu asupra fluxurilor logistice, capacității operaționale și performanței generale a depozitului.";
$primaryButtonIcon = "bx-buildings";
$primaryButtonText = "Analiză depozit";

// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
$dateCondition = "created_at BETWEEN ? AND ?";
$periodLabel = "Ultimele 30 zile";

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
  $dateParams = flx_period_params('30d');
  $periodLabel = "Ultimele 30 zile";
}

/* KPI principale */
$inboundTotalQuery = "SELECT COUNT(*) AS total FROM inbound_orders WHERE $dateCondition";
$pickpackTotalQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE $dateCondition";
$outboundTotalQuery = "SELECT COUNT(*) AS total FROM outbound_orders WHERE $dateCondition";
$pickpackBacklogQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition";

/* detalii pe status */
$inboundActiveQuery    = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND $dateCondition";
$inboundBacklogQuery   = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()) AND $dateCondition";
$inboundCompletedQuery = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NOT NULL AND $dateCondition";

$pickpackInProgressQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND $dateCondition";
$pickpackCompletedQuery  = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NOT NULL AND $dateCondition";

$outboundReadyQuery   = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) >= CURDATE() AND $dateCondition";
$outboundShippedQuery = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NOT NULL AND $dateCondition";
$outboundBacklogQuery = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition";

/* activitate recentă */
$recentInboundQuery = "
  SELECT reception_number AS ref_code, supplier_name AS label_text, 'Inbound' AS module_name, status, created_at
  FROM inbound_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

$recentPickpackQuery = "
  SELECT order_number AS ref_code, operator_name AS label_text, 'Pick / Pack' AS module_name, status, created_at
  FROM pickpack_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

$recentOutboundQuery = "
  SELECT order_number AS ref_code, destination AS label_text, 'Outbound' AS module_name, status, created_at
  FROM outbound_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

/* excepții prioritare */
$exceptionsInboundQuery = "
  SELECT reception_number AS ref_code, 'Inbound' AS module_name, 'Recepție prioritate ridicată' AS issue_text,
         finished_at, expected_finished_date, created_at
  FROM inbound_orders
  WHERE finished_at IS NULL
    AND (expected_finished_date IS NOT NULL AND expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
    AND $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

$exceptionsPickpackQuery = "
  SELECT order_number AS ref_code, 'Pick / Pack' AS module_name, 'Comandă restantă' AS issue_text,
         operator_name, finished_at, expected_finished_date, created_at
  FROM pickpack_orders
  WHERE finished_at IS NULL AND expected_finished_date IS NOT NULL AND expected_finished_date < CURDATE()
    AND $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

$exceptionsOutboundQuery = "
  SELECT order_number AS ref_code, 'Outbound' AS module_name,
         CONCAT('Restanță ', DATEDIFF(CURDATE(), DATE(planned_date)), ' zile') AS issue_text,
         finished_at, planned_date, created_at
  FROM outbound_orders
  WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

/* grafice */
$flowChartQuery = "
  SELECT 'Inbound' AS flow_name, (SELECT COUNT(*) FROM inbound_orders WHERE $dateCondition) AS total
  UNION ALL
  SELECT 'Pick / Pack' AS flow_name, (SELECT COUNT(*) FROM pickpack_orders WHERE $dateCondition) AS total
  UNION ALL
  SELECT 'Outbound' AS flow_name, (SELECT COUNT(*) FROM outbound_orders WHERE $dateCondition) AS total
";

$capacityChartQuery = "
  SELECT 'Inbound' AS zone_name, (SELECT COUNT(*) FROM inbound_orders WHERE finished_at IS NULL AND $dateCondition) AS total
  UNION ALL
  SELECT 'Picking' AS zone_name, (SELECT COUNT(*) FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND $dateCondition) AS total
  UNION ALL
  SELECT 'Packing' AS zone_name, (SELECT COUNT(*) FROM pickpack_orders WHERE finished_at IS NOT NULL AND $dateCondition) AS total
  UNION ALL
  SELECT 'Outbound' AS zone_name, (SELECT COUNT(*) FROM outbound_orders WHERE finished_at IS NULL AND $dateCondition) AS total
";

$trendChartQuery = "
  SELECT day_label, SUM(total_events) AS total_events
  FROM (
    SELECT DATE(created_at) AS day_label, COUNT(*) AS total_events
    FROM inbound_orders
    WHERE $dateCondition
    GROUP BY DATE(created_at)

    UNION ALL

    SELECT DATE(created_at) AS day_label, COUNT(*) AS total_events
    FROM pickpack_orders
    WHERE $dateCondition
    GROUP BY DATE(created_at)

    UNION ALL

    SELECT DATE(created_at) AS day_label, COUNT(*) AS total_events
    FROM outbound_orders
    WHERE $dateCondition
    GROUP BY DATE(created_at)
  ) combined
  GROUP BY day_label
";

/* execuție query-uri */
$inboundTotalResult = flx_query($conn, $inboundTotalQuery, $dateParams);
$pickpackTotalResult = flx_query($conn, $pickpackTotalQuery, $dateParams);
$outboundTotalResult = flx_query($conn, $outboundTotalQuery, $dateParams);
$pickpackBacklogResult = flx_query($conn, $pickpackBacklogQuery, $dateParams);

$inboundActiveResult    = flx_query($conn, $inboundActiveQuery, $dateParams);
$inboundBacklogResult   = flx_query($conn, $inboundBacklogQuery, $dateParams);
$inboundCompletedResult = flx_query($conn, $inboundCompletedQuery, $dateParams);

$pickpackInProgressResult = flx_query($conn, $pickpackInProgressQuery, $dateParams);
$pickpackCompletedResult  = flx_query($conn, $pickpackCompletedQuery, $dateParams);

$outboundReadyResult   = flx_query($conn, $outboundReadyQuery, $dateParams);
$outboundShippedResult = flx_query($conn, $outboundShippedQuery, $dateParams);
$outboundBacklogResult = flx_query($conn, $outboundBacklogQuery, $dateParams);

$recentInboundResult = flx_query($conn, $recentInboundQuery, $dateParams);
$recentPickpackResult = flx_query($conn, $recentPickpackQuery, $dateParams);
$recentOutboundResult = flx_query($conn, $recentOutboundQuery, $dateParams);

$exceptionsInboundResult = flx_query($conn, $exceptionsInboundQuery, $dateParams);
$exceptionsPickpackResult = flx_query($conn, $exceptionsPickpackQuery, $dateParams);
$exceptionsOutboundResult = flx_query($conn, $exceptionsOutboundQuery, $dateParams);

$flowChartResult = flx_query($conn, $flowChartQuery, array_merge($dateParams, $dateParams, $dateParams));
$capacityChartResult = flx_query($conn, $capacityChartQuery, array_merge($dateParams, $dateParams, $dateParams, $dateParams));
$trendChartResult = flx_query($conn, $trendChartQuery, array_merge($dateParams, $dateParams, $dateParams));

/* valori */
$inboundTotal = ($inboundTotalResult && $inboundTotalResult->num_rows > 0) ? (int)$inboundTotalResult->fetch_assoc()['total'] : 0;
$pickpackTotal = ($pickpackTotalResult && $pickpackTotalResult->num_rows > 0) ? (int)$pickpackTotalResult->fetch_assoc()['total'] : 0;
$outboundTotal = ($outboundTotalResult && $outboundTotalResult->num_rows > 0) ? (int)$outboundTotalResult->fetch_assoc()['total'] : 0;
$inboundActive    = ($inboundActiveResult   && $inboundActiveResult->num_rows   > 0) ? (int)$inboundActiveResult->fetch_assoc()['total']   : 0;
$inboundBacklog   = ($inboundBacklogResult  && $inboundBacklogResult->num_rows  > 0) ? (int)$inboundBacklogResult->fetch_assoc()['total']  : 0;
$inboundCompleted = ($inboundCompletedResult && $inboundCompletedResult->num_rows > 0) ? (int)$inboundCompletedResult->fetch_assoc()['total'] : 0;

$pickpackInProgress = ($pickpackInProgressResult && $pickpackInProgressResult->num_rows > 0) ? (int)$pickpackInProgressResult->fetch_assoc()['total'] : 0;
$pickpackCompleted  = ($pickpackCompletedResult  && $pickpackCompletedResult->num_rows  > 0) ? (int)$pickpackCompletedResult->fetch_assoc()['total']  : 0;
$pickpackBacklog    = ($pickpackBacklogResult    && $pickpackBacklogResult->num_rows    > 0) ? (int)$pickpackBacklogResult->fetch_assoc()['total']    : 0;

$outboundReady   = ($outboundReadyResult   && $outboundReadyResult->num_rows   > 0) ? (int)$outboundReadyResult->fetch_assoc()['total']   : 0;
$outboundShipped = ($outboundShippedResult && $outboundShippedResult->num_rows > 0) ? (int)$outboundShippedResult->fetch_assoc()['total'] : 0;
$outboundBacklog = ($outboundBacklogResult && $outboundBacklogResult->num_rows > 0) ? (int)$outboundBacklogResult->fetch_assoc()['total'] : 0;

$activeAlerts = $inboundBacklog + $pickpackBacklog + $outboundBacklog;

/* procente simple */
$totalAllFlows = $inboundTotal + $pickpackTotal + $outboundTotal;
$capacityUsed = ($totalAllFlows > 0) ? min(100, round((($inboundActive + $pickpackInProgress + $outboundReady + $outboundBacklog) / $totalAllFlows) * 100)) : 0;
$livrariFinalizateProcent = ($outboundTotal > 0) ? round(($outboundShipped / $outboundTotal) * 100, 1) : 0;

/* funcții */
function severityCardClass($severity) {
  switch ($severity) {
    case 'high': return 'danger';
    case 'medium': return 'warning';
    case 'low': return 'success';
    default: return 'warning';
  }
}

function translateGenericStatus($status) {
  switch ($status) {
    case 'pending': return 'În așteptare';
    case 'active': return 'Activă';
    case 'processing': return 'În procesare';
    case 'completed': return 'Finalizată';
    case 'not_started': return 'Neîncepută';
    case 'in_progress': return 'În execuție';
    case 'ready': return 'Pregătită';
    case 'shipped': return 'Expediată';
    case 'delayed': return 'Întârziată';
    case 'backlog': return 'Restanță';
    case 'not_shipped': return 'Neexpediat';
    case 'shipped_on_time': return 'Expediat la timp';
    case 'shipped_late': return 'Expediat cu întârziere';
    default: return $status;
  }
}

function statusBadgeClassGeneric($status) {
  switch ($status) {
    case 'completed':
    case 'shipped':
    case 'shipped_on_time':
      return 'badge-success';
    case 'processing':
    case 'in_progress':
    case 'active':
    case 'ready':
    case 'not_shipped':
      return 'badge-primary';
    case 'pending':
    case 'not_started':
      return 'badge-warning';
    case 'delayed':
    case 'backlog':
    case 'shipped_late':
      return 'badge-danger';
    default:
      return 'badge-primary';
  }
}

/* activitate recentă combinată */
$recentActivity = [];

if ($recentInboundResult && $recentInboundResult->num_rows > 0) {
  while ($row = $recentInboundResult->fetch_assoc()) $recentActivity[] = $row;
}
if ($recentPickpackResult && $recentPickpackResult->num_rows > 0) {
  while ($row = $recentPickpackResult->fetch_assoc()) $recentActivity[] = $row;
}
if ($recentOutboundResult && $recentOutboundResult->num_rows > 0) {
  while ($row = $recentOutboundResult->fetch_assoc()) $recentActivity[] = $row;
}

usort($recentActivity, function($a, $b) {
  return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$recentActivity = array_slice($recentActivity, 0, 6);

/* excepții */
$exceptions = [];

if ($exceptionsInboundResult && $exceptionsInboundResult->num_rows > 0) {
  while ($row = $exceptionsInboundResult->fetch_assoc()) $exceptions[] = $row;
}
if ($exceptionsPickpackResult && $exceptionsPickpackResult->num_rows > 0) {
  while ($row = $exceptionsPickpackResult->fetch_assoc()) $exceptions[] = $row;
}
if ($exceptionsOutboundResult && $exceptionsOutboundResult->num_rows > 0) {
  while ($row = $exceptionsOutboundResult->fetch_assoc()) $exceptions[] = $row;
}

usort($exceptions, function($a, $b) {
  return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$exceptions = array_slice($exceptions, 0, 6);

/* grafice */
$flowLabels = [];
$flowData = [];

if ($flowChartResult && $flowChartResult->num_rows > 0) {
  while ($row = $flowChartResult->fetch_assoc()) {
    $flowLabels[] = $row['flow_name'];
    $flowData[] = (int)$row['total'];
  }
}

$capacityLabels = [];
$capacityData = [];

if ($capacityChartResult && $capacityChartResult->num_rows > 0) {
  while ($row = $capacityChartResult->fetch_assoc()) {
    $capacityLabels[] = $row['zone_name'];
    $capacityData[] = (int)$row['total'];
  }
}

$trendRaw = [];
if ($trendChartResult && $trendChartResult->num_rows > 0) {
  while ($row = $trendChartResult->fetch_assoc()) {
    $key = $row['day_label'];
    if (!isset($trendRaw[$key])) {
      $trendRaw[$key] = 0;
    }
    $trendRaw[$key] += (int)$row['total_events'];
  }
}
ksort($trendRaw);

$trendLabels = [];
$trendData = [];
foreach ($trendRaw as $day => $value) {
  $trendLabels[] = date('d.m', strtotime($day));
  $trendData[] = $value;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Dashboard Șef de depozit</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="css/styles.css?v=<?php echo time(); ?>">
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

    .chart-flow-wrapper,
    .chart-bar-wrapper,
    .chart-wrap {
      flex: 1;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      max-height: 260px;
    }

    .chart-flow-wrapper canvas,
    .chart-bar-wrapper canvas,
    .chart-wrap canvas {
      width: 100% !important;
      max-height: 260px;
    }
  </style>
</head>
<body data-page="dashboard-depozit" data-role="depozit">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">

      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Dashboard Șef de depozit</strong>
      </div>

      <div class="topbar">
        <div class="topbar-left">
          <h1><?php echo $pageTitle; ?></h1>
          <p><?php echo $pageSubtitle; ?></p>
        </div>

        <div class="topbar-right">
          <form method="GET" action="dashboard-depozit.php" style="display:flex; gap:12px; align-items:center;">
            <select class="control-select" id="periodSelect" name="period" onchange="this.form.submit()">
              <option value="today" <?php echo ($period === 'today') ? 'selected' : ''; ?>>Astăzi</option>
              <option value="7d" <?php echo ($period === '7d') ? 'selected' : ''; ?>>Ultimele 7 zile</option>
              <option value="30d" <?php echo ($period === '30d') ? 'selected' : ''; ?>>Ultimele 30 zile</option>
              <option value="month" <?php echo ($period === 'month') ? 'selected' : ''; ?>>Luna curentă</option>
            </select>
          </form>

          <a href="raport-depozit.php?period=<?php echo urlencode($period); ?>" target="_blank" class="control-button primary">
            <i class='bx bx-file'></i>
            <span>Raport PDF</span>
          </a>
        </div>
      </div>

     


      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Recepții înregistrate</span>
            <span class="kpi-icon"><i class='bx bx-log-in-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $inboundTotal; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Comenzi pick / pack</span>
            <span class="kpi-icon"><i class='bx bx-package'></i></span>
          </div>
          <div class="kpi-value"><?php echo $pickpackTotal; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Livrări outbound</span>
            <span class="kpi-icon"><i class='bx bx-send'></i></span>
          </div>
          <div class="kpi-value"><?php echo $outboundTotal; ?></div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo min(100, round($livrariFinalizateProcent)); ?>%"></div>
          </div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Intervenții necesare</span>
            <span class="kpi-icon"><i class='bx bx-error-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $activeAlerts; ?></div>
        </div>
      </div>

      <div class="module-nav-grid">
        <a href="inbound.php?period=<?php echo urlencode($period); ?>" class="module-nav-card">
          <div class="module-nav-icon tag-inbound"><i class='bx bx-log-in-circle'></i></div>
          <div class="module-nav-body">
            <span class="module-nav-title">Inbound</span>
            <span class="module-nav-desc">Recepții și procesare furnizori</span>
          </div>
          <i class='bx bx-right-arrow-alt arrow'></i>
        </a>

        <a href="pickpack.php?period=<?php echo urlencode($period); ?>" class="module-nav-card">
          <div class="module-nav-icon tag-pickpack"><i class='bx bx-package'></i></div>
          <div class="module-nav-body">
            <span class="module-nav-title">Pick / Pack</span>
            <span class="module-nav-desc">Comenzi pregătite pentru expediție</span>
          </div>
          <i class='bx bx-right-arrow-alt arrow'></i>
        </a>

        <a href="outbound.php?period=<?php echo urlencode($period); ?>" class="module-nav-card">
          <div class="module-nav-icon tag-outbound"><i class='bx bx-send'></i></div>
          <div class="module-nav-body">
            <span class="module-nav-title">Outbound</span>
            <span class="module-nav-desc">Livrări și expedieri planificate</span>
          </div>
          <i class='bx bx-right-arrow-alt arrow'></i>
        </a>
      </div>

      <div class="cards-grid two-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Performanță pe fluxuri</h3>
              <p>Volumul agregat pe Inbound, Pick / Pack și Outbound.</p>
            </div>
            <span class="card-tag role-accent-tag">Overview</span>
          </div>
          <div class="chart-flow-wrapper">
            <canvas id="depotFlowChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Utilizare capacitate pe zone</h3>
              <p>Zonele cu activitate curentă ridicată.</p>
            </div>
            <span class="card-tag role-accent-tag">Capacitate</span>
          </div>
          <div class="chart-wrap chart-sm">
            <canvas id="depotCapacityChart"></canvas>
          </div>
        </div>
      </div>

      <div class="cards-grid two-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Evoluție activitate depozit</h3>
              <p>Total evenimente operaționale agregate pe zile.</p>
            </div>
          </div>

          <div class="chart-wrap">
            <canvas id="depotTrendChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Obiective operaționale</h3>
              <p>Indicatori sintetici ai depozitului.</p>
            </div>
          </div>

          <div class="progress-list">
            <div class="progress-row">
              <div class="row-top">
                <span>Recepții finalizate</span>
                <strong><?php echo $inboundCompleted; ?></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo min(100, $inboundCompleted * 15); ?>%"></div></div>
            </div>

            <div class="progress-row">
              <div class="row-top">
                <span>Pick / Pack finalizate</span>
                <strong><?php echo $pickpackCompleted; ?></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo min(100, $pickpackCompleted * 15); ?>%"></div></div>
            </div>

            <div class="progress-row">
              <div class="row-top">
                <span>Livrări expediate</span>
                <strong><?php echo $outboundShipped; ?></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo min(100, $outboundShipped * 15); ?>%"></div></div>
            </div>

            <div class="progress-row">
              <div class="row-top">
                <span>Intervenții necesare</span>
                <strong><?php echo $activeAlerts; ?></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo min(100, $activeAlerts * 20); ?>%"></div></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Excepții prioritare la nivel de depozit</h3>
            <p>Evenimente care necesită atenție la nivel tactic.</p>
          </div>
          <span class="card-tag tag-warning"><?php echo count($exceptions); ?> active</span>
        </div>

        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Referință</th>
                <th>Flux</th>
                <th>Problemă</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($exceptions)): ?>
                <?php foreach ($exceptions as $ex): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($ex['ref_code']); ?></td>
                    <td><?php echo htmlspecialchars($ex['module_name']); ?></td>
                    <td><?php echo htmlspecialchars($ex['issue_text']); ?></td>
                    <td>
                      <?php
                        if ($ex['module_name'] === 'Inbound') {
                          $derivedStatus = flx_inbound_status($ex);
                        } elseif ($ex['module_name'] === 'Pick / Pack') {
                          $derivedStatus = flx_pickpack_status($ex);
                        } else {
                          $derivedStatus = flx_outbound_status($ex);
                        }
                      ?>
                      <span class="small-badge <?php echo statusBadgeClassGeneric($derivedStatus); ?>">
                        <?php echo translateGenericStatus($derivedStatus); ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="4">Nu există excepții active în baza de date.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>


  <script>
    window.depotFlowChartData = {
      labels: <?php echo json_encode($flowLabels); ?>,
      data: <?php echo json_encode($flowData); ?>
    };

    window.depotCapacityChartData = {
      labels: <?php echo json_encode($capacityLabels); ?>,
      data: <?php echo json_encode($capacityData); ?>
    };

    window.depotTrendChartData = {
      labels: <?php echo json_encode($trendLabels); ?>,
      data: <?php echo json_encode($trendData); ?>
    };

    function changePeriod(periodValue) {
      const params = new URLSearchParams(window.location.search);
      params.set('period', periodValue);
      window.location.href = window.location.pathname + '?' + params.toString();
    }
  </script>

  <!-- Aplică tema din localStorage după ce body este încărcat -->
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