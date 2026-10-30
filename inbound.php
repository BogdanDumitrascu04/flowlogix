<?php
session_start();

// PROTECȚIE: Utilizatorul trebuie să fie autentificat
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$currentPage = 'inbound';
require_once 'config.php';
require_once 'flow_helpers.php';

// Utilizează rolul din sesiune, nu din GET
$roleKey = isset($_SESSION['role']) ? $_SESSION['role'] : 'default';
$period = isset($_GET['period']) ? $_GET['period'] : '7d';

$pageTitle = "Inbound";
$pageSubtitle = "Monitorizarea recepțiilor, rampelor, timpilor de descărcare și a recepțiilor în așteptare.";
$primaryButtonIcon = "bx-analyse";
$primaryButtonText = "Analiză rapidă";

if ($roleKey === 'tura') {
  $pageTitle = "Recepții active";
  $pageSubtitle = "Vizualizare operațională pentru controlul recepțiilor curente, blocajelor și excepțiilor din schimb.";
  $primaryButtonIcon = "bx-error-circle";
  $primaryButtonText = "Blocaje inbound";
} elseif ($roleKey === 'depozit') {
  $pageTitle = "Inbound";
  $pageSubtitle = "Coordonarea recepțiilor și a capacității operaționale inbound la nivel de depozit.";
  $primaryButtonIcon = "bx-buildings";
  $primaryButtonText = "Analiză inbound";
} elseif ($roleKey === 'manager') {
  $pageTitle = "Inbound overview";
  $pageSubtitle = "Vizualizare sintetică a performanței recepțiilor și a impactului asupra fluxului logistic general.";
  $primaryButtonIcon = "bx-line-chart";
  $primaryButtonText = "Rezumat recepții";
}

/* FILTRARE PE PERIOADĂ */
// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
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
    $csvQuery = "SELECT reception_number, supplier_name, created_at, finished_at, expected_finished_date
                 FROM inbound_orders WHERE $dateCondition ORDER BY created_at DESC";
    $csvResult = flx_query($conn, $csvQuery, $dateParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inbound-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    fputcsv($output, ['Nr. recepție', 'Furnizor', 'Status derivat', 'Prioritate derivată', 'Data creare', 'Termen estimat', 'Data finalizare', 'Zile restanță'], ';');
    if ($csvResult) {
        while ($row = $csvResult->fetch_assoc()) {
            $derivedStatus   = flx_inbound_status($row);
            $derivedPriority = flx_inbound_priority($row);
            $daysOverdue = 0;
            if (empty($row['finished_at'])) {
                $dueDate = !empty($row['expected_finished_date']) ? $row['expected_finished_date'] : date('Y-m-d', strtotime($row['created_at']));
                $due   = strtotime(date('Y-m-d', strtotime($dueDate)));
                $today = strtotime(date('Y-m-d'));
                if ($today > $due) {
                    $daysOverdue = (int)(($today - $due) / 86400);
                }
            }
            fputcsv($output, [
                $row['reception_number'],
                $row['supplier_name'],
                $derivedStatus,
                $derivedPriority,
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

/* KPI */
$totalQuery       = "SELECT COUNT(*) AS total FROM inbound_orders WHERE $dateCondition";
$completedQuery   = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NOT NULL AND $dateCondition";
$processingQuery  = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND $dateCondition";
$backlogQuery     = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition";
$highPriorityQuery = "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()) AND $dateCondition";

/* tabel */
$tableQuery = "SELECT * FROM inbound_orders WHERE $dateCondition ORDER BY created_at DESC";

/* avertizări backlog inbound */
$backlogWarningsQuery = "
  SELECT reception_number, supplier_name,
    DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_overdue
  FROM inbound_orders
  WHERE finished_at IS NULL
    AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
    AND $dateCondition
  ORDER BY days_overdue DESC
  LIMIT 3
";

/* activitate recentă */
$recentQuery = "
  SELECT reception_number, supplier_name, status, priority, created_at, finished_at
  FROM inbound_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

/* grafic pe zile */
$dailyChartQuery = "
  SELECT DATE(created_at) AS day_label, COUNT(*) AS total
  FROM inbound_orders
  WHERE $dateCondition
  GROUP BY DATE(created_at)
  ORDER BY DATE(created_at) ASC
";

/* grafic pe ore */
$hourlyChartQuery = "
  SELECT HOUR(created_at) AS hour_label, COUNT(*) AS total
  FROM inbound_orders
  WHERE $dateCondition
  GROUP BY HOUR(created_at)
  ORDER BY HOUR(created_at) ASC
";

$totalResult        = flx_query($conn, $totalQuery, $dateParams);
$completedResult    = flx_query($conn, $completedQuery, $dateParams);
$processingResult   = flx_query($conn, $processingQuery, $dateParams);
$backlogResult      = flx_query($conn, $backlogQuery, $dateParams);
$highPriorityResult = flx_query($conn, $highPriorityQuery, $dateParams);
$tableResult        = flx_query($conn, $tableQuery, $dateParams);
$backlogWarningsResult = flx_query($conn, $backlogWarningsQuery, $dateParams);
$recentResult       = flx_query($conn, $recentQuery, $dateParams);
$dailyChartResult   = flx_query($conn, $dailyChartQuery, $dateParams);
$hourlyChartResult  = flx_query($conn, $hourlyChartQuery, $dateParams);

$totalInbound        = ($totalResult        && $totalResult->num_rows        > 0) ? (int)$totalResult->fetch_assoc()['total']        : 0;
$completedInbound    = ($completedResult    && $completedResult->num_rows    > 0) ? (int)$completedResult->fetch_assoc()['total']    : 0;
$processingInbound   = ($processingResult   && $processingResult->num_rows   > 0) ? (int)$processingResult->fetch_assoc()['total']   : 0;
$backlogInbound      = ($backlogResult      && $backlogResult->num_rows      > 0) ? (int)$backlogResult->fetch_assoc()['total']      : 0;
$highPriorityInbound = ($highPriorityResult && $highPriorityResult->num_rows > 0) ? (int)$highPriorityResult->fetch_assoc()['total'] : 0;

$completedPercent = ($totalInbound > 0) ? round(($completedInbound / $totalInbound) * 100) : 0;

/* date grafice */
$dailyLabels = [];
$dailyData = [];

if ($dailyChartResult && $dailyChartResult->num_rows > 0) {
  while ($row = $dailyChartResult->fetch_assoc()) {
    $dailyLabels[] = date('d.m', strtotime($row['day_label']));
    $dailyData[] = (int)$row['total'];
  }
}

$hourlyLabels = [];
$hourlyData = [];

if ($hourlyChartResult && $hourlyChartResult->num_rows > 0) {
  while ($row = $hourlyChartResult->fetch_assoc()) {
    $hour = str_pad($row['hour_label'], 2, '0', STR_PAD_LEFT);
    $hourlyLabels[] = $hour . ':00';
    $hourlyData[] = (int)$row['total'];
  }
}

function translateInboundStatus($status) {
  switch ($status) {
    case 'pending': return 'Activă';
    case 'processing': return 'În procesare';
    case 'completed': return 'Finalizată';
    case 'backlog': return 'Restanță';
    default: return ucfirst($status);
  }
}

function translatePriority($priority) {
  switch ($priority) {
    case 'low': return 'Scăzută';
    case 'medium': return 'Medie';
    case 'high': return 'Ridicată';
    default: return $priority;
  }
}

function priorityBadgeClass($priority) {
  switch ($priority) {
    case 'high': return 'badge-danger';
    case 'medium': return 'badge-warning';
    case 'low': return 'badge-success';
    default: return 'badge-primary';
  }
}

function statusBadgeClassInbound($status) {
  switch ($status) {
    case 'pending': return 'badge-warning';
    case 'processing': return 'badge-primary';
    case 'completed': return 'badge-success';
    case 'backlog': return 'badge-danger';
    default: return 'badge-primary';
  }
}


/* recomandări simple bazate pe DB */
$recommendations = [];

if ($highPriorityInbound > 0) {
  $recommendations[] = [
    'title' => 'Prioritizează recepțiile cu prioritate ridicată',
    'text' => 'Există ' . $highPriorityInbound . ' recepții marcate cu prioritate high care necesită atenție rapidă.',
    'badge' => 'Urgent',
    'class' => 'badge-danger'
  ];
}

if ($processingInbound > 0) {
  $recommendations[] = [
    'title' => 'Monitorizează recepțiile active',
    'text' => 'Sunt ' . $processingInbound . ' recepții active care trebuie urmărite până la finalizare.',
    'badge' => 'Prioritar',
    'class' => 'badge-warning'
  ];
}

if ($backlogInbound > 0) {
  $recommendations[] = [
    'title' => 'Rezolvă recepțiile în backlog',
    'text' => 'Există ' . $backlogInbound . ' recepții cu termen depășit în backlog. Necesită intervenție imediată.',
    'badge' => 'Urgent',
    'class' => 'badge-danger'
  ];
}

while (count($recommendations) < 3) {
  $recommendations[] = [
    'title' => 'Menține ritmul de procesare',
    'text' => 'Rata actuală de finalizare trebuie păstrată pentru stabilitatea fluxului inbound.',
    'badge' => 'Monitorizare',
    'class' => 'badge-success'
  ];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Inbound</title>
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
<body data-page="inbound">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Inbound</strong>
      </div>

      <div class="topbar">
        <div class="topbar-left">
          <h1><?php echo $pageTitle; ?></h1>
          <p><?php echo $pageSubtitle; ?></p>
        </div>

        <div class="topbar-right">
          <form method="GET" action="" style="display:flex; gap:12px; align-items:center;">
            <select class="control-select" id="periodSelect" name="period" onchange="this.form.submit()">
              <option value="today" <?php echo ($period === 'today') ? 'selected' : ''; ?>>Astăzi</option>
              <option value="7d" <?php echo ($period === '7d') ? 'selected' : ''; ?>>Ultimele 7 zile</option>
              <option value="30d" <?php echo ($period === '30d') ? 'selected' : ''; ?>>Ultimele 30 zile</option>
              <option value="month" <?php echo ($period === 'month') ? 'selected' : ''; ?>>Luna curentă</option>
            </select>
          </form>

          <a href="inbound.php?period=<?php echo urlencode($period); ?>&export=csv" class="control-button primary">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>
      </div>

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Recepții înregistrate</span>
            <span class="kpi-icon"><i class='bx bx-calendar-check'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalInbound; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Procesate complet</span>
            <span class="kpi-icon"><i class='bx bx-box'></i></span>
          </div>
          <div class="kpi-value"><?php echo $completedInbound; ?></div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo $completedPercent; ?>%"></div>
          </div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">În procesare</span>
            <span class="kpi-icon"><i class='bx bx-time'></i></span>
          </div>
          <div class="kpi-value"><?php echo $processingInbound; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Prioritate ridicată</span>
            <span class="kpi-icon"><i class='bx bx-error-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $highPriorityInbound; ?></div>
        </div>
      </div>

      <?php if ($backlogWarningsResult && $backlogWarningsResult->num_rows > 0): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Restanțe inbound</h3>
            <p>Elemente calculate automat din datele operaționale.</p>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nr. recepție</th>
                <th>Furnizor</th>
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
                  <td><strong><?php echo htmlspecialchars($warn['reception_number']); ?></strong></td>
                  <td><?php echo htmlspecialchars($warn['supplier_name']); ?></td>
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
              <h3>Recepții pe zile</h3>
            </div>
            <span class="card-tag tag-primary">Inbound</span>
          </div>
          <div class="chart-bar-wrapper">
            <canvas id="inboundMainChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Recepții pe intervale orare</h3>
              <p>Distribuția după ora de creare pentru perioada selectată.</p>
            </div>
          </div>
          <div class="chart-wrap chart-sm">
            <canvas id="inboundHoursChart"></canvas>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Recepții înregistrate</h3>
          </div>
        </div>

        <div class="table-scroll">
          <table class="data-table" id="table-inbound">
            <thead>
              <tr>
                <th>Nr. recepție</th>
                <th>Furnizor</th>
                <th>Prioritate</th>
                <th>Creată la</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($tableResult && $tableResult->num_rows > 0): ?>
                <?php $totalTableRows = $tableResult->num_rows; $rowIndex = 0; ?>
                <?php mysqli_data_seek($tableResult, 0); ?>
                <?php while ($row = $tableResult->fetch_assoc()): $rowIndex++; ?>
                  <?php
                    $derivedStatus   = flx_inbound_status($row);
                    $derivedPriority = flx_inbound_priority($row);
                    $rowClass = $rowIndex > 10 ? ' class="extra-row hidden-row"' : '';
                  ?>
                  <tr<?php echo $rowClass; ?>>
                    <td><?php echo htmlspecialchars($row['reception_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                    <td>
                      <span class="small-badge <?php echo priorityBadgeClass($derivedPriority); ?>">
                        <?php echo translatePriority($derivedPriority); ?>
                      </span>
                    </td>
                    <td><?php echo date('d.m.Y H:i', strtotime($row['created_at'])); ?></td>
                    <td>
                      <span class="small-badge <?php echo statusBadgeClassInbound($derivedStatus); ?>">
                        <?php echo translateInboundStatus($derivedStatus); ?>
                      </span>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5">Nu există recepții în baza de date pentru perioada selectată.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (!empty($totalTableRows) && $totalTableRows > 10): ?>
          <div class="table-actions">
            <p class="table-extra-note">Se afișează primele 10 recepții din <?php echo $totalTableRows; ?> din perioada selectată.</p>
            <button type="button" class="toggle-table-btn" data-table-target="table-inbound" data-expanded="0">Afișează toate</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <div class="modal" id="globalModal">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title">Analiză inbound</h3>
      </div>
      <div class="modal-body">
        <p><strong>Observație principală:</strong> această pagină este conectată la baza de date MySQL și folosește date reale din tabelul <code>inbound_orders</code>.</p>
        <p>KPI-urile, avertizările de backlog, tabelul principal și graficele sunt alimentate din baza de date și filtrate după perioada selectată.</p>
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
    window.inboundMainChartData = {
      labels: <?php echo json_encode($dailyLabels); ?>,
      data: <?php echo json_encode($dailyData); ?>
    };

    window.inboundHoursChartData = {
      labels: <?php echo json_encode($hourlyLabels); ?>,
      data: <?php echo json_encode($hourlyData); ?>
    };
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