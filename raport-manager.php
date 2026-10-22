<?php
session_start();

$allowedRoles = ['manager', 'admin'];
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

require_once 'config.php';
require_once 'flow_helpers.php';

$userName = htmlspecialchars($_SESSION['user'] ?? '—');

$period        = isset($_GET['period']) ? $_GET['period'] : '30d';
// Filtrul de perioadă e un fragment SQL fix; limitele intervalului se leagă ca parametri (?)
$dateFilter = "created_at BETWEEN ? AND ?";
$dateParams = flx_period_params('30d');
$periodLabel   = "Ultimele 30 zile";

switch ($period) {
    case 'today':
        $dateParams = flx_period_params('today');
        $periodLabel = "Astăzi";
        break;
    case '7d':
        $dateParams = flx_period_params('7d');
        $periodLabel = "Ultimele 7 zile";
        break;
    case 'month':
        $dateParams = flx_period_params('month');
        $periodLabel = "Luna curentă";
        break;
    default:
        $period = '30d';
        break;
}

function rpt_mgr_flux(mysqli $db, string $sql, array $params = []): array {
    $r = flx_query($db, $sql, $params);
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

function rpt_mgr_grad_label(int $grad): string {
    if ($grad >= 90) return 'Foarte bun';
    if ($grad >= 70) return 'Bun';
    if ($grad >= 50) return 'Necesită atenție';
    return 'Critic';
}

/* Inbound */
$inboundStats = rpt_mgr_flux($conn, "
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
", $dateParams);

/* Pick / Pack */
$pickpackStats = rpt_mgr_flux($conn, "
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
", $dateParams);

/* Outbound */
$outboundStats = rpt_mgr_flux($conn, "
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
", $dateParams);

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
} else {
    $gradRespectare = round(($totalInTerm / $totalOperations) * 100);
    $gradLabel      = rpt_mgr_grad_label($gradRespectare);
}

$inboundGrad  = ($inboundTotal  > 0) ? round(($inboundInTerm  / $inboundTotal)  * 100) : 0;
$pickpackGrad = ($pickpackTotal > 0) ? round(($pickpackInTerm / $pickpackTotal) * 100) : 0;
$outboundGrad = ($outboundTotal > 0) ? round(($outboundInTerm / $outboundTotal) * 100) : 0;

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
$delayedResult = flx_query($conn, $delayedQuery, array_merge($dateParams, $dateParams, $dateParams));

$generatedAt = date('d.m.Y H:i');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Raport managerial — FlowLogix</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      font-size: 13px;
      color: #1e293b;
      background: #f1f5f9;
      padding: 32px 24px;
    }

    .print-actions {
      max-width: 960px;
      margin: 0 auto 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      justify-content: flex-end;
    }
    .btn-back {
      color: #64748b;
      text-decoration: none;
      font-size: 13px;
    }
    .btn-back:hover { color: #1e40af; }
    .btn-print {
      background: #1e40af;
      color: #fff;
      border: none;
      padding: 9px 20px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }
    .btn-print:hover { background: #1e3a8a; }

    .page {
      max-width: 960px;
      margin: 0 auto;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 20px rgba(0,0,0,.08);
      padding: 40px 48px;
    }

    .report-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 2px solid #1e40af;
      padding-bottom: 18px;
      margin-bottom: 32px;
    }
    .report-logo {
      font-size: 11px;
      font-weight: 700;
      color: #1e40af;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      margin-bottom: 5px;
    }
    .report-title {
      font-size: 22px;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.2;
    }
    .report-subtitle {
      font-size: 12px;
      color: #64748b;
      margin-top: 4px;
    }
    .report-meta {
      font-size: 12px;
      color: #64748b;
      line-height: 2;
      text-align: right;
    }
    .report-meta strong { color: #334155; }

    .section { margin-bottom: 32px; }
    .section-title {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: #94a3b8;
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 7px;
      margin-bottom: 16px;
    }

    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
    }
    .kpi-box {
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 18px 12px;
      text-align: center;
    }
    .kpi-box .kpi-label {
      font-size: 11px;
      color: #64748b;
      margin-bottom: 8px;
      line-height: 1.4;
      min-height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .kpi-box .kpi-num {
      font-size: 34px;
      font-weight: 700;
      color: #0f172a;
      line-height: 1;
    }
    .kpi-box .kpi-sub {
      font-size: 11px;
      color: #64748b;
      margin-top: 6px;
    }
    .kpi-box.kpi-alert { border-color: #fecaca; background: #fff7f7; }
    .kpi-box.kpi-alert .kpi-num { color: #dc2626; }
    .kpi-box.kpi-ok   { border-color: #bbf7d0; background: #f0fdf4; }

    .interp-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
    }
    .interp-box {
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      padding: 12px 10px;
      text-align: center;
    }
    .interp-range  { font-size: 13px; font-weight: 700; color: #0f172a; }
    .interp-label  { font-size: 11px; color: #64748b; margin-top: 4px; }
    .interp-verde  { border-color: #bbf7d0; background: #f0fdf4; }
    .interp-albastru { border-color: #bfdbfe; background: #eff6ff; }
    .interp-galben { border-color: #fde68a; background: #fffbeb; }
    .interp-rosu   { border-color: #fecaca; background: #fff7f7; }

    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    thead th {
      background: #f1f5f9;
      color: #475569;
      font-weight: 600;
      text-align: left;
      padding: 9px 12px;
      border: 1px solid #cbd5e1;
    }
    tbody td {
      padding: 8px 12px;
      border: 1px solid #e2e8f0;
      vertical-align: middle;
    }
    tbody tr:nth-child(even) td { background: #f8fafc; }

    .badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
    }
    .badge-danger  { background: #fee2e2; color: #b91c1c; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-ok      { background: #dcfce7; color: #166534; }
    .badge-blue    { background: #dbeafe; color: #1d4ed8; }

    .mod-badge {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
    }
    .mod-inbound  { background: #dbeafe; color: #1d4ed8; }
    .mod-pickpack { background: #ede9fe; color: #6d28d9; }
    .mod-outbound { background: #d1fae5; color: #065f46; }

    td.num { text-align: center; font-weight: 600; font-size: 14px; }
    tfoot td { font-weight: 700; background: #f1f5f9; border: 1px solid #cbd5e1; }
    tfoot td.num { font-size: 15px; }

    .observation {
      background: #f8fafc;
      border-left: 3px solid #94a3b8;
      padding: 13px 16px;
      border-radius: 0 6px 6px 0;
      font-size: 12px;
      color: #475569;
      line-height: 1.8;
    }

    .no-data {
      text-align: center;
      color: #94a3b8;
      padding: 24px;
      font-style: italic;
      border: 1px dashed #e2e8f0;
      border-radius: 6px;
    }

    @media print {
      body { background: #fff !important; padding: 0 !important; }
      .print-actions { display: none !important; }
      .page {
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 20px 28px !important;
        max-width: 100% !important;
      }
      .kpi-box   { break-inside: avoid; }
      .interp-box { break-inside: avoid; }
      .section   { break-inside: avoid; }
      tr         { break-inside: avoid; }
      table      { break-inside: auto; }
      a          { color: inherit !important; text-decoration: none !important; }
    }

    @page {
      size: A4;
      margin: 15mm;
    }
  </style>
</head>
<body>

  <div class="print-actions">
    <a href="dashboard-manager.php?period=<?php echo urlencode($period); ?>" class="btn-back">&#8592; Înapoi la dashboard</a>
    <button class="btn-print" onclick="window.print()">&#128438; Printează / Salvează PDF</button>
  </div>

  <div class="page">

    <div class="report-header">
      <div>
        <div class="report-logo">FlowLogix</div>
        <div class="report-title">Raport managerial logistic</div>
        <div class="report-subtitle">Sinteză automată din datele operaționale</div>
      </div>
      <div class="report-meta">
        <div><strong>Perioadă:</strong> <?php echo htmlspecialchars($periodLabel); ?></div>
        <div><strong>Generat:</strong> <?php echo $generatedAt; ?></div>
        <div><strong>Utilizator:</strong> <?php echo $userName; ?></div>
      </div>
    </div>

    <!-- Secțiunea 1: KPI-uri principale -->
    <div class="section">
      <div class="section-title">KPI-uri principale</div>
      <div class="kpi-grid">
        <div class="kpi-box">
          <div class="kpi-label">Operațiuni totale</div>
          <div class="kpi-num"><?php echo $totalOperations; ?></div>
          <div class="kpi-sub">Inbound + Pick/Pack + Outbound</div>
        </div>
        <div class="kpi-box kpi-ok">
          <div class="kpi-label">În termen</div>
          <div class="kpi-num"><?php echo $totalInTerm; ?></div>
          <div class="kpi-sub">Finalizate</div>
        </div>
        <div class="kpi-box <?php echo $totalDelayed > 0 ? 'kpi-alert' : ''; ?>">
          <div class="kpi-label">Întârziate</div>
          <div class="kpi-num"><?php echo $totalDelayed; ?></div>
          <div class="kpi-sub">Termen depășit</div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Grad respectare termene</div>
          <div class="kpi-num"><?php echo $gradRespectare !== null ? $gradRespectare . '%' : '—'; ?></div>
          <div class="kpi-sub"><?php echo htmlspecialchars($gradLabel); ?></div>
        </div>
      </div>
    </div>

    <!-- Secțiunea 2: Interpretare indicator -->
    <div class="section">
      <div class="section-title">Interpretare indicator</div>
      <div class="interp-grid">
        <div class="interp-box interp-verde">
          <div class="interp-range">90% – 100%</div>
          <div class="interp-label">Foarte bun</div>
        </div>
        <div class="interp-box interp-albastru">
          <div class="interp-range">70% – 89%</div>
          <div class="interp-label">Bun</div>
        </div>
        <div class="interp-box interp-galben">
          <div class="interp-range">50% – 69%</div>
          <div class="interp-label">Necesită atenție</div>
        </div>
        <div class="interp-box interp-rosu">
          <div class="interp-range">Sub 50%</div>
          <div class="interp-label">Critic</div>
        </div>
      </div>
    </div>

    <!-- Secțiunea 3: Performanță pe fluxuri -->
    <div class="section">
      <div class="section-title">Performanță pe fluxuri</div>
      <table>
        <thead>
          <tr>
            <th>Flux</th>
            <th style="text-align:center;">Total</th>
            <th style="text-align:center;">În termen</th>
            <th style="text-align:center;">Întârziate</th>
            <th style="text-align:center;">În lucru</th>
            <th style="text-align:center;">Grad respectare termene</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="mod-badge mod-inbound">Inbound</span></td>
            <td class="num"><?php echo $inboundTotal; ?></td>
            <td class="num"><?php echo $inboundInTerm; ?></td>
            <td class="num">
              <?php if ($inboundDelayed > 0): ?>
                <span class="badge badge-danger"><?php echo $inboundDelayed; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $inboundInProgress; ?></td>
            <td style="text-align:center;">
              <?php echo $inboundGrad; ?>% — <?php echo rpt_mgr_grad_label($inboundGrad); ?>
            </td>
          </tr>
          <tr>
            <td><span class="mod-badge mod-pickpack">Pick / Pack</span></td>
            <td class="num"><?php echo $pickpackTotal; ?></td>
            <td class="num"><?php echo $pickpackInTerm; ?></td>
            <td class="num">
              <?php if ($pickpackDelayed > 0): ?>
                <span class="badge badge-danger"><?php echo $pickpackDelayed; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $pickpackInProgress; ?></td>
            <td style="text-align:center;">
              <?php echo $pickpackGrad; ?>% — <?php echo rpt_mgr_grad_label($pickpackGrad); ?>
            </td>
          </tr>
          <tr>
            <td><span class="mod-badge mod-outbound">Outbound</span></td>
            <td class="num"><?php echo $outboundTotal; ?></td>
            <td class="num"><?php echo $outboundInTerm; ?></td>
            <td class="num">
              <?php if ($outboundDelayed > 0): ?>
                <span class="badge badge-danger"><?php echo $outboundDelayed; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $outboundInProgress; ?></td>
            <td style="text-align:center;">
              <?php echo $outboundGrad; ?>% — <?php echo rpt_mgr_grad_label($outboundGrad); ?>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td><strong>Total general</strong></td>
            <td class="num"><?php echo $totalOperations; ?></td>
            <td class="num"><?php echo $totalInTerm; ?></td>
            <td class="num">
              <?php if ($totalDelayed > 0): ?>
                <span class="badge badge-danger"><?php echo $totalDelayed; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $totalInProgress; ?></td>
            <td style="text-align:center;">
              <strong><?php echo $gradRespectare !== null ? $gradRespectare . '%' : '—'; ?> — <?php echo htmlspecialchars($gradLabel); ?></strong>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Secțiunea 4: Situații întârziate -->
    <div class="section">
      <div class="section-title">Situații întârziate (top 10)</div>
      <?php if ($delayedResult && $delayedResult->num_rows > 0): ?>
        <table>
          <thead>
            <tr>
              <th>Flux</th>
              <th>Referință</th>
              <th>Context</th>
              <th>Restantă din</th>
              <th style="text-align:center;">Zile întârziere</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $delayedResult->fetch_assoc()):
              $days      = (int)$row['days_overdue'];
              $flux      = $row['flux'];
              $modClass  = $flux === 'Inbound' ? 'mod-inbound' : ($flux === 'Pick / Pack' ? 'mod-pickpack' : 'mod-outbound');
              $daysClass = $days >= 3 ? 'badge-danger' : 'badge-warning';
            ?>
              <tr>
                <td><span class="mod-badge <?php echo $modClass; ?>"><?php echo htmlspecialchars($flux); ?></span></td>
                <td><strong><?php echo htmlspecialchars($row['referinta']); ?></strong></td>
                <td><?php echo htmlspecialchars($row['context_text']); ?></td>
                <td><?php echo $row['due_date'] ? htmlspecialchars($row['due_date']) : '—'; ?></td>
                <td style="text-align:center;">
                  <span class="badge <?php echo $daysClass; ?>">
                    +<?php echo $days; ?> <?php echo $days === 1 ? 'zi' : 'zile'; ?>
                  </span>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="no-data">Nu există situații întârziate pentru perioada selectată.</p>
      <?php endif; ?>
    </div>

    <!-- Secțiunea 5: Observație -->
    <div class="section">
      <div class="section-title">Observație</div>
      <div class="observation">
        Raportul este generat automat din datele operaționale existente și nu utilizează alerte manuale.
        Toate valorile sunt calculate derivat din câmpurile <em>finished_at</em>, <em>expected_finished_date</em>
        și <em>planned_date</em> din tabelele <em>inbound_orders</em>, <em>pickpack_orders</em> și <em>outbound_orders</em>.
      </div>
    </div>

  </div>

  <?php $conn->close(); ?>
</body>
</html>
