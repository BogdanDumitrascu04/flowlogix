<?php
session_start();

// PROTECȚIE: Utilizatorul trebuie să fie autentificat
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$currentPage = 'pickpack';
require_once 'config.php';
require_once 'flow_helpers.php';

// Utilizează rolul din sesiune, nu din GET
$roleKey = isset($_SESSION['role']) ? $_SESSION['role'] : 'default';
$period = isset($_GET['period']) ? $_GET['period'] : '7d';

$pageTitle = "Pick / Pack";
$pageSubtitle = "Productivitatea operatorilor, timpii de procesare și calitatea pregătirii comenzilor.";
$primaryButtonIcon = "bx-user-check";
$primaryButtonText = "Detalii echipă";

if ($roleKey === 'tura') {
  $pageTitle = "Picking / Packing activ";
  $pageSubtitle = "Control operațional al comenzilor în lucru, al echipei active și al excepțiilor de procesare.";
  $primaryButtonIcon = "bx-task";
  $primaryButtonText = "Task-uri urgente";
} elseif ($roleKey === 'depozit') {
  $pageTitle = "Pick / Pack";
  $pageSubtitle = "Vizualizare completă asupra productivității, acurateței și echilibrului între picking și packing.";
  $primaryButtonIcon = "bx-analyse";
  $primaryButtonText = "Analiză echipă";
} elseif ($roleKey === 'manager') {
  $pageTitle = "Pick / Pack overview";
  $pageSubtitle = "Perspectivă sintetică asupra randamentului de procesare și a impactului asupra livrărilor.";
  $primaryButtonIcon = "bx-line-chart";
  $primaryButtonText = "Rezumat productivitate";
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
    $csvQuery = "SELECT order_number, operator_name, items_count, created_at, finished_at, expected_finished_date
                 FROM pickpack_orders WHERE $dateCondition ORDER BY created_at DESC";
    $csvResult = flx_query($conn, $csvQuery, $dateParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pickpack-' . date('Y-m-d') . '.csv"');
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

/* KPI */
$totalQuery      = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE $dateCondition";
$completedQuery  = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NOT NULL AND $dateCondition";
$inProgressQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE()) AND $dateCondition";
$notStartedQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND (operator_name IS NULL OR operator_name = '') AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE()) AND $dateCondition";
$backlogQuery    = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition";
$itemsQuery      = "SELECT COALESCE(SUM(items_count),0) AS total FROM pickpack_orders WHERE $dateCondition";

/* timp mediu de procesare doar pentru finalizate */
$avgProcessingQuery = "
  SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, finished_at)) AS avg_minutes
  FROM pickpack_orders
  WHERE finished_at IS NOT NULL
    AND $dateCondition
";

/* top operatori */
$topOperatorsQuery = "
  SELECT operator_name, COUNT(*) AS total_orders
  FROM pickpack_orders
  WHERE $dateCondition
  GROUP BY operator_name
  ORDER BY total_orders DESC
  LIMIT 5
";

/* activitate recentă */
$recentQuery = "
  SELECT order_number, operator_name, items_count, status, created_at, finished_at
  FROM pickpack_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
  LIMIT 3
";

/* tabel principal */
$tableQuery = "
  SELECT order_number, operator_name, items_count, status, created_at, finished_at, expected_finished_date
  FROM pickpack_orders
  WHERE $dateCondition
  ORDER BY created_at DESC
";

/* avertizări backlog pick/pack */
$backlogWarningsQuery = "
  SELECT order_number, operator_name,
    DATEDIFF(CURDATE(), expected_finished_date) AS days_overdue
  FROM pickpack_orders
  WHERE finished_at IS NULL
    AND expected_finished_date < CURDATE()
    AND $dateCondition
  ORDER BY days_overdue DESC
  LIMIT 3
";

/* grafic procesate vs neprocesate */
$statusChartQuery = "
  SELECT
    CASE
      WHEN finished_at IS NOT NULL THEN 'Procesate'
      WHEN expected_finished_date IS NOT NULL AND expected_finished_date < CURDATE() THEN 'Restante'
      ELSE 'Neprocesate'
    END AS chart_status,
    COUNT(*) AS total
  FROM pickpack_orders
  WHERE $dateCondition
  GROUP BY chart_status
  ORDER BY FIELD(chart_status, 'Procesate', 'Neprocesate', 'Restante')
";

/* grafic top operatori */
$operatorChartQuery = "
  SELECT operator_name, COUNT(*) AS total_orders
  FROM pickpack_orders
  WHERE $dateCondition
  GROUP BY operator_name
  ORDER BY total_orders DESC
  LIMIT 5
";

$totalResult         = flx_query($conn, $totalQuery, $dateParams);
$completedResult     = flx_query($conn, $completedQuery, $dateParams);
$inProgressResult    = flx_query($conn, $inProgressQuery, $dateParams);
$notStartedResult    = flx_query($conn, $notStartedQuery, $dateParams);
$backlogResult       = flx_query($conn, $backlogQuery, $dateParams);
$itemsResult         = flx_query($conn, $itemsQuery, $dateParams);
$avgProcessingResult = flx_query($conn, $avgProcessingQuery, $dateParams);
$topOperatorsResult  = flx_query($conn, $topOperatorsQuery, $dateParams);
$recentResult        = flx_query($conn, $recentQuery, $dateParams);
$tableResult         = flx_query($conn, $tableQuery, $dateParams);
$backlogWarningsResult = flx_query($conn, $backlogWarningsQuery, $dateParams);
$statusChartResult   = flx_query($conn, $statusChartQuery, $dateParams);
$operatorChartResult = flx_query($conn, $operatorChartQuery, $dateParams);

$totalOrders       = ($totalResult         && $totalResult->num_rows         > 0) ? (int)$totalResult->fetch_assoc()['total']                                : 0;
$completedOrders   = ($completedResult     && $completedResult->num_rows     > 0) ? (int)$completedResult->fetch_assoc()['total']                            : 0;
$inProgressOrders  = ($inProgressResult    && $inProgressResult->num_rows    > 0) ? (int)$inProgressResult->fetch_assoc()['total']                           : 0;
$notStartedOrders  = ($notStartedResult    && $notStartedResult->num_rows    > 0) ? (int)$notStartedResult->fetch_assoc()['total']                           : 0;
$backlogOrders     = ($backlogResult       && $backlogResult->num_rows       > 0) ? (int)$backlogResult->fetch_assoc()['total']                              : 0;
$totalItems        = ($itemsResult         && $itemsResult->num_rows         > 0) ? (int)$itemsResult->fetch_assoc()['total']                                : 0;
$avgProcessingTime = ($avgProcessingResult && $avgProcessingResult->num_rows > 0) ? round((float)$avgProcessingResult->fetch_assoc()['avg_minutes'])         : 0;

$completedPercent = ($totalOrders > 0) ? round(($completedOrders / $totalOrders) * 100) : 0;

/* grafice */
$statusLabels = [];
$statusData = [];

if ($statusChartResult && $statusChartResult->num_rows > 0) {
  while ($row = $statusChartResult->fetch_assoc()) {
    $statusLabels[] = $row['chart_status'];
    $statusData[] = (int)$row['total'];
  }
}

$operatorLabels = [];
$operatorData = [];

if ($operatorChartResult && $operatorChartResult->num_rows > 0) {
  while ($row = $operatorChartResult->fetch_assoc()) {
    $operatorLabels[] = $row['operator_name'];
    $operatorData[] = (int)$row['total_orders'];
  }
}

function translatePickpackStatus($status) {
  switch ($status) {
    case 'not_started': return 'Neîncepută';
    case 'in_progress': return 'În execuție';
    case 'completed': return 'Finalizată';
    case 'backlog': return 'Restanță';
    default: return ucfirst($status);
  }
}

function pickpackStatusBadgeClass($status) {
  switch ($status) {
    case 'not_started': return 'badge-warning';
    case 'in_progress': return 'badge-primary';
    case 'completed': return 'badge-success';
    case 'backlog': return 'badge-danger';
    default: return 'badge-primary';
  }
}


function formatProcessingMinutes($createdAt, $finishedAt, $status) {
  if (!empty($finishedAt)) {
    $created = strtotime($createdAt);
    $finished = strtotime($finishedAt);

    if ($created && $finished && $finished >= $created) {
      $minutes = round(($finished - $created) / 60);
      return $minutes . ' min';
    }
  }
  return '—';
}

/* top performer */
$topPerformerName = 'N/A';
$topPerformerCount = 0;

if ($topOperatorsResult && $topOperatorsResult->num_rows > 0) {
  mysqli_data_seek($topOperatorsResult, 0);
  $topRow = $topOperatorsResult->fetch_assoc();
  $topPerformerName = $topRow['operator_name'];
  $topPerformerCount = (int)$topRow['total_orders'];
  mysqli_data_seek($topOperatorsResult, 0);
}

/* recomandări */
$recommendations = [];

if ($inProgressOrders > 0) {
  $recommendations[] = [
    'title' => 'Monitorizează comenzile în execuție',
    'text' => 'Există ' . $inProgressOrders . ' comenzi aflate în procesare care necesită urmărire până la finalizare.',
    'badge' => 'Prioritar',
    'class' => 'badge-warning'
  ];
}

if ($notStartedOrders > 0) {
  $recommendations[] = [
    'title' => 'Reduce comenzile neîncepute',
    'text' => 'Sunt ' . $notStartedOrders . ' comenzi care nu au intrat încă în fluxul operațional.',
    'badge' => 'Acțiune',
    'class' => 'badge-danger'
  ];
}

if ($backlogOrders > 0) {
  $recommendations[] = [
    'title' => 'Rezolvă comenzile în backlog',
    'text' => 'Există ' . $backlogOrders . ' comenzi cu termen depășit în backlog. Necesită intervenție imediată.',
    'badge' => 'Urgent',
    'class' => 'badge-danger'
  ];
}

if ($avgProcessingTime > 0) {
  $recommendations[] = [
    'title' => 'Păstrează timpul mediu de procesare sub control',
    'text' => 'Timpul mediu actual este de ' . $avgProcessingTime . ' minute pentru comenzile finalizate.',
    'badge' => 'Monitorizare',
    'class' => 'badge-primary'
  ];
}

while (count($recommendations) < 3) {
  $recommendations[] = [
    'title' => 'Menține ritmul de procesare',
    'text' => 'Fluxul pick / pack trebuie să rămână stabil pentru susținerea expedițiilor.',
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
  <title>FlowLogix | Pick / Pack</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=13">
  <link rel="stylesheet" href="css/styles.css?v=13">
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

    .hidden-row { display: none; }

    .table-actions {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      margin-top: 16px;
      padding-bottom: 4px;
    }

    .table-extra-note {
      font-size: 12px;
      color: var(--text-muted, #64748b);
      margin: 0;
    }

    .toggle-table-btn {
      border: 1px solid var(--border, #dbe3ef);
      background: #fff;
      color: var(--primary, #2563eb);
      border-radius: 999px;
      padding: 10px 18px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .18s ease;
    }

    .toggle-table-btn:hover {
      background: rgba(37, 99, 235, 0.08);
      border-color: rgba(37, 99, 235, 0.35);
    }
  </style>
</head>
<body data-page="pickpack">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Pick / Pack</strong>
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

          <a href="pickpack.php?period=<?php echo urlencode($period); ?>&export=csv" class="control-button primary">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>
      </div>

      

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Comenzi înregistrate</span>
            <span class="kpi-icon"><i class='bx bx-task'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalOrders; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Finalizate</span>
            <span class="kpi-icon"><i class='bx bx-check-shield'></i></span>
          </div>
          <div class="kpi-value"><?php echo $completedOrders; ?></div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo $completedPercent; ?>%"></div>
          </div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Timp mediu procesare</span>
            <span class="kpi-icon"><i class='bx bx-stopwatch'></i></span>
          </div>
          <div class="kpi-value"><?php echo $avgProcessingTime; ?> min</div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Total articole</span>
            <span class="kpi-icon"><i class='bx bx-package'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalItems; ?></div>
        </div>
      </div>

      <?php if ($backlogWarningsResult && $backlogWarningsResult->num_rows > 0): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Restanțe Pick / Pack</h3>
            <p>Elemente calculate automat din datele operaționale.</p>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nr. comandă</th>
                <th>Operator</th>
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
                  <td><?php echo htmlspecialchars($warn['operator_name'] ?: 'Neatribuit'); ?></td>
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
              <h3>Procesate vs neprocesate</h3>
              <p>Distribuția comenzilor după stadiu pentru perioada selectată.</p>
            </div>
          </div>
          <div class="chart-bar-wrapper">
            <canvas id="pickpackMainChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Top operatori</h3>
            </div>
          </div>
          <div class="chart-bar-wrapper" style="height: 260px;">
            <canvas id="pickpackOperatorsChart"></canvas>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Productivitate operatori / comenzi</h3>
          </div>
        </div>

        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nr. comandă</th>
                <th>Operator</th>
                <th>Articole</th>
                <th>Status</th>
                <th>Creată la</th>
                <th>Timp procesare</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($tableResult && $tableResult->num_rows > 0): ?>
                <?php $totalTableRows = $tableResult->num_rows; $rowIndex = 0; ?>
                <?php while ($row = $tableResult->fetch_assoc()): $rowIndex++; ?>
                  <?php $derivedStatus = flx_pickpack_status($row); ?>
                  <?php $rowClass = $rowIndex > 10 ? ' class="extra-row hidden-row"' : ''; ?>
                  <tr<?php echo $rowClass; ?>>
                    <td><?php echo htmlspecialchars($row['order_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['operator_name']); ?></td>
                    <td><?php echo (int)$row['items_count']; ?></td>
                    <td>
                      <span class="small-badge <?php echo pickpackStatusBadgeClass($derivedStatus); ?>">
                        <?php echo translatePickpackStatus($derivedStatus); ?>
                      </span>
                    </td>
                    <td><?php echo date('d.m.Y H:i', strtotime($row['created_at'])); ?></td>
                    <td><?php echo formatProcessingMinutes($row['created_at'], $row['finished_at'], $derivedStatus); ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <?php $totalTableRows = 0; ?>
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
            <button type="button" class="toggle-table-btn" id="toggleTableBtn">Afișează toate comenzile</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <div class="modal" id="globalModal">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title">Analiză Pick / Pack</h3>
      </div>
      <div class="modal-body">
        <p><strong>Observație principală:</strong> această pagină este conectată la baza de date MySQL și folosește date reale din tabelul <code>pickpack_orders</code>.</p>
        <p>KPI-urile, avertizările de backlog, tabelul principal și graficele sunt alimentate prin interogări și agregări directe din baza de date, filtrate după perioada selectată.</p>
      </div>
      <div class="modal-footer">
        <button class="control-button" id="closeModalBtn">Închide</button>
      </div>
    </div>
  </div>

  <script>
    window.pickpackMainChartData = {
      labels: <?php echo json_encode($statusLabels); ?>,
      data: <?php echo json_encode($statusData); ?>
    };

    window.pickpackOperatorsChartData = {
      labels: <?php echo json_encode($operatorLabels); ?>,
      data: <?php echo json_encode($operatorData); ?>
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

  <script>
    (function () {
      var btn = document.getElementById('toggleTableBtn');
      if (!btn) return;
      var extraRows = document.querySelectorAll('.extra-row');
      var expanded = false;
      btn.addEventListener('click', function () {
        expanded = !expanded;
        extraRows.forEach(function (row) {
          row.classList.toggle('hidden-row', !expanded);
        });
        btn.textContent = expanded ? 'Afișează mai puține' : 'Afișează toate comenzile';
      });
    })();
  </script>

  <?php $conn->close(); ?>
</body>
</html>