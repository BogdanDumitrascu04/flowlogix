<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'manager') {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

require_once 'config.php';

$currentPage = 'dashboard';
$period      = isset($_GET['period']) ? $_GET['period'] : '30d';
$currentDate = date('Y-m-d H:i:s');
$dateFilter  = "created_at >= DATE_SUB('$currentDate', INTERVAL 30 DAY)";

switch ($period) {
    case 'today':
        $dateFilter = "DATE(created_at) = CURDATE()";
        break;
    case '7d':
        $dateFilter = "created_at >= DATE_SUB('$currentDate', INTERVAL 7 DAY)";
        break;
    case 'month':
        $dateFilter = "MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
        break;
    default:
        $period = '30d';
        break;
}

function mgr_flux_stats(mysqli $db, string $sql): array {
    $r = $db->query($sql);
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        return [
            'total'       => (int)($row['total']       ?? 0),
            'in_term'     => (int)($row['in_term']     ?? 0),
            'delayed'     => (int)($row['delayed_count'] ?? 0),
            'in_progress' => (int)($row['in_progress'] ?? 0),
        ];
    }
    return ['total' => 0, 'in_term' => 0, 'delayed' => 0, 'in_progress' => 0];
}

function mgr_grad_label(int $grad): string {
    if ($grad >= 90) return 'Foarte bun';
    if ($grad >= 70) return 'Bun';
    if ($grad >= 50) return 'Necesită atenție';
    return 'Critic';
}

function mgr_grad_badge(int $grad): string {
    if ($grad >= 90) return 'badge-success';
    if ($grad >= 70) return 'badge-primary';
    if ($grad >= 50) return 'badge-warning';
    return 'badge-danger';
}

/* Inbound */
$inboundStats = mgr_flux_stats($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS in_term,
        SUM(CASE WHEN finished_at IS NULL
                 AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
             THEN 1 ELSE 0 END) AS delayed_count,
        SUM(CASE WHEN finished_at IS NULL
                 AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE())
                 AND DATE(created_at) >= CURDATE()
             THEN 1 ELSE 0 END) AS in_progress
    FROM inbound_orders
    WHERE $dateFilter
");

/* Pick / Pack */
$pickpackStats = mgr_flux_stats($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS in_term,
        SUM(CASE WHEN finished_at IS NULL
                 AND expected_finished_date < CURDATE()
             THEN 1 ELSE 0 END) AS delayed_count,
        SUM(CASE WHEN finished_at IS NULL
                 AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE())
             THEN 1 ELSE 0 END) AS in_progress
    FROM pickpack_orders
    WHERE $dateFilter
");

/* Outbound */
$outboundStats = mgr_flux_stats($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS in_term,
        SUM(CASE WHEN finished_at IS NULL
                 AND DATE(planned_date) < CURDATE()
             THEN 1 ELSE 0 END) AS delayed_count,
        SUM(CASE WHEN finished_at IS NULL
                 AND DATE(planned_date) >= CURDATE()
             THEN 1 ELSE 0 END) AS in_progress
    FROM outbound_orders
    WHERE $dateFilter
");

$inboundTotal      = $inboundStats['total'];
$inboundInTerm     = $inboundStats['in_term'];
$inboundDelayed    = $inboundStats['delayed'];
$inboundInProgress = $inboundStats['in_progress'];

$pickpackTotal      = $pickpackStats['total'];
$pickpackInTerm     = $pickpackStats['in_term'];
$pickpackDelayed    = $pickpackStats['delayed'];
$pickpackInProgress = $pickpackStats['in_progress'];

$outboundTotal      = $outboundStats['total'];
$outboundInTerm     = $outboundStats['in_term'];
$outboundDelayed    = $outboundStats['delayed'];
$outboundInProgress = $outboundStats['in_progress'];

$totalOperations = $inboundTotal  + $pickpackTotal  + $outboundTotal;
$totalInTerm     = $inboundInTerm + $pickpackInTerm + $outboundInTerm;
$totalDelayed    = $inboundDelayed + $pickpackDelayed + $outboundDelayed;
$totalInProgress = $inboundInProgress + $pickpackInProgress + $outboundInProgress;

if ($totalOperations === 0) {
    $gradRespectare = null;
    $gradLabel      = 'Fără date';
    $gradBadge      = 'badge-primary';
} else {
    $gradRespectare = round(($totalInTerm / $totalOperations) * 100);
    $gradLabel      = mgr_grad_label($gradRespectare);
    $gradBadge      = mgr_grad_badge($gradRespectare);
}

$inboundGrad  = ($inboundTotal  > 0) ? round(($inboundInTerm  / $inboundTotal)  * 100) : 0;
$pickpackGrad = ($pickpackTotal > 0) ? round(($pickpackInTerm / $pickpackTotal) * 100) : 0;
$outboundGrad = ($outboundTotal > 0) ? round(($outboundInTerm / $outboundTotal) * 100) : 0;

if ($inboundTotal === 0) {
    $inboundGradDisplay = '—';
    $inboundGradLabel   = 'Fără date';
    $inboundGradBadge   = 'badge-primary';
} else {
    $inboundGradDisplay = $inboundGrad . '%';
    $inboundGradLabel   = mgr_grad_label($inboundGrad);
    $inboundGradBadge   = mgr_grad_badge($inboundGrad);
}

if ($pickpackTotal === 0) {
    $pickpackGradDisplay = '—';
    $pickpackGradLabel   = 'Fără date';
    $pickpackGradBadge   = 'badge-primary';
} else {
    $pickpackGradDisplay = $pickpackGrad . '%';
    $pickpackGradLabel   = mgr_grad_label($pickpackGrad);
    $pickpackGradBadge   = mgr_grad_badge($pickpackGrad);
}

if ($outboundTotal === 0) {
    $outboundGradDisplay = '—';
    $outboundGradLabel   = 'Fără date';
    $outboundGradBadge   = 'badge-primary';
} else {
    $outboundGradDisplay = $outboundGrad . '%';
    $outboundGradLabel   = mgr_grad_label($outboundGrad);
    $outboundGradBadge   = mgr_grad_badge($outboundGrad);
}

/* Acțiuni recomandate */
$actiuni = [];
if ($totalDelayed > 0) {
    $actiuni[] = 'Prioritizează situațiile întârziate.';
    $maxDelayed = max($inboundDelayed, $pickpackDelayed, $outboundDelayed);
    if ($outboundDelayed > 0 && $outboundDelayed === $maxDelayed) {
        $actiuni[] = 'Verifică livrările outbound întârziate.';
    } elseif ($inboundDelayed > 0 && $inboundDelayed === $maxDelayed) {
        $actiuni[] = 'Verifică recepțiile întârziate.';
    } elseif ($pickpackDelayed > 0 && $pickpackDelayed === $maxDelayed) {
        $actiuni[] = 'Verifică comenzile pick / pack întârziate.';
    }
} else {
    $actiuni[] = 'Nu sunt situații întârziate în perioada selectată.';
}

/* Notă demo: aspect realist la ~70–80% finalizate, ~10–20% întârziate, ~10% în lucru. */

/* Situații întârziate — top 10 */
$delayedQuery = "
SELECT flux, referinta, context_text, due_date, days_overdue
FROM (
  SELECT
    CONVERT('Inbound' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS flux,
    CONVERT(reception_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS referinta,
    CONVERT(supplier_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context_text,
    COALESCE(expected_finished_date, DATE(created_at)) AS due_date,
    DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_overdue
  FROM inbound_orders
  WHERE finished_at IS NULL
    AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
    AND $dateFilter

  UNION ALL

  SELECT
    CONVERT('Pick / Pack' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS flux,
    CONVERT(CAST(order_number AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS referinta,
    CONVERT(COALESCE(NULLIF(operator_name, ''), 'Neatribuit') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context_text,
    expected_finished_date AS due_date,
    DATEDIFF(CURDATE(), expected_finished_date) AS days_overdue
  FROM pickpack_orders
  WHERE finished_at IS NULL
    AND expected_finished_date < CURDATE()
    AND $dateFilter

  UNION ALL

  SELECT
    CONVERT('Outbound' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS flux,
    CONVERT(CAST(order_number AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS referinta,
    CONVERT(destination USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context_text,
    DATE(planned_date) AS due_date,
    DATEDIFF(CURDATE(), DATE(planned_date)) AS days_overdue
  FROM outbound_orders
  WHERE finished_at IS NULL
    AND DATE(planned_date) < CURDATE()
    AND $dateFilter
) AS d
ORDER BY days_overdue DESC
LIMIT 10
";
$delayedResult = $conn->query($delayedQuery);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Dashboard Manager logistic</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="css/styles.css?v=<?php echo time(); ?>">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
  <style>
    .kpi-grid, .cards-grid { overflow: visible; }
    .kpi-card { position: relative; overflow: visible; }
    .kpi-top { position: relative; }

    .kpi-info-btn {
      width: 22px;
      height: 22px;
      border: none;
      border-radius: 999px;
      background: rgba(37, 99, 235, 0.10);
      color: #2563eb;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: help;
      margin-left: 6px;
      flex-shrink: 0;
      vertical-align: middle;
      font-size: 13px;
      padding: 0;
      line-height: 1;
      transition: all .18s ease;
    }
    .kpi-info-btn:hover,
    .kpi-info-btn:focus {
      background: #2563eb;
      color: #ffffff;
      transform: translateY(-1px);
      outline: none;
    }

    .kpi-info-panel {
      position: absolute;
      top: 34px;
      left: 50%;
      transform: translateX(-50%) translateY(-6px);
      width: 280px;
      max-width: calc(100vw - 64px);
      background: #ffffff;
      color: #0f172a;
      border: 1px solid rgba(37, 99, 235, 0.18);
      box-shadow: 0 18px 38px rgba(15, 23, 42, 0.16);
      border-radius: 14px;
      padding: 12px 14px;
      z-index: 999;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
      text-align: left;
      white-space: normal;
      line-height: 1.45;
    }

    .kpi-info-panel strong {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #2563eb;
      margin-bottom: 6px;
    }

    .kpi-info-panel p {
      margin: 0;
      font-size: 12px;
      font-weight: 500;
      color: #334155;
    }

    .kpi-info-btn:hover + .kpi-info-panel,
    .kpi-info-btn:focus + .kpi-info-panel {
      opacity: 1;
      visibility: visible;
      transform: translateX(-50%) translateY(0);
    }

    .kpi-grid .kpi-card:first-child .kpi-info-panel {
      left: 16px;
      transform: translateX(0) translateY(-6px);
    }
    .kpi-grid .kpi-card:first-child .kpi-info-btn:hover + .kpi-info-panel,
    .kpi-grid .kpi-card:first-child .kpi-info-btn:focus + .kpi-info-panel {
      transform: translateX(0) translateY(0);
    }

    .kpi-grid .kpi-card:last-child .kpi-info-panel {
      left: auto;
      right: 16px;
      transform: translateX(0) translateY(-6px);
    }
    .kpi-grid .kpi-card:last-child .kpi-info-btn:hover + .kpi-info-panel,
    .kpi-grid .kpi-card:last-child .kpi-info-btn:focus + .kpi-info-panel {
      transform: translateX(0) translateY(0);
    }

    [data-page="dashboard-manager"] .page-shell { padding-top: 10px; }
  </style>
</head>
<body data-page="dashboard-manager" data-role="manager">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">

      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Dashboard Manager logistic</strong>
      </div>

      <?php include 'topbar.php'; ?>

      <!-- KPI cards -->
      <div class="cards-grid kpi-grid">

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Operațiuni totale</span>
            <button type="button" class="kpi-info-btn" aria-label="Informații indicator">
              <i class='bx bx-info-circle'></i>
            </button>
            <div class="kpi-info-panel">
              <strong>Ce înseamnă?</strong>
              <p>Total recepții, comenzi pick / pack și livrări outbound din perioada selectată.</p>
            </div>
            <span class="kpi-icon"><i class='bx bx-layer'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalOperations; ?></div>
          <div class="kpi-subtext"><span class="small-badge badge-primary">Toate fluxurile</span></div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">În termen</span>
            <button type="button" class="kpi-info-btn" aria-label="Informații indicator">
              <i class='bx bx-info-circle'></i>
            </button>
            <div class="kpi-info-panel">
              <strong>Ce înseamnă?</strong>
              <p>Operațiuni finalizate în perioada selectată, fără să fie marcate ca restante active.</p>
            </div>
            <span class="kpi-icon"><i class='bx bx-check-circle'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalInTerm; ?></div>
          <div class="kpi-subtext"><span class="small-badge badge-success">Finalizate</span></div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo $totalOperations > 0 ? min(100, round($totalInTerm / $totalOperations * 100)) : 0; ?>%"></div>
          </div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Întârziate</span>
            <button type="button" class="kpi-info-btn" aria-label="Informații indicator">
              <i class='bx bx-info-circle'></i>
            </button>
            <div class="kpi-info-panel">
              <strong>Ce înseamnă?</strong>
              <p>Operațiuni nefinalizate care au depășit termenul estimat sau data planificată.</p>
            </div>
            <span class="kpi-icon"><i class='bx bx-time-five'></i></span>
          </div>
          <div class="kpi-value"><?php echo $totalDelayed; ?></div>
          <div class="kpi-subtext">
            <span class="small-badge <?php echo $totalDelayed > 0 ? 'badge-danger' : 'badge-success'; ?>">
              <?php echo $totalDelayed > 0 ? 'Necesită atenție' : 'Nicio restanță'; ?>
            </span>
          </div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo $totalOperations > 0 ? min(100, round($totalDelayed / $totalOperations * 100)) : 0; ?>%; background:var(--danger, #dc2626);"></div>
          </div>
        </div>

        <div class="card kpi-card">
          <div class="kpi-top">
            <span class="label">Grad respectare termene</span>
            <button type="button" class="kpi-info-btn" aria-label="Informații indicator">
              <i class='bx bx-info-circle'></i>
            </button>
            <div class="kpi-info-panel">
              <strong>Ce înseamnă?</strong>
              <p>Procentul operațiunilor în termen din totalul operațiunilor analizate.</p>
            </div>
            <span class="kpi-icon"><i class='bx bx-trophy'></i></span>
          </div>
          <div class="kpi-value"><?php echo $gradRespectare !== null ? $gradRespectare . '%' : '—'; ?></div>
          <div class="kpi-subtext">
            <span class="small-badge <?php echo $gradBadge; ?>"><?php echo $gradLabel; ?></span>
          </div>
          <div class="kpi-progress">
            <div class="kpi-progress-bar" style="width:<?php echo $gradRespectare !== null ? min(100, $gradRespectare) : 0; ?>%"></div>
          </div>
        </div>

      </div>

      <!-- Interpretare + Acțiuni -->
      <div class="cards-grid two-col">

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Interpretare indicator</h3>
              <p>Pragurile de evaluare pentru gradul de respectare a termenelor.</p>
            </div>
          </div>
          <div class="progress-list">
            <div class="progress-row">
              <div class="row-top">
                <span>90% – 100%</span>
                <strong><span class="small-badge badge-success">Foarte bun</span></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:100%;"></div></div>
            </div>
            <div class="progress-row">
              <div class="row-top">
                <span>70% – 89%</span>
                <strong><span class="small-badge badge-primary">Bun</span></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:79%;"></div></div>
            </div>
            <div class="progress-row">
              <div class="row-top">
                <span>50% – 69%</span>
                <strong><span class="small-badge badge-warning">Necesită atenție</span></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:59%; background:var(--warning,#d97706);"></div></div>
            </div>
            <div class="progress-row">
              <div class="row-top">
                <span>Sub 50%</span>
                <strong><span class="small-badge badge-danger">Critic</span></strong>
              </div>
              <div class="progress-track"><div class="progress-fill" style="width:30%; background:var(--danger,#dc2626);"></div></div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Acțiuni recomandate</h3>
              <p>Direcții derivate din datele operaționale curente.</p>
            </div>
          </div>
          <div class="recommend-list">
            <?php foreach ($actiuni as $actiune): ?>
              <div class="recommend-item">
                <div>
                  <p><?php echo htmlspecialchars($actiune); ?></p>
                </div>
                <span class="small-badge <?php echo $totalDelayed > 0 ? 'badge-warning' : 'badge-success'; ?>">
                  <?php echo $totalDelayed > 0 ? 'Atenție' : 'OK'; ?>
                </span>
              </div>
            <?php endforeach; ?>
            <div class="recommend-item">
              <div>
                <p>Operațiuni în lucru: <strong><?php echo $totalInProgress; ?></strong> — urmărite activ.</p>
              </div>
              <span class="small-badge badge-primary">În lucru</span>
            </div>
          </div>
        </div>

      </div>

      <!-- Grafice -->
      <div class="cards-grid two-col">

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>În termen vs întârziate</h3>
              <p>Distribuția operațiunilor din perioada selectată.</p>
            </div>
          </div>
          <div class="chart-wrap chart-sm">
            <canvas id="managerStatusChart"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3>Performanță pe fluxuri</h3>
              <p>Volumul total de operațiuni pe fiecare flux logistic.</p>
            </div>
          </div>
          <div class="chart-wrap chart-sm">
            <canvas id="managerFlowChart"></canvas>
          </div>
        </div>

      </div>

      <!-- Tabel performanță pe fluxuri -->
      <div class="card" id="performanta-fluxuri">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Performanță pe fluxuri</h3>
            <p>Detaliu per flux: total, în termen, întârziate, în lucru și grad de respectare a termenelor.</p>
          </div>
        </div>
        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Flux</th>
                <th>Total</th>
                <th>În termen</th>
                <th>Întârziate</th>
                <th>În lucru</th>
                <th>Grad respectare termene</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>Inbound</strong></td>
                <td><?php echo $inboundTotal; ?></td>
                <td><?php echo $inboundInTerm; ?></td>
                <td>
                  <?php if ($inboundDelayed > 0): ?>
                    <span class="small-badge badge-danger"><?php echo $inboundDelayed; ?></span>
                  <?php else: ?>
                    <span class="small-badge badge-success">0</span>
                  <?php endif; ?>
                </td>
                <td><?php echo $inboundInProgress; ?></td>
                <td>
                  <span class="small-badge <?php echo $inboundGradBadge; ?>">
                    <?php echo $inboundGradDisplay; ?> — <?php echo $inboundGradLabel; ?>
                  </span>
                </td>
              </tr>
              <tr>
                <td><strong>Pick / Pack</strong></td>
                <td><?php echo $pickpackTotal; ?></td>
                <td><?php echo $pickpackInTerm; ?></td>
                <td>
                  <?php if ($pickpackDelayed > 0): ?>
                    <span class="small-badge badge-danger"><?php echo $pickpackDelayed; ?></span>
                  <?php else: ?>
                    <span class="small-badge badge-success">0</span>
                  <?php endif; ?>
                </td>
                <td><?php echo $pickpackInProgress; ?></td>
                <td>
                  <span class="small-badge <?php echo $pickpackGradBadge; ?>">
                    <?php echo $pickpackGradDisplay; ?> — <?php echo $pickpackGradLabel; ?>
                  </span>
                </td>
              </tr>
              <tr>
                <td><strong>Outbound</strong></td>
                <td><?php echo $outboundTotal; ?></td>
                <td><?php echo $outboundInTerm; ?></td>
                <td>
                  <?php if ($outboundDelayed > 0): ?>
                    <span class="small-badge badge-danger"><?php echo $outboundDelayed; ?></span>
                  <?php else: ?>
                    <span class="small-badge badge-success">0</span>
                  <?php endif; ?>
                </td>
                <td><?php echo $outboundInProgress; ?></td>
                <td>
                  <span class="small-badge <?php echo $outboundGradBadge; ?>">
                    <?php echo $outboundGradDisplay; ?> — <?php echo $outboundGradLabel; ?>
                  </span>
                </td>
              </tr>
              <tr style="font-weight:600; background:var(--surface,#f8fafc);">
                <td>Total general</td>
                <td><?php echo $totalOperations; ?></td>
                <td><?php echo $totalInTerm; ?></td>
                <td>
                  <?php if ($totalDelayed > 0): ?>
                    <span class="small-badge badge-danger"><?php echo $totalDelayed; ?></span>
                  <?php else: ?>
                    <span class="small-badge badge-success">0</span>
                  <?php endif; ?>
                </td>
                <td><?php echo $totalInProgress; ?></td>
                <td>
                  <span class="small-badge <?php echo $gradBadge; ?>">
                    <?php echo $gradRespectare !== null ? $gradRespectare . '%' : '—'; ?> — <?php echo $gradLabel; ?>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Situații întârziate -->
      <div class="card" id="situatii-intarziate">
        <div class="card-header">
          <div class="card-title-wrap">
            <h3>Situații întârziate</h3>
            <p>Primele 10 operațiuni nefinalizate cu termenul depășit, ordonate după numărul de zile.</p>
          </div>
          <?php if ($totalDelayed === 0): ?>
            <span class="card-tag role-accent-tag">Nicio restanță</span>
          <?php else: ?>
            <span class="card-tag" style="background:var(--danger-soft,#fee2e2);color:var(--danger,#dc2626);"><?php echo $totalDelayed; ?> restanțe</span>
          <?php endif; ?>
        </div>

        <?php if ($delayedResult && $delayedResult->num_rows > 0): ?>
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Flux</th>
                  <th>Referință</th>
                  <th>Context</th>
                  <th>Restantă din</th>
                  <th>Zile întârziere</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $delayedResult->fetch_assoc()):
                  $days  = (int)$row['days_overdue'];
                  $flux  = $row['flux'];
                  $fluxBadge = $flux === 'Inbound' ? 'badge-primary' : ($flux === 'Pick / Pack' ? 'badge-warning' : 'badge-success');
                  $daysBadge = $days >= 3 ? 'badge-danger' : 'badge-warning';
                ?>
                  <tr>
                    <td><span class="small-badge <?php echo $fluxBadge; ?>"><?php echo htmlspecialchars($flux); ?></span></td>
                    <td><strong><?php echo htmlspecialchars($row['referinta']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['context_text']); ?></td>
                    <td><?php echo $row['due_date'] ? htmlspecialchars($row['due_date']) : '—'; ?></td>
                    <td>
                      <span class="small-badge <?php echo $daysBadge; ?>">
                        +<?php echo $days; ?> <?php echo $days === 1 ? 'zi' : 'zile'; ?>
                      </span>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p style="padding:20px; color:var(--text-muted,#64748b); font-style:italic;">
            Nu există situații întârziate în perioada selectată.
          </p>
        <?php endif; ?>
      </div>

    </div>
  </main>

  <script>
    window.managerStatusChartData = {
      labels: <?php echo json_encode(['În termen', 'Întârziate', 'În lucru']); ?>,
      data:   <?php echo json_encode([$totalInTerm, $totalDelayed, $totalInProgress]); ?>
    };
    window.managerFlowChartData = {
      labels: <?php echo json_encode(['Inbound', 'Pick / Pack', 'Outbound']); ?>,
      data:   <?php echo json_encode([$inboundTotal, $pickpackTotal, $outboundTotal]); ?>
    };
  </script>

  <script>
    function changePeriod(period) {
      const url = new URL(window.location.href);
      url.searchParams.set('period', period);
      window.location.href = url.toString();
    }
  </script>

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
