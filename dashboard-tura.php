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

$currentPage = 'dashboard-tura';

$userName = isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : 'Utilizator';

/* Filtrare perioadă */
$period = isset($_GET['period']) ? $_GET['period'] : 'today';
// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
$dateCondition = "created_at BETWEEN ? AND ?";
$periodLabel = "";

switch ($period) {
    case 'today':
        $dateParams = flx_period_params('today');
        $periodLabel = "Astăzi";
        break;
    case '7d':
        $dateParams = flx_period_params('7d');
        $periodLabel = "Ultimele 7 zile";
        break;
    case '30d':
        $dateParams = flx_period_params('30d');
        $periodLabel = "Ultimele 30 zile";
        break;
    case 'month':
        $dateParams = flx_period_params('month');
        $periodLabel = "Luna curentă";
        break;
    default:
        $period = 'today';
        $dateParams = flx_period_params('today');
        $periodLabel = "Astăzi";
        break;
}

/* KPI — derived logic */
$inboundProcessingQuery  = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND $dateCondition";
$pickpackInProgressQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND $dateCondition";
$outboundDelayedQuery    = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition";
$backlogInboundQuery     = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()) AND $dateCondition";
$backlogPickpackQuery    = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition";
$backlogOutboundQuery    = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition";

/* activitate recentă: ultimele 5 evenimente simple */
$recentInboundQuery = "
  SELECT reception_number AS ref_code, supplier_name AS label_text, 'inbound' AS source_type,
         finished_at, expected_finished_date, created_at
  FROM inbound_orders
  ORDER BY created_at DESC
  LIMIT 3
";

$recentPickpackQuery = "
  SELECT order_number AS ref_code, operator_name AS label_text, 'pickpack' AS source_type,
         operator_name, finished_at, expected_finished_date, created_at
  FROM pickpack_orders
  ORDER BY created_at DESC
  LIMIT 3
";

$recentOutboundQuery = "
  SELECT order_number AS ref_code, destination AS label_text, 'outbound' AS source_type,
         finished_at, planned_date, created_at
  FROM outbound_orders
  ORDER BY created_at DESC
  LIMIT 3
";

/* excepții — derived logic */
$exceptionsInboundQuery = "
  SELECT reception_number AS ref_code, 'Inbound' AS module_name, 'Restanță — termen depășit' AS issue_text,
         finished_at, expected_finished_date, created_at
  FROM inbound_orders
  WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition
  ORDER BY created_at ASC
  LIMIT 3
";

$exceptionsOutboundQuery = "
  SELECT order_number AS ref_code, 'Outbound' AS module_name,
    CONCAT('Backlog ', DATEDIFF(CURDATE(), DATE(planned_date)), ' zile') AS issue_text,
    finished_at, planned_date, created_at
  FROM outbound_orders
  WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition
  ORDER BY planned_date ASC
  LIMIT 3
";

$exceptionsPickpackQuery = "
  SELECT order_number AS ref_code, 'Pick / Pack' AS module_name, 'Restanță — termen depășit' AS issue_text,
         operator_name, finished_at, expected_finished_date, created_at
  FROM pickpack_orders
  WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition
  ORDER BY created_at ASC
  LIMIT 3
";

$inboundProcessingResult = flx_query($conn, $inboundProcessingQuery, $dateParams);
$pickpackInProgressResult = flx_query($conn, $pickpackInProgressQuery, $dateParams);
$outboundDelayedResult    = flx_query($conn, $outboundDelayedQuery, $dateParams);
$backlogInboundResult     = flx_query($conn, $backlogInboundQuery, $dateParams);
$backlogPickpackResult    = flx_query($conn, $backlogPickpackQuery, $dateParams);
$backlogOutboundResult    = flx_query($conn, $backlogOutboundQuery, $dateParams);

$recentInboundResult = $conn->query($recentInboundQuery);
$recentPickpackResult = $conn->query($recentPickpackQuery);
$recentOutboundResult = $conn->query($recentOutboundQuery);

$exceptionsInboundResult = flx_query($conn, $exceptionsInboundQuery, $dateParams);
$exceptionsOutboundResult = flx_query($conn, $exceptionsOutboundQuery, $dateParams);
$exceptionsPickpackResult = flx_query($conn, $exceptionsPickpackQuery, $dateParams);

$inboundProcessing  = ($inboundProcessingResult  && $inboundProcessingResult->num_rows  > 0) ? (int)$inboundProcessingResult->fetch_assoc()['total']  : 0;
$pickpackInProgress = ($pickpackInProgressResult && $pickpackInProgressResult->num_rows > 0) ? (int)$pickpackInProgressResult->fetch_assoc()['total'] : 0;
$outboundDelayed    = ($outboundDelayedResult    && $outboundDelayedResult->num_rows    > 0) ? (int)$outboundDelayedResult->fetch_assoc()['total']    : 0;
$backlogInbound     = ($backlogInboundResult     && $backlogInboundResult->num_rows     > 0) ? (int)$backlogInboundResult->fetch_assoc()['total']     : 0;
$backlogPickpack    = ($backlogPickpackResult    && $backlogPickpackResult->num_rows    > 0) ? (int)$backlogPickpackResult->fetch_assoc()['total']    : 0;
$backlogOutbound    = ($backlogOutboundResult    && $backlogOutboundResult->num_rows    > 0) ? (int)$backlogOutboundResult->fetch_assoc()['total']    : 0;
$activeAlerts = $backlogInbound + $backlogPickpack + $backlogOutbound;

function severityCardClass($severity) {
  switch ($severity) {
    case 'high': return 'danger';
    case 'medium': return 'warning';
    case 'low': return 'success';
    default: return 'warning';
  }
}

function sourceLabel($sourceType) {
  switch ($sourceType) {
    case 'inbound': return 'Inbound';
    case 'pickpack': return 'Pick / Pack';
    case 'outbound': return 'Outbound';
    default: return 'General';
  }
}

function translateGenericStatus($status) {
  switch ($status) {
    case 'pending': return 'În așteptare';
    case 'processing': return 'În procesare';
    case 'completed': return 'Finalizat';
    case 'not_started': return 'Neînceput';
    case 'in_progress': return 'În lucru';
    case 'ready': return 'Pregătită';
    case 'shipped': return 'Expediată';
    case 'delayed': return 'Întârziată';
    case 'backlog': return 'Restanță';
    case 'not_shipped': return 'Neexpediată';
    case 'shipped_on_time': return 'Expediată la timp';
    case 'shipped_late': return 'Expediată cu întârziere';
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
$recentActivity = array_slice($recentActivity, 0, 5);

$exceptions = [];

if ($exceptionsInboundResult && $exceptionsInboundResult->num_rows > 0) {
  while ($row = $exceptionsInboundResult->fetch_assoc()) $exceptions[] = $row;
}
if ($exceptionsOutboundResult && $exceptionsOutboundResult->num_rows > 0) {
  while ($row = $exceptionsOutboundResult->fetch_assoc()) $exceptions[] = $row;
}
if ($exceptionsPickpackResult && $exceptionsPickpackResult->num_rows > 0) {
  while ($row = $exceptionsPickpackResult->fetch_assoc()) $exceptions[] = $row;
}

usort($exceptions, function($a, $b) {
  return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$exceptions = array_slice($exceptions, 0, 6);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Dashboard Șef de tură</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="css/styles.css?v=<?php echo time(); ?>">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="dashboard-tura" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">

      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Dashboard Șef de tură</strong>
      </div>

      <div class="topbar role-topbar role-tura">
        <div class="topbar-left">
          <h1>Dashboard operațional tură</h1>
          <p>Monitorizare în timp real a activității din schimbul curent și a excepțiilor operaționale.</p>
        </div>

        <div class="topbar-right">
          <form method="GET" action="" style="display:flex; gap:12px; align-items:center;">
            <select class="control-select" name="period" onchange="this.form.submit()">
              <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Astăzi</option>
              <option value="7d"    <?php echo $period === '7d'    ? 'selected' : ''; ?>>Ultimele 7 zile</option>
              <option value="30d"   <?php echo $period === '30d'   ? 'selected' : ''; ?>>Ultimele 30 zile</option>
              <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Luna curentă</option>
            </select>
          </form>

          <a href="raport-tura.php?period=<?php echo urlencode($period); ?>" target="_blank" class="control-button primary">
            <i class='bx bx-file'></i>
            <span>Raport PDF</span>
          </a>
        </div>
      </div>

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Recepții în procesare</span>
            <span class="kpi-icon"><i class='bx bx-log-in-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $inboundProcessing; ?></div>
  
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Pick / Pack în execuție</span>
            <span class="kpi-icon"><i class='bx bx-package'></i></span>
          </div>
          <div class="kpi-value"><?php echo $pickpackInProgress; ?></div>
          
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Livrări restante</span>
            <span class="kpi-icon"><i class='bx bx-error'></i></span>
          </div>
          <div class="kpi-value"><?php echo $outboundDelayed; ?></div>
        
        </div>

        <div class="card kpi-card"<?php if ($activeAlerts > 0) echo ' style="border-color:#fecaca;"'; ?>>
          <div class="kpi-top">
            <span class="label">Intervenții necesare</span>
            <span class="kpi-icon"><i class='bx bx-bell'></i></span>
          </div>
          <div class="kpi-value"<?php if ($activeAlerts > 0) echo ' style="color:#dc2626;"'; ?>><?php echo $activeAlerts; ?></div>

        </div>
      </div>

      <div class="cards-grid three-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Inbound</h3>
              <p>Recepții active și restanțe.</p>
            </div>
            <span class="card-tag role-accent-tag"><i class='bx bx-log-in-circle'></i></span>
          </div>
          <div class="summary-list">
            <div class="summary-item">
              <div>
                <strong>Activ</strong>
                <p>Recepții în procesare</p>
              </div>
              <span class="small-badge badge-primary"><?php echo $inboundProcessing; ?></span>
            </div>
            <div class="summary-item">
              <div>
                <strong>Restanță</strong>
                <p>Termen depășit</p>
              </div>
              <span class="small-badge <?php echo $backlogInbound > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $backlogInbound; ?></span>
            </div>
          </div>
          <div class="card-cta">
            <a href="receptii-active.php?period=<?php echo urlencode($period); ?>" class="control-button secondary" style="display:flex; width:100%; justify-content:center; text-decoration:none;">
              <i class='bx bx-right-arrow-alt'></i>
              <span>Vezi recepțiile</span>
            </a>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Pick / Pack</h3>
              <p>Comenzi în execuție și restanțe.</p>
            </div>
            <span class="card-tag role-accent-tag"><i class='bx bx-package'></i></span>
          </div>
          <div class="summary-list">
            <div class="summary-item">
              <div>
                <strong>În lucru</strong>
                <p>Comenzi cu operator asignat</p>
              </div>
              <span class="small-badge badge-primary"><?php echo $pickpackInProgress; ?></span>
            </div>
            <div class="summary-item">
              <div>
                <strong>Restanță</strong>
                <p>Termen depășit</p>
              </div>
              <span class="small-badge <?php echo $backlogPickpack > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $backlogPickpack; ?></span>
            </div>
          </div>
          <div class="card-cta">
            <a href="picking-packing-tura.php?period=<?php echo urlencode($period); ?>" class="control-button secondary" style="display:flex; width:100%; justify-content:center; text-decoration:none;">
              <i class='bx bx-right-arrow-alt'></i>
              <span>Vezi comenzile</span>
            </a>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Outbound</h3>
              <p>Livrări restante și backlog acumulat.</p>
            </div>
            <span class="card-tag role-accent-tag"><i class='bx bx-send'></i></span>
          </div>
          <div class="summary-list">
            <div class="summary-item">
              <div>
                <strong>Restanță</strong>
                <p>Data planificată depășită</p>
              </div>
              <span class="small-badge <?php echo $outboundDelayed > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $outboundDelayed; ?></span>
            </div>
          </div>
          <div class="card-cta">
            <a href="expedieri-urgente.php?period=<?php echo urlencode($period); ?>" class="control-button secondary" style="display:flex; width:100%; justify-content:center; text-decoration:none;">
              <i class='bx bx-right-arrow-alt'></i>
              <span>Vezi expedierile</span>
            </a>
          </div>
        </div>
      </div>

      <div class="cards-grid two-col">
        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Activitate recentă</h3>
              <p>Ultimele evenimente reale din baza de date.</p>
            </div>
          </div>

          <div class="activity-list">
            <?php if (!empty($recentActivity)): ?>
              <?php foreach ($recentActivity as $item): ?>
                <?php
                  if ($item['source_type'] === 'inbound') {
                    $derivedStatus = flx_inbound_status($item);
                  } elseif ($item['source_type'] === 'pickpack') {
                    $derivedStatus = flx_pickpack_status($item);
                  } else {
                    $derivedStatus = flx_outbound_status($item);
                  }
                ?>
                <div class="activity-item">
                  <div>
                    <strong><?php echo htmlspecialchars($item['ref_code']); ?> — <?php echo htmlspecialchars($item['label_text']); ?></strong>
                    <p><span class="small-badge <?php echo $item['source_type'] === 'inbound' ? 'badge-primary' : ($item['source_type'] === 'pickpack' ? 'badge-warning' : 'badge-success'); ?>"><?php echo sourceLabel($item['source_type']); ?></span> Status: <?php echo translateGenericStatus($derivedStatus); ?></p>
                  </div>
                  <span class="small-badge <?php echo statusBadgeClassGeneric($derivedStatus); ?>">
                    <?php echo date('H:i', strtotime($item['created_at'])); ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="activity-item">
                <div>
                  <strong>Nu există activitate recentă</strong>
                  <p>Nu au fost găsite evenimente recente în baza de date.</p>
                </div>
                <span class="small-badge badge-primary">Info</span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" id="exceptions-table">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Restanțe și blocaje</h3>
              <p>Elemente care necesită atenție imediată.</p>
            </div>
            <span class="card-tag tag-danger"><?php echo count($exceptions); ?> active</span>
          </div>

          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Referință</th>
                  <th>Modul</th>
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
                            $exDerivedStatus = flx_inbound_status($ex);
                          } elseif ($ex['module_name'] === 'Pick / Pack') {
                            $exDerivedStatus = flx_pickpack_status($ex);
                          } else {
                            $exDerivedStatus = flx_outbound_status($ex);
                          }
                        ?>
                        <span class="small-badge <?php echo statusBadgeClassGeneric($exDerivedStatus); ?>">
                          <?php echo translateGenericStatus($exDerivedStatus); ?>
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

    </div>
  </main>

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