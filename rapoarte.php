<?php
session_start();

$allowedRoles = ['depozit', 'manager', 'admin'];
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

$currentPage = 'rapoarte';
require_once 'config.php';
require_once 'flow_helpers.php';

$roleKey = isset($_SESSION['role']) ? $_SESSION['role'] : 'default';

$period = isset($_GET['period']) ? $_GET['period'] : '7d';

// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
$dateCondition = "created_at BETWEEN ? AND ?";
$dateParams = flx_period_params('7d');
$periodLabel   = "Ultimele 7 zile";

switch ($period) {
    case 'today':
        $dateParams = flx_period_params('today');
        $periodLabel   = "Astăzi";
        break;
    case '30d':
        $dateParams = flx_period_params('30d');
        $periodLabel   = "Ultimele 30 zile";
        break;
    case 'month':
        $dateParams = flx_period_params('month');
        $periodLabel   = "Luna curentă";
        break;
    default:
        $period = '7d';
        break;
}

function rpt_count(mysqli $db, string $sql, array $params = []): int {
    $r = flx_query($db, $sql, $params);
    return ($r && $r->num_rows > 0) ? (int)$r->fetch_assoc()['total'] : 0;
}

$totalInbound    = rpt_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE $dateCondition", $dateParams);
$totalPickpack   = rpt_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE $dateCondition", $dateParams);
$totalOutbound   = rpt_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE $dateCondition", $dateParams);

$backlogInbound  = rpt_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()) AND $dateCondition", $dateParams);
$backlogPickpack = rpt_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition", $dateParams);
$backlogOutbound = rpt_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition", $dateParams);

$completedInbound  = rpt_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NOT NULL AND $dateCondition", $dateParams);
$completedPickpack = rpt_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NOT NULL AND $dateCondition", $dateParams);
$shippedOutbound   = rpt_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NOT NULL AND $dateCondition", $dateParams);

$totalOperations = $totalInbound + $totalPickpack + $totalOutbound;
$totalCompleted  = $completedInbound + $completedPickpack + $shippedOutbound;
$totalBacklog    = $backlogInbound + $backlogPickpack + $backlogOutbound;

$completionRate = $totalOperations > 0 ? round(($totalCompleted / $totalOperations) * 100) : 0;

$flows = ['Inbound' => $totalInbound, 'Pick / Pack' => $totalPickpack, 'Outbound' => $totalOutbound];
$dominantFlow = 'Fără date';
if ($totalOperations > 0) {
    arsort($flows);
    $dominantFlow = array_key_first($flows);
}

$backlogs = ['Inbound' => $backlogInbound, 'Pick / Pack' => $backlogPickpack, 'Outbound' => $backlogOutbound];
$riskFlow = 'Fără restanțe';
if ($totalBacklog > 0) {
    arsort($backlogs);
    $riskFlow = array_key_first($backlogs);
}

function activeClass(string $current, string $value, string $base, string $extra): string {
    return $current === $value ? "$base $extra" : $base;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Rapoarte</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=13">
  <link rel="stylesheet" href="css/styles.css?v=13">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
  <style>
    /* Header controls */
    .report-hero-actions {
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .header-period-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .header-period-pill {
      display: inline-flex;
      align-items: center;
      min-height: 44px;
      padding: 0 20px;
      border-radius: 999px;
      border: 1.5px solid var(--border, #e2e8f0);
      background: var(--card-bg, #fff);
      color: var(--text, #475569);
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
      white-space: nowrap;
      transition: border-color 0.15s, color 0.15s;
    }
    .header-period-pill:hover {
      border-color: var(--primary, #2563eb);
      color: var(--primary, #2563eb);
    }
    .header-period-pill-active {
      border-color: var(--primary, #2563eb);
      background: var(--primary, #2563eb);
      color: #fff;
      font-weight: 600;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.18);
    }
    .header-period-pill-active:hover { color: #fff; }
    .header-pdf-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 44px;
      padding: 0 22px;
      border-radius: 10px;
      border: 1.5px solid var(--border, #cbd5e1);
      background: transparent;
      color: var(--text, #334155);
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
      white-space: nowrap;
      transition: border-color 0.15s, color 0.15s;
    }
    .header-pdf-btn:hover {
      border-color: var(--primary, #2563eb);
      color: var(--primary, #2563eb);
    }

    /* Diagnostic tiles */
    .diag-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-bottom: 18px;
    }
    @media (max-width: 900px) {
      .diag-grid { grid-template-columns: repeat(2, 1fr); }
    }
    .diag-tile {
      text-align: center;
      padding: 15px 10px;
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 10px;
      background: var(--card-bg, #fff);
    }
    .diag-tile-alert { border-color: #fca5a5; background: #fff7f7; }
    .diag-tile-ok    { border-color: #bbf7d0; background: #f0fdf4; }
    .diag-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-muted, #94a3b8);
      margin-bottom: 8px;
    }
    .diag-value    { font-size: 24px; font-weight: 700; color: var(--text, #0f172a); }
    .diag-value-sm { font-size: 16px; font-weight: 700; color: var(--text, #0f172a); }
    .diag-alert    { color: #dc2626; }
    .diag-ok       { color: #16a34a; }

    /* Export section header */
    .export-section-header {
      margin-bottom: 14px;
    }
    .export-section-header h2 {
      font-size: 16px;
      font-weight: 700;
      color: var(--text, #0f172a);
      margin: 0 0 4px;
    }
    .export-section-header p {
      font-size: 13px;
      color: var(--text-muted, #64748b);
      margin: 0;
    }

    /* 4-column CSV export grid */
    .export-grid-four {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
    }
    @media (max-width: 900px) {
      .export-grid-four { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 560px) {
      .export-grid-four { grid-template-columns: 1fr; }
    }
    .export-card {
      display: flex;
      flex-direction: column;
    }
    .export-card a {
      margin-top: auto;
    }
  </style>
</head>
<body data-page="rapoarte">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">

      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Rapoarte</strong>
      </div>

      <!-- Header with integrated period selector and PDF button -->
      <div class="topbar report-hero">
        <div class="topbar-left report-hero-content">
          <h1>Rapoarte depozit</h1>
          <p>Alege perioada și descarcă rapoarte operaționale pe fluxuri.</p>
        </div>
        <div class="topbar-right report-hero-actions">
          <div class="header-period-pills">
            <a href="rapoarte.php?period=today"
               class="<?php echo activeClass($period, 'today', 'header-period-pill', 'header-period-pill-active'); ?>">Astăzi</a>
            <a href="rapoarte.php?period=7d"
               class="<?php echo activeClass($period, '7d', 'header-period-pill', 'header-period-pill-active'); ?>">Ultimele 7 zile</a>
            <a href="rapoarte.php?period=30d"
               class="<?php echo activeClass($period, '30d', 'header-period-pill', 'header-period-pill-active'); ?>">Ultimele 30 zile</a>
            <a href="rapoarte.php?period=month"
               class="<?php echo activeClass($period, 'month', 'header-period-pill', 'header-period-pill-active'); ?>">Luna curentă</a>
          </div>
          <a href="raport-depozit.php?period=<?php echo urlencode($period); ?>"
             target="_blank"
             class="header-pdf-btn">
            <i class='bx bx-file'></i>
            <span>Raport PDF</span>
          </a>
        </div>
      </div>

      <!-- Diagnostic operațional -->
      <div class="card">
        <div class="card-header">
          <div class="card-title-wrap">
            <h2><i class='bx bx-pulse'></i> Diagnostic operațional</h2>
            <p>Perioadă: <strong><?php echo htmlspecialchars($periodLabel); ?></strong></p>
          </div>
        </div>

        <div class="diag-grid">

          <div class="diag-tile">
            <div class="diag-label">Flux dominant</div>
            <div class="diag-value-sm"><?php echo htmlspecialchars($dominantFlow); ?></div>
          </div>

          <div class="diag-tile <?php echo $totalBacklog > 0 ? 'diag-tile-alert' : ''; ?>">
            <div class="diag-label">Zonă cu risc</div>
            <div class="diag-value-sm <?php echo $totalBacklog > 0 ? 'diag-alert' : ''; ?>"><?php echo htmlspecialchars($riskFlow); ?></div>
          </div>

          <div class="diag-tile">
            <div class="diag-label">Grad finalizare</div>
            <div class="diag-value"><?php echo $completionRate; ?>%</div>
          </div>

          <div class="diag-tile <?php echo $totalBacklog > 0 ? 'diag-tile-alert' : 'diag-tile-ok'; ?>">
            <div class="diag-label">Intervenții necesare</div>
            <div class="diag-value <?php echo $totalBacklog > 0 ? 'diag-alert' : 'diag-ok'; ?>"><?php echo $totalBacklog; ?></div>
          </div>

        </div>

        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px 12px; border:1px solid var(--border,#e2e8f0); background:var(--surface,#f8fafc); font-weight:600; color:var(--text-muted,#64748b);">Flux</th>
              <th style="text-align:center; padding:8px 12px; border:1px solid var(--border,#e2e8f0); background:var(--surface,#f8fafc); font-weight:600; color:var(--text-muted,#64748b);">Total</th>
              <th style="text-align:center; padding:8px 12px; border:1px solid var(--border,#e2e8f0); background:var(--surface,#f8fafc); font-weight:600; color:var(--text-muted,#64748b);">Restanțe</th>
              <th style="text-align:center; padding:8px 12px; border:1px solid var(--border,#e2e8f0); background:var(--surface,#f8fafc); font-weight:600; color:var(--text-muted,#64748b);">Finalizate</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); color:var(--text,#0f172a);">Inbound</td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:var(--text,#0f172a);"><?php echo $totalInbound; ?></td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:<?php echo $backlogInbound > 0 ? '#dc2626' : 'var(--text,#0f172a)'; ?>;"><?php echo $backlogInbound; ?></td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:var(--text,#0f172a);"><?php echo $completedInbound; ?></td>
            </tr>
            <tr style="background:var(--surface,#f8fafc);">
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); color:var(--text,#0f172a);">Pick / Pack</td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:var(--text,#0f172a);"><?php echo $totalPickpack; ?></td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:<?php echo $backlogPickpack > 0 ? '#dc2626' : 'var(--text,#0f172a)'; ?>;"><?php echo $backlogPickpack; ?></td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:var(--text,#0f172a);"><?php echo $completedPickpack; ?></td>
            </tr>
            <tr>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); color:var(--text,#0f172a);">Outbound</td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:var(--text,#0f172a);"><?php echo $totalOutbound; ?></td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:<?php echo $backlogOutbound > 0 ? '#dc2626' : 'var(--text,#0f172a)'; ?>;"><?php echo $backlogOutbound; ?></td>
              <td style="padding:7px 12px; border:1px solid var(--border,#e2e8f0); text-align:center; font-weight:600; color:var(--text,#0f172a);"><?php echo $shippedOutbound; ?></td>
            </tr>
          </tbody>
        </table>

        <p style="margin-top:10px; font-size:12px; color:var(--text-muted,#94a3b8);">
          Diagnosticul este calculat automat pe baza datelor operaționale din perioada selectată.
        </p>
      </div>

      <!-- Exporturi disponibile -->
      <div class="export-section-header">
        <h2>Exporturi disponibile</h2>
        <p>Descarcă rapoarte CSV pentru perioada selectată.</p>
      </div>

      <div class="export-grid-four">

        <div class="card export-card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3><i class='bx bx-layer' style="color:var(--primary,#2563eb);"></i> Toate fluxurile</h3>
              <p>Export complet cu recepții, comenzi pick / pack și livrări outbound.</p>
            </div>
          </div>
          <a href="export_report.php?period=<?php echo urlencode($period); ?>&area=all&detail=detailed"
             class="control-button primary"
             style="display:flex; align-items:center; justify-content:center; gap:6px; text-decoration:none; margin-top:14px;">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>

        <div class="card export-card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3><i class='bx bx-log-in-circle' style="color:var(--primary,#2563eb);"></i> Inbound</h3>
              <p>Recepții, furnizori, statusuri calculate și restanțe.</p>
            </div>
          </div>
          <a href="export_report.php?period=<?php echo urlencode($period); ?>&area=inbound&detail=detailed"
             class="control-button primary"
             style="display:flex; align-items:center; justify-content:center; gap:6px; text-decoration:none; margin-top:14px;">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>

        <div class="card export-card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3><i class='bx bx-box' style="color:var(--primary,#2563eb);"></i> Pick / Pack</h3>
              <p>Comenzi, operatori, progres operațional și restanțe.</p>
            </div>
          </div>
          <a href="export_report.php?period=<?php echo urlencode($period); ?>&area=pickpack&detail=detailed"
             class="control-button primary"
             style="display:flex; align-items:center; justify-content:center; gap:6px; text-decoration:none; margin-top:14px;">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>

        <div class="card export-card">
          <div class="card-header">
            <div class="card-title-wrap">
              <h3><i class='bx bx-log-out-circle' style="color:var(--primary,#2563eb);"></i> Outbound</h3>
              <p>Livrări, destinații, întârzieri și statusuri calculate.</p>
            </div>
          </div>
          <a href="export_report.php?period=<?php echo urlencode($period); ?>&area=outbound&detail=detailed"
             class="control-button primary"
             style="display:flex; align-items:center; justify-content:center; gap:6px; text-decoration:none; margin-top:14px;">
            <i class='bx bx-download'></i>
            <span>Descarcă CSV</span>
          </a>
        </div>

      </div>

    </div>
  </main>

  <?php $conn->close(); ?>
</body>
</html>
