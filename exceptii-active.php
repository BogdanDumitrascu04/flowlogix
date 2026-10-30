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

$currentPage = 'exceptii-active';

// Status, priority and delay are derived from the dates (flow_helpers.php), not read from the stored columns.
$delayedInboundQuery  = "SELECT COUNT(*) as total FROM inbound_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND DATE(created_at) = CURDATE()";
$delayedPickpackQuery = "SELECT COUNT(*) as total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND DATE(created_at) = CURDATE()";
$delayedOutboundQuery = "SELECT COUNT(*) as total FROM outbound_orders WHERE (finished_at IS NULL AND DATE(planned_date) < CURDATE() OR finished_at IS NOT NULL AND DATE(finished_at) > DATE(planned_date)) AND DATE(created_at) = CURDATE()";

$exceptionsQuery = "
(SELECT
    'inbound' COLLATE utf8mb4_general_ci as flux,
    reception_number COLLATE utf8mb4_general_ci as reference,
    CASE
        WHEN expected_finished_date < CURDATE() THEN 'backlog'
        ELSE 'pending'
    END COLLATE utf8mb4_general_ci as status,
    'high' COLLATE utf8mb4_general_ci as priority,
    DATEDIFF(CURDATE(), expected_finished_date) as delay_hours,
    NULL as delay_minutes,
    created_at
FROM inbound_orders
WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND DATE(created_at) = CURDATE())

UNION

(SELECT
    'pickpack' COLLATE utf8mb4_general_ci as flux,
    CAST(order_number AS CHAR) COLLATE utf8mb4_general_ci as reference,
    'backlog' COLLATE utf8mb4_general_ci as status,
    'high' COLLATE utf8mb4_general_ci as priority,
    DATEDIFF(CURDATE(), expected_finished_date) as delay_hours,
    NULL as delay_minutes,
    created_at
FROM pickpack_orders
WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND DATE(created_at) = CURDATE())

UNION

(SELECT
    'outbound' COLLATE utf8mb4_general_ci as flux,
    CAST(order_number AS CHAR) COLLATE utf8mb4_general_ci as reference,
    'backlog' COLLATE utf8mb4_general_ci as status,
    'high' COLLATE utf8mb4_general_ci as priority,
    NULL as delay_hours,
    DATEDIFF(CURDATE(), DATE(planned_date)) as delay_minutes,
    created_at
FROM outbound_orders
WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND DATE(created_at) = CURDATE())

UNION

(SELECT
    'outbound' COLLATE utf8mb4_general_ci as flux,
    CAST(order_number AS CHAR) COLLATE utf8mb4_general_ci as reference,
    'shipped_late' COLLATE utf8mb4_general_ci as status,
    'medium' COLLATE utf8mb4_general_ci as priority,
    NULL as delay_hours,
    DATEDIFF(DATE(finished_at), DATE(planned_date)) as delay_minutes,
    created_at
FROM outbound_orders
WHERE finished_at IS NOT NULL AND DATE(finished_at) > DATE(planned_date) AND DATE(created_at) = CURDATE())

ORDER BY created_at DESC
";

$delayedInboundResult  = $conn->query($delayedInboundQuery);
$delayedPickpackResult = $conn->query($delayedPickpackQuery);
$delayedOutboundResult = $conn->query($delayedOutboundQuery);
$exceptionsResult      = $conn->query($exceptionsQuery);

$delayedInbound  = ($delayedInboundResult  && $delayedInboundResult->num_rows  > 0) ? (int)$delayedInboundResult->fetch_assoc()['total']  : 0;
$delayedPickpack = ($delayedPickpackResult && $delayedPickpackResult->num_rows > 0) ? (int)$delayedPickpackResult->fetch_assoc()['total'] : 0;
$delayedOutbound = ($delayedOutboundResult && $delayedOutboundResult->num_rows > 0) ? (int)$delayedOutboundResult->fetch_assoc()['total'] : 0;

$totalExceptions = $delayedInbound + $delayedPickpack + $delayedOutbound;
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowLogix | Excepții active</title>
    <link rel="stylesheet" href="css/sidebar.css?v=14">
    <link rel="stylesheet" href="css/styles.css?v=14">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="exceptii-active" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Excepții active</strong>
      </div>

      <?php include 'topbar.php'; ?>

      <div class="cards-grid kpi-grid">
                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Restanță inbound</span>
                        <span class="kpi-icon">⏱️</span>
                    </div>
                    <div class="kpi-value"><?php echo $delayedInbound; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Restanță pick/pack</span>
                        <span class="kpi-icon">⏱️</span>
                    </div>
                    <div class="kpi-value"><?php echo $delayedPickpack; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Restanță outbound</span>
                        <span class="kpi-icon">⏱️</span>
                    </div>
                    <div class="kpi-value"><?php echo $delayedOutbound; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Total excepții</span>
                        <span class="kpi-icon">🔴</span>
                    </div>
                    <div class="kpi-value"><?php echo $totalExceptions; ?></div>
                </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Excepții detectate</h3>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Flux</th>
                <th>Referință</th>
                <th>Status</th>
                <th>Tip excepție</th>
                <th>Timp</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($exceptionsResult && $exceptionsResult->num_rows > 0): ?>
                <?php while ($row = $exceptionsResult->fetch_assoc()): ?>
                  <?php
                    $delayVal = (int)($row['delay_hours'] ?? $row['delay_minutes'] ?? 0);
                    if ($row['flux'] === 'outbound' && $row['status'] === 'shipped_late') {
                      $exceptionType = 'Întârziere ' . $delayVal . ' zile';
                    } elseif ($delayVal > 0) {
                      $exceptionType = 'Restanță ' . $delayVal . ' zile';
                    } else {
                      $exceptionType = 'Restanță';
                    }
                  ?>
                  <tr class="status-<?php echo $row['status']; ?>">
                    <td>
                      <span class="badge" style="background-color: #3b82f6; color: white; padding: 4px 8px; border-radius: 4px;">
                        <?php echo ucfirst($row['flux']); ?>
                      </span>
                    </td>
                    <td><strong><?php echo htmlspecialchars($row['reference']); ?></strong></td>
                    <td>
                      <span class="badge badge-<?php echo $row['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                      </span>
                    </td>
                    <td>
                      <span style="color: #dc2626; font-weight: bold;">⚠️ <?php echo $exceptionType; ?></span>
                    </td>
                    <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" style="text-align: center; color: #16a34a; font-weight: bold; padding: 20px;">✓ Nicio excepție detectată</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 16px; border-radius: 10px; margin-top: 20px;">
        <p style="margin: 0; color: #166534; line-height: 1.6;">
          <strong>Excepții urmărite:</strong><br>
          • Recepții inbound nefinalizate cu expected_finished_date depășit<br>
          • Comenzi pick/pack nefinalizate cu expected_finished_date depășit<br>
          • Expedieri outbound neexpediate cu planned_date depășit<br>
          • Expedieri finalizate după planned_date (shipped late)
        </p>
      </div>
    </div>
  </main>

  <style>
    .status-backlog { background-color: rgba(220, 38, 38, 0.08); }
    .status-shipped_late { background-color: rgba(245, 158, 11, 0.08); }
    .badge-backlog { background-color: #dc2626; color: #fff; }
    .badge-shipped_late { background-color: #d97706; color: #fff; }
  </style>
</body>
</html>
