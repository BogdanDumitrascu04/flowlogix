<?php
session_start();

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

$currentPage = 'alerte-blocaje';

$inboundBacklogQuery  = "SELECT COUNT(*) AS total FROM inbound_orders  WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())";
$pickpackBacklogQuery = "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE()";
$outboundBacklogQuery = "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE()";
$criticalQuery        = "
  SELECT COUNT(*) AS total FROM (
    SELECT DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_over
    FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
    UNION ALL
    SELECT DATEDIFF(CURDATE(), expected_finished_date)
    FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE()
    UNION ALL
    SELECT DATEDIFF(CURDATE(), DATE(planned_date))
    FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE()
  ) AS bl WHERE days_over >= 3
";

$blocajeQuery = "
(SELECT
  'inbound'               AS module,
  reception_number        AS reference,
  supplier_name           AS context,
  'Recepție nefinalizată' AS problem,
  created_at,
  expected_finished_date  AS due_date,
  DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_overdue
FROM inbound_orders
WHERE finished_at IS NULL
  AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()))

UNION ALL

(SELECT
  'pickpack'              AS module,
  CAST(order_number AS CHAR) COLLATE utf8mb4_unicode_ci AS reference,
  COALESCE(operator_name, 'Neatribuit') AS context,
  'Comandă nefinalizată'  AS problem,
  created_at,
  expected_finished_date  AS due_date,
  DATEDIFF(CURDATE(), expected_finished_date) AS days_overdue
FROM pickpack_orders
WHERE finished_at IS NULL
  AND expected_finished_date < CURDATE())

UNION ALL

(SELECT
  'outbound'              AS module,
  CAST(order_number AS CHAR) COLLATE utf8mb4_unicode_ci AS reference,
  destination             AS context,
  'Expediție neefectuată' AS problem,
  created_at,
  DATE(planned_date)      AS due_date,
  DATEDIFF(CURDATE(), DATE(planned_date)) AS days_overdue
FROM outbound_orders
WHERE finished_at IS NULL
  AND DATE(planned_date) < CURDATE())

ORDER BY days_overdue DESC, created_at ASC
";

$inboundBacklogResult  = $conn->query($inboundBacklogQuery);
$pickpackBacklogResult = $conn->query($pickpackBacklogQuery);
$outboundBacklogResult = $conn->query($outboundBacklogQuery);
$criticalResult        = $conn->query($criticalQuery);
$blocajeResult         = $conn->query($blocajeQuery);

$inboundBacklog  = ($inboundBacklogResult  && $inboundBacklogResult->num_rows  > 0) ? (int)$inboundBacklogResult->fetch_assoc()['total']  : 0;
$pickpackBacklog = ($pickpackBacklogResult && $pickpackBacklogResult->num_rows > 0) ? (int)$pickpackBacklogResult->fetch_assoc()['total'] : 0;
$outboundBacklog = ($outboundBacklogResult && $outboundBacklogResult->num_rows > 0) ? (int)$outboundBacklogResult->fetch_assoc()['total'] : 0;
$criticalCount   = ($criticalResult        && $criticalResult->num_rows        > 0) ? (int)$criticalResult->fetch_assoc()['total']        : 0;

$totalBlockages = $inboundBacklog + $pickpackBacklog + $outboundBacklog;

function moduleLabel($module) {
  switch ($module) {
    case 'inbound':  return 'Inbound';
    case 'pickpack': return 'Pick/Pack';
    case 'outbound': return 'Outbound';
    default: return ucfirst($module);
  }
}

function moduleBadgeClass($module) {
  switch ($module) {
    case 'inbound':  return 'badge-primary';
    case 'pickpack': return 'badge-warning';
    case 'outbound': return 'badge-danger';
    default: return 'badge-primary';
  }
}

function severityFromDays($days) {
  if ($days >= 3) return ['Critic',  'badge-danger'];
  if ($days >= 1) return ['Moderat', 'badge-warning'];
  return                 ['Recent',  'badge-primary'];
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowLogix | Restanțe și blocaje</title>
    <link rel="stylesheet" href="css/sidebar.css?v=14">
    <link rel="stylesheet" href="css/styles.css?v=14">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="alerte-blocaje" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Restanțe și blocaje</strong>
      </div>

      <?php include 'topbar.php'; ?>

      <div class="cards-grid kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Total blocaje</span>
            <span class="kpi-icon"><i class='bx bx-error-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalBlockages; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Inbound restanțe</span>
            <span class="kpi-icon"><i class='bx bx-package'></i></span>
          </div>
          <div class="kpi-value"><?php echo $inboundBacklog; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Pick/Pack restanțe</span>
            <span class="kpi-icon"><i class='bx bx-task'></i></span>
          </div>
          <div class="kpi-value"><?php echo $pickpackBacklog; ?></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Outbound restanțe</span>
            <span class="kpi-icon"><i class='bx bx-send'></i></span>
          </div>
          <div class="kpi-value"><?php echo $outboundBacklog; ?></div>
        </div>
      </div>

      <?php if ($criticalCount > 0): ?>
        <div class="alert-grid" style="margin-bottom: 0;">
          <div class="alert-card danger">
            <h4><?php echo $criticalCount; ?> blocaj<?php echo $criticalCount > 1 ? 'e critice' : ' critic'; ?> (>3 zile restanță)</h4>
            <p>Elemente cu întârziere mai mare de 3 zile — necesită intervenție imediată.</p>
          </div>
        </div>
      <?php endif; ?>

      <div class="card" style="margin-top: 20px;">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Blocaje operaționale</h3>
            <p>Toate restanțele curente din flux, ordonate după urgență.</p>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Modul</th>
                <th>Referință</th>
                <th>Context</th>
                <th>Problemă</th>
                <th>Scadență</th>
                <th>Zile restanță</th>
                <th>Severitate</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($blocajeResult && $blocajeResult->num_rows > 0): ?>
                <?php while ($row = $blocajeResult->fetch_assoc()): ?>
                  <?php
                    $daysOver = (int)$row['days_overdue'];
                    [$sevLabel, $sevBadge] = severityFromDays($daysOver);
                    $dueDateDisplay = $row['due_date'] ? date('d.m.Y', strtotime($row['due_date'])) : '—';
                  ?>
                  <tr class="blocaj-<?php echo $daysOver >= 3 ? 'critical' : ($daysOver >= 1 ? 'warning' : 'low'); ?>">
                    <td>
                      <span class="badge <?php echo moduleBadgeClass($row['module']); ?>">
                        <?php echo moduleLabel($row['module']); ?>
                      </span>
                    </td>
                    <td><strong><?php echo htmlspecialchars($row['reference']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['context']); ?></td>
                    <td><?php echo htmlspecialchars($row['problem']); ?></td>
                    <td><?php echo $dueDateDisplay; ?></td>
                    <td><span style="color: #dc2626; font-weight: 600;">+<?php echo $daysOver; ?> zile</span></td>
                    <td>
                      <span class="badge <?php echo $sevBadge; ?>">
                        <?php echo $sevLabel; ?>
                      </span>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" style="text-align: center; color: #16a34a; font-weight: bold; padding: 20px;">
                    <i class='bx bx-check-circle'></i> Niciun blocaj operațional activ
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>

  <style>
    .blocaj-critical { background-color: rgba(220, 38, 38, 0.06); }
    .blocaj-warning  { background-color: rgba(245, 158, 11, 0.06); }
    .blocaj-low      { background-color: rgba(59, 130, 246, 0.04); }
  </style>

  <?php $conn->close(); ?>
</body>
</html>
