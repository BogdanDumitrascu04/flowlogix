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

$currentPage = 'receptii-active';

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
    $csvQuery = "SELECT reception_number, supplier_name, created_at, finished_at, expected_finished_date
                 FROM inbound_orders WHERE $dateCondition ORDER BY created_at DESC";
    $csvResult = flx_query($conn, $csvQuery, $dateParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="receptii-active-' . date('Y-m-d') . '.csv"');
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
$statusRo    = ['backlog' => 'Restanță', 'pending' => 'În așteptare', 'completed' => 'Finalizat'];
$statusCss   = ['backlog' => 'badge-danger', 'pending' => 'badge-warning', 'completed' => 'badge-success'];
$priorityRo  = ['high' => 'Ridicată', 'medium' => 'Medie', 'low' => 'Scăzută'];
$priorityCss = ['high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-success'];

// Query-uri pentru recepții active (derived logic — fără dependență de status/priority stocate)
$inProgressQuery = "SELECT COUNT(*) as total FROM inbound_orders WHERE finished_at IS NULL AND $dateCondition";
$priorityQuery = "SELECT COUNT(*) as total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()) AND $dateCondition";
$atRiskQuery = "SELECT COUNT(*) as total FROM inbound_orders WHERE finished_at IS NULL AND expected_finished_date IS NOT NULL AND expected_finished_date < CURDATE() AND $dateCondition";
$completedPeriodQuery = "SELECT COUNT(*) as total FROM inbound_orders WHERE finished_at IS NOT NULL AND $finishedAtCondition";

// Tabel recepții active
$tableQuery = "SELECT
    reception_number,
    supplier_name,
    status,
    priority,
    created_at,
    finished_at,
    expected_finished_date,
    TIMEDIFF(NOW(), created_at) as time_elapsed
FROM inbound_orders
WHERE $dateCondition
ORDER BY
  CASE
    WHEN finished_at IS NULL
     AND (
       expected_finished_date < CURDATE()
       OR DATE(created_at) < CURDATE()
     )
    THEN 0
    ELSE 1
  END ASC,
  created_at DESC";

$inProgressResult = flx_query($conn, $inProgressQuery, $dateParams);
$priorityResult = flx_query($conn, $priorityQuery, $dateParams);
$atRiskResult = flx_query($conn, $atRiskQuery, $dateParams);
$completedPeriodResult = flx_query($conn, $completedPeriodQuery, $finishedParams);
$tableResult = flx_query($conn, $tableQuery, $dateParams);

$inProgress = ($inProgressResult && $inProgressResult->num_rows > 0) ? (int)$inProgressResult->fetch_assoc()['total'] : 0;
$priorityCount = ($priorityResult && $priorityResult->num_rows > 0) ? (int)$priorityResult->fetch_assoc()['total'] : 0;
$atRisk = ($atRiskResult && $atRiskResult->num_rows > 0) ? (int)$atRiskResult->fetch_assoc()['total'] : 0;
$completedPeriod = ($completedPeriodResult && $completedPeriodResult->num_rows > 0) ? (int)$completedPeriodResult->fetch_assoc()['total'] : 0;
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowLogix | Recepții active</title>
    <link rel="stylesheet" href="css/sidebar.css?v=13">
    <link rel="stylesheet" href="css/styles.css?v=13">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="receptii-active" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Recepții active</strong>
      </div>

      <div class="topbar role-topbar role-tura">
        <div class="topbar-left">
          <h1>Recepții active</h1>
          <p>Monitorizare operațională a recepțiilor curente, priorităților și blocajelor inbound.</p>
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

          <a href="receptii-active.php?period=<?php echo urlencode($period); ?>&export=csv" class="control-button primary">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>
      </div>

      <div class="cards-grid kpi-grid">
                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">În curs acum</span>
                        <span class="kpi-icon">⏳</span>
                    </div>
                    <div class="kpi-value"><?php echo $inProgress; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Prioritare</span>
                        <span class="kpi-icon">⚡</span>
                    </div>
                    <div class="kpi-value"><?php echo $priorityCount; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Cu risc</span>
                        <span class="kpi-icon">⚠️</span>
                    </div>
                    <div class="kpi-value"><?php echo $atRisk; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Finalizate în perioadă</span>
                        <span class="kpi-icon">✓</span>
                    </div>
                    <div class="kpi-value"><?php echo $completedPeriod; ?></div>
                </div>
              </div>
            </div>

            <!-- Tabel recepții -->
            <div class="card">
              <div class="card-header">
                <div class="card-title-wrap">
                  <h3>Recepții în monitorizare</h3>
                </div>
              </div>
              <div class="table-wrap">
                <table class="data-table" id="table-receptii-active">
                  <thead>
                    <tr>
                      <th>Nr. recepție</th>
                      <th>Furnizor</th>
                      <th>Status</th>
                      <th>Prioritate</th>
                      <th>Oră sosire</th>
                      <th>Vechime</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($tableResult && $tableResult->num_rows > 0): ?>
                      <?php $totalTableRows = $tableResult->num_rows; $rowIndex = 0; ?>
                      <?php while ($row = $tableResult->fetch_assoc()): $rowIndex++; ?>
                        <?php
                          $derivedStatus   = flx_inbound_status($row);
                          $derivedPriority = flx_inbound_priority($row);
                          $rowClass = $rowIndex > 10 ? 'status-' . $derivedStatus . ' extra-row hidden-row' : 'status-' . $derivedStatus;
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                          <td><strong><?php echo htmlspecialchars($row['reception_number']); ?></strong></td>
                          <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                          <td>
                            <span class="badge <?php echo $statusCss[$derivedStatus] ?? 'badge-secondary'; ?>">
                              <?php echo $statusRo[$derivedStatus] ?? ucfirst(str_replace('_', ' ', $derivedStatus)); ?>
                            </span>
                          </td>
                          <td>
                            <span class="badge <?php echo $priorityCss[$derivedPriority] ?? 'badge-secondary'; ?>">
                              <?php echo $priorityRo[$derivedPriority] ?? ucfirst($derivedPriority); ?>
                            </span>
                          </td>
                          <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                          <td><?php echo formatElapsed($row['time_elapsed']); ?></td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="6" style="text-align: center; color: #999;">Nicio recepție în progres</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php if (!empty($totalTableRows) && $totalTableRows > 10): ?>
                <div class="table-actions">
                  <p class="table-extra-note">Se afișează primele 10 recepții din <?php echo $totalTableRows; ?> din perioada selectată.</p>
                  <button type="button" class="toggle-table-btn" data-table-target="table-receptii-active" data-expanded="0">Afișează toate</button>
                </div>
              <?php endif; ?>
            </div>
    </div>
  </main>

  <style>
    .status-processing { background-color: rgba(59, 130, 246, 0.05); }
    .status-completed { background-color: rgba(34, 197, 94, 0.05); }
    .status-pending { background-color: rgba(249, 115, 22, 0.05); }
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
