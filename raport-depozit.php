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

require_once 'config.php';

$userName = htmlspecialchars($_SESSION['user'] ?? '—');

/* Perioadă */
$period        = isset($_GET['period']) ? $_GET['period'] : '30d';
$dateCondition = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$periodLabel   = "Ultimele 30 zile";

switch ($period) {
    case 'today':
        $dateCondition = "DATE(created_at) = CURDATE()";
        $periodLabel   = "Astăzi";
        break;
    case '7d':
        $dateCondition = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $periodLabel   = "Ultimele 7 zile";
        break;
    case 'month':
        $dateCondition = "MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())";
        $periodLabel   = "Luna curentă";
        break;
    default:
        $period = '30d';
        break;
}

function rpt_dep_count(mysqli $db, string $sql): int {
    $r = $db->query($sql);
    return ($r && $r->num_rows > 0) ? (int)$r->fetch_assoc()['total'] : 0;
}

/* Inbound */
$inboundTotal    = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE $dateCondition");
$inboundActive   = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND $dateCondition");
$inboundBacklog  = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NULL AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE()) AND $dateCondition");
$inboundComplete = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM inbound_orders WHERE finished_at IS NOT NULL AND $dateCondition");

/* Pick / Pack */
$pickpackTotal    = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE $dateCondition");
$pickpackInWork   = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND operator_name IS NOT NULL AND operator_name <> '' AND $dateCondition");
$pickpackBacklog  = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NULL AND expected_finished_date < CURDATE() AND $dateCondition");
$pickpackComplete = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM pickpack_orders WHERE finished_at IS NOT NULL AND $dateCondition");

/* Outbound */
$outboundTotal   = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE $dateCondition");
$outboundReady   = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) >= CURDATE() AND $dateCondition");
$outboundBacklog = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NULL AND DATE(planned_date) < CURDATE() AND $dateCondition");
$outboundShipped = rpt_dep_count($conn, "SELECT COUNT(*) AS total FROM outbound_orders WHERE finished_at IS NOT NULL AND $dateCondition");

$interventii = $inboundBacklog + $pickpackBacklog + $outboundBacklog;

/* Blocaje — top 10 */
$blockersQuery = "
SELECT module, ref_code, context_text, problem_text, due_date, days_overdue
FROM (
  SELECT
    CONVERT('Inbound' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS module,
    CONVERT(reception_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ref_code,
    CONVERT(supplier_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context_text,
    CONVERT('Recepție nefinalizată' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS problem_text,
    COALESCE(expected_finished_date, DATE(created_at)) AS due_date,
    DATEDIFF(CURDATE(), COALESCE(expected_finished_date, DATE(created_at))) AS days_overdue
  FROM inbound_orders
  WHERE finished_at IS NULL
    AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
    AND $dateCondition

  UNION ALL

  SELECT
    CONVERT('Pick / Pack' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS module,
    CONVERT(CAST(order_number AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ref_code,
    CONVERT(COALESCE(NULLIF(operator_name, ''), 'Neatribuit') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context_text,
    CONVERT('Comandă nefinalizată' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS problem_text,
    expected_finished_date AS due_date,
    DATEDIFF(CURDATE(), expected_finished_date) AS days_overdue
  FROM pickpack_orders
  WHERE finished_at IS NULL
    AND expected_finished_date < CURDATE()
    AND $dateCondition

  UNION ALL

  SELECT
    CONVERT('Outbound' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS module,
    CONVERT(CAST(order_number AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ref_code,
    CONVERT(destination USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context_text,
    CONVERT('Expediție neefectuată' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS problem_text,
    DATE(planned_date) AS due_date,
    DATEDIFF(CURDATE(), DATE(planned_date)) AS days_overdue
  FROM outbound_orders
  WHERE finished_at IS NULL
    AND DATE(planned_date) < CURDATE()
    AND $dateCondition
) AS bl
ORDER BY days_overdue DESC
LIMIT 10
";
$blockersResult = $conn->query($blockersQuery);

$generatedAt = date('d.m.Y H:i');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Raport depozit — FlowLogix</title>
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
    .kpi-box.kpi-alert { border-color: #fecaca; background: #fff7f7; }
    .kpi-box.kpi-alert .kpi-num { color: #dc2626; }

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
      .kpi-box { break-inside: avoid; }
      .section { break-inside: avoid; }
      tr { break-inside: avoid; }
      table { break-inside: auto; }
      a { color: inherit !important; text-decoration: none !important; }
    }

    @page {
      size: A4;
      margin: 15mm;
    }
  </style>
</head>
<body>

  <div class="print-actions">
    <a href="dashboard-depozit.php?period=<?php echo urlencode($period); ?>" class="btn-back">&#8592; Înapoi la dashboard</a>
    <button class="btn-print" onclick="window.print()">&#128438; Printează / Salvează PDF</button>
  </div>

  <div class="page">

    <div class="report-header">
      <div>
        <div class="report-logo">FlowLogix</div>
        <div class="report-title">Raport operațional depozit</div>
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
          <div class="kpi-label">Recepții înregistrate</div>
          <div class="kpi-num"><?php echo $inboundTotal; ?></div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Comenzi pick / pack</div>
          <div class="kpi-num"><?php echo $pickpackTotal; ?></div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Livrări outbound</div>
          <div class="kpi-num"><?php echo $outboundTotal; ?></div>
        </div>
        <div class="kpi-box <?php echo $interventii > 0 ? 'kpi-alert' : ''; ?>">
          <div class="kpi-label">Intervenții necesare</div>
          <div class="kpi-num"><?php echo $interventii; ?></div>
        </div>
      </div>
    </div>

    <!-- Secțiunea 2: Rezumat pe fluxuri -->
    <div class="section">
      <div class="section-title">Rezumat pe fluxuri</div>
      <table>
        <thead>
          <tr>
            <th>Flux</th>
            <th style="text-align:center;">Total</th>
            <th style="text-align:center;">Active / În lucru</th>
            <th style="text-align:center;">Restanțe</th>
            <th style="text-align:center;">Finalizate / Expediate</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="mod-badge mod-inbound">Inbound</span></td>
            <td class="num"><?php echo $inboundTotal; ?></td>
            <td class="num"><?php echo $inboundActive; ?></td>
            <td class="num">
              <?php if ($inboundBacklog > 0): ?>
                <span class="badge badge-danger"><?php echo $inboundBacklog; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $inboundComplete; ?></td>
          </tr>
          <tr>
            <td><span class="mod-badge mod-pickpack">Pick / Pack</span></td>
            <td class="num"><?php echo $pickpackTotal; ?></td>
            <td class="num"><?php echo $pickpackInWork; ?></td>
            <td class="num">
              <?php if ($pickpackBacklog > 0): ?>
                <span class="badge badge-danger"><?php echo $pickpackBacklog; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $pickpackComplete; ?></td>
          </tr>
          <tr>
            <td><span class="mod-badge mod-outbound">Outbound</span></td>
            <td class="num"><?php echo $outboundTotal; ?></td>
            <td class="num"><?php echo $outboundReady; ?></td>
            <td class="num">
              <?php if ($outboundBacklog > 0): ?>
                <span class="badge badge-danger"><?php echo $outboundBacklog; ?></span>
              <?php else: ?>
                <span class="badge badge-ok">0</span>
              <?php endif; ?>
            </td>
            <td class="num"><?php echo $outboundShipped; ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Secțiunea 3: Restanțe și blocaje -->
    <div class="section">
      <div class="section-title">Restanțe și blocaje</div>
      <?php if ($blockersResult && $blockersResult->num_rows > 0): ?>
        <table>
          <thead>
            <tr>
              <th>Modul</th>
              <th>Referință</th>
              <th>Context</th>
              <th>Problemă</th>
              <th>Scadență</th>
              <th style="text-align:center;">Zile restanță</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($bl = $blockersResult->fetch_assoc()): ?>
              <?php
                $days = (int)$bl['days_overdue'];
                $severityClass = $days >= 3 ? 'badge-danger' : ($days >= 1 ? 'badge-warning' : 'badge-ok');
                $moduleClass   = $bl['module'] === 'Inbound' ? 'mod-inbound' : ($bl['module'] === 'Pick / Pack' ? 'mod-pickpack' : 'mod-outbound');
              ?>
              <tr>
                <td><span class="mod-badge <?php echo $moduleClass; ?>"><?php echo htmlspecialchars($bl['module']); ?></span></td>
                <td><strong><?php echo htmlspecialchars($bl['ref_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($bl['context_text']); ?></td>
                <td><?php echo htmlspecialchars($bl['problem_text']); ?></td>
                <td><?php echo $bl['due_date'] ? htmlspecialchars($bl['due_date']) : '—'; ?></td>
                <td style="text-align:center;">
                  <span class="badge <?php echo $severityClass; ?>">
                    <?php echo $days > 0 ? '+' . $days . ' zile' : 'Azi'; ?>
                  </span>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="no-data">Nu există restanțe sau blocaje active pentru perioada selectată.</p>
      <?php endif; ?>
    </div>

    <!-- Secțiunea 4: Observație -->
    <div class="section">
      <div class="section-title">Observație</div>
      <div class="observation">
        Raportul este generat automat din datele operaționale existente și nu utilizează alerte stocate manual.
        Toate valorile sunt calculate derivat din câmpurile <em>finished_at</em>, <em>expected_finished_date</em>,
        <em>planned_date</em> și <em>operator_name</em>.
      </div>
    </div>

  </div>

  <?php $conn->close(); ?>
</body>
</html>
