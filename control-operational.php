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

$currentPage = 'control-operational';

// INBOUND
$inboundActiveQuery = "SELECT COUNT(*) as total FROM inbound_orders WHERE finished_at IS NULL AND DATE(created_at) = CURDATE()";
$inboundTableQuery = "SELECT reception_number, supplier_name, status, priority, created_at, finished_at, expected_finished_date FROM inbound_orders WHERE finished_at IS NULL AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 5";

// PICKPACK
$pickpackActiveQuery = "SELECT COUNT(*) as total FROM pickpack_orders WHERE finished_at IS NULL AND DATE(created_at) = CURDATE()";
$pickpackTableQuery = "SELECT order_number, operator_name, items_count, status, created_at, finished_at, expected_finished_date FROM pickpack_orders WHERE finished_at IS NULL AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 5";

// OUTBOUND
$outboundActiveQuery = "SELECT COUNT(*) as total FROM outbound_orders WHERE finished_at IS NULL AND DATE(created_at) = CURDATE()";
$outboundTableQuery = "SELECT order_number, destination, created_at, planned_date, finished_at FROM outbound_orders WHERE finished_at IS NULL AND DATE(created_at) = CURDATE() ORDER BY planned_date ASC LIMIT 5";

// PROBLEME (blocaje derivate)
$problemsQuery = "
  SELECT (
    (SELECT COUNT(*) FROM inbound_orders  WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()))
    +
    (SELECT COUNT(*) FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE())
    +
    (SELECT COUNT(*) FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE())
  ) AS total
";
$blocajeTableQuery = "
(SELECT 'inbound'  AS module, reception_number AS reference, 'Recepție nefinalizată' AS problem,
  DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_overdue
FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()))
UNION ALL
(SELECT 'pickpack' AS module, CAST(order_number AS CHAR) COLLATE utf8mb4_unicode_ci, 'Comandă nefinalizată',
  DATEDIFF(CURDATE(), expected_finished_date)
FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE())
UNION ALL
(SELECT 'outbound' AS module, CAST(order_number AS CHAR) COLLATE utf8mb4_unicode_ci, 'Expediție neefectuată',
  DATEDIFF(CURDATE(), DATE(planned_date))
FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE())
ORDER BY days_overdue DESC
LIMIT 5
";

$inboundActiveResult = $conn->query($inboundActiveQuery);
$pickpackActiveResult = $conn->query($pickpackActiveQuery);
$outboundActiveResult = $conn->query($outboundActiveQuery);
$problemsResult = $conn->query($problemsQuery);

$inboundActive  = ($inboundActiveResult  && $inboundActiveResult->num_rows  > 0) ? (int)$inboundActiveResult->fetch_assoc()['total']  : 0;
$pickpackActive = ($pickpackActiveResult && $pickpackActiveResult->num_rows > 0) ? (int)$pickpackActiveResult->fetch_assoc()['total'] : 0;
$outboundActive = ($outboundActiveResult && $outboundActiveResult->num_rows > 0) ? (int)$outboundActiveResult->fetch_assoc()['total'] : 0;
$problems       = ($problemsResult       && $problemsResult->num_rows       > 0) ? (int)$problemsResult->fetch_assoc()['total']       : 0;

$inboundTableResult  = $conn->query($inboundTableQuery);
$pickpackTableResult = $conn->query($pickpackTableQuery);
$outboundTableResult = $conn->query($outboundTableQuery);
$blocajeTableResult  = $conn->query($blocajeTableQuery);
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowLogix | Control operațional</title>
    <link rel="stylesheet" href="css/sidebar.css?v=14">
    <link rel="stylesheet" href="css/styles.css?v=14">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="control-operational" data-role="tura">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Control operațional</strong>
      </div>

      <?php include 'topbar.php'; ?>

      <div class="cards-grid kpi-grid">
                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Active inbound</span>
                        <span class="kpi-icon">📥</span>
                    </div>
                    <div class="kpi-value"><?php echo $inboundActive; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Active pick/pack</span>
                        <span class="kpi-icon">📦</span>
                    </div>
                    <div class="kpi-value"><?php echo $pickpackActive; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Active outbound</span>
                        <span class="kpi-icon">📤</span>
                    </div>
                    <div class="kpi-value"><?php echo $outboundActive; ?></div>
                </div>

                <div class="card kpi-card">
                    <div class="kpi-top">
                        <span class="label">Blocaje active</span>
                        <span class="kpi-icon"><i class='bx bx-error-circle'></i></span>
                    </div>
                    <div class="kpi-value"><?php echo $problems; ?></div>
                </div>
            </div>

      <div class="cards-grid three-col">
                <!-- INBOUND -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <h3>Inbound active</h3>
                        </div>
                    </div>
                    <div class="table-wrap" style="max-height: 400px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Recepție</th>
                                    <th>Furnizor</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($inboundTableResult && $inboundTableResult->num_rows > 0): ?>
                                    <?php while ($row = $inboundTableResult->fetch_assoc()): ?>
                                        <?php $derivedStatus = flx_inbound_status($row); ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['reception_number']); ?></strong></td>
                                            <td style="font-size: 0.9em;"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $derivedStatus; ?>" style="font-size: 0.8em;">
                                                    <?php echo ucfirst(str_replace('_', ' ', $derivedStatus)); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999; font-size: 0.9em;">Nicio activitate</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- PICKPACK -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <h3>Pick/Pack active</h3>
                        </div>
                    </div>
                    <div class="table-wrap" style="max-height: 400px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Comandă</th>
                                    <th>Operator</th>
                                    <th>Art.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($pickpackTableResult && $pickpackTableResult->num_rows > 0): ?>
                                    <?php while ($row = $pickpackTableResult->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo $row['order_number']; ?></strong></td>
                                            <td style="font-size: 0.9em;"><?php echo htmlspecialchars($row['operator_name']); ?></td>
                                            <td><?php echo $row['items_count']; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999; font-size: 0.9em;">Nicio activitate</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- OUTBOUND -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <h3>Outbound active</h3>
                        </div>
                    </div>
                    <div class="table-wrap" style="max-height: 400px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Comandă</th>
                                    <th>Destinație</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($outboundTableResult && $outboundTableResult->num_rows > 0): ?>
                                    <?php while ($row = $outboundTableResult->fetch_assoc()): ?>
                                        <?php $derivedStatus = flx_outbound_status($row); ?>
                                        <tr>
                                            <td><strong><?php echo $row['order_number']; ?></strong></td>
                                            <td style="font-size: 0.9em;"><?php echo htmlspecialchars($row['destination']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $derivedStatus; ?>" style="font-size: 0.8em;">
                                                    <?php echo ucfirst(str_replace('_', ' ', $derivedStatus)); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999; font-size: 0.9em;">Nicio activitate</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Blocaje active</h3>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Modul</th>
                <th>Referință</th>
                <th>Problemă</th>
                <th>Zile restanță</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($blocajeTableResult && $blocajeTableResult->num_rows > 0): ?>
                <?php while ($row = $blocajeTableResult->fetch_assoc()): ?>
                  <?php
                    $daysOver   = (int)$row['days_overdue'];
                    $badgeClass = $daysOver >= 3 ? 'badge-danger' : ($daysOver >= 1 ? 'badge-warning' : 'badge-primary');
                  ?>
                  <tr>
                    <td>
                      <span class="badge badge-primary" style="font-size: 0.8em;">
                        <?php echo ucfirst($row['module']); ?>
                      </span>
                    </td>
                    <td><strong><?php echo htmlspecialchars($row['reference']); ?></strong></td>
                    <td style="font-size: 0.9em;"><?php echo htmlspecialchars($row['problem']); ?></td>
                    <td>
                      <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.8em;">
                        +<?php echo $daysOver; ?> zile
                      </span>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="4" style="text-align: center; color: #16a34a; font-weight: bold;">✓ Niciun blocaj detectat</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
