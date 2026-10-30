<?php
// Admin tool: seeds a balanced set of demo orders for today.

session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

require_once 'config.php';
require_once 'csrf.php';

if (!isset($conn) || !$conn) {
    die("Conexiunea la baza de date nu este disponibilă.");
}

$today   = date('Y-m-d');
$ieri    = date('Y-m-d', strtotime('-1 day'));

// Inserts run only from the confirmation form (POST + CSRF token), never from a link.
$confirm = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valid()) {
        http_response_code(403);
        exit('Cererea a fost respinsă: token de securitate invalid. Reîncarcă pagina și încearcă din nou.');
    }
    $confirm = true;
}

// Today's demo data is seeded at most once.
$alreadyExists = false;
$chkStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM inbound_orders
     WHERE reception_number LIKE ?"
);
$chkPattern = 'DEMO-' . $today . '-%';
$chkStmt->bind_param("s", $chkPattern);
$chkStmt->execute();
$chk = $chkStmt->get_result();
if ($chk && ($chkRow = $chk->fetch_assoc())) {
    $alreadyExists = (int)$chkRow['cnt'] > 0;
}

// Today's counts, shown in the preview.
function atd_count(mysqli $c, string $t): int {
    $q = $c->query("SELECT COUNT(*) AS n FROM `$t` WHERE DATE(created_at) = CURDATE()");
    return ($q && ($row = $q->fetch_assoc())) ? (int)$row['n'] : 0;
}
$curIn = atd_count($conn, 'inbound_orders');
$curPp = atd_count($conn, 'pickpack_orders');
$curOb = atd_count($conn, 'outbound_orders');

$suppliers    = ['Nordic Goods', 'Urban Trade', 'EuroShip', 'Fresh Import', 'TransLog Expert'];
$operators    = ['Vlad Ionescu', 'Irina S', 'Monica D', 'Paul R', 'Carmen N', 'Dan V', 'Sorin L'];
$destinations = ['București', 'Timișoara', 'Cluj-Napoca', 'Oradea', 'Brașov', 'Sibiu', 'Ploiești'];

function atd_el(array $a): string {
    return $a[array_rand($a)];
}

function atd_at(string $date, int $h): string {
    return $date . ' '
        . str_pad($h,           2, '0', STR_PAD_LEFT) . ':'
        . str_pad(rand(0, 59),  2, '0', STR_PAD_LEFT) . ':'
        . str_pad(rand(0, 59),  2, '0', STR_PAD_LEFT);
}

function atd_max_num(mysqli $c, string $t): int {
    $q = $c->query("SELECT COALESCE(MAX(order_number), 1000) AS m FROM `$t`");
    return ($q && ($row = $q->fetch_assoc())) ? (int)$row['m'] + 1 : 1001;
}

$ppBase = atd_max_num($conn, 'pickpack_orders');
$obBase = atd_max_num($conn, 'outbound_orders');

/*
 * Inbound: 20 rows, 80% on time.
 * 16 finished today, 2 overdue (expected yesterday), 2 open and due today.
 * The manager dashboard counts an open row as delayed once its expected date has passed.
 */
$ibCreateH = [7, 7, 7, 8, 8, 8, 8, 9, 9, 9, 7, 8, 8, 9, 7, 8];
$ibFinishH = [10, 11, 11, 12, 12, 13, 13, 14, 14, 15, 10, 11, 13, 15, 10, 16];

$ibRows = [];
for ($i = 1; $i <= 16; $i++) {
    $ibRows[] = [
        'supplier_name'          => atd_el($suppliers),
        'reception_number'       => 'DEMO-' . $today . '-IB' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'status'                 => 'completed',
        'priority'               => 'low',
        'created_at'             => atd_at($today, $ibCreateH[$i - 1]),
        'finished_at'            => atd_at($today, $ibFinishH[$i - 1]),
        'expected_finished_date' => $today,
    ];
}
for ($i = 17; $i <= 18; $i++) {
    $ibRows[] = [
        'supplier_name'          => atd_el($suppliers),
        'reception_number'       => 'DEMO-' . $today . '-IB' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'status'                 => 'pending',
        'priority'               => 'high',
        'created_at'             => atd_at($today, 7),
        'finished_at'            => null,
        'expected_finished_date' => $ieri,
    ];
}
for ($i = 19; $i <= 20; $i++) {
    $ibRows[] = [
        'supplier_name'          => atd_el($suppliers),
        'reception_number'       => 'DEMO-' . $today . '-IB' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'status'                 => 'processing',
        'priority'               => 'medium',
        'created_at'             => atd_at($today, rand(8, 10)),
        'finished_at'            => null,
        'expected_finished_date' => $today,
    ];
}

/*
 * Pick/pack: 20 rows, 75% on time.
 * 15 finished today, 3 overdue, 2 open and due today.
 * Overdue rows keep an operator, so the shift pages list them as in progress.
 */
$ppRows = [];
for ($i = 1; $i <= 15; $i++) {
    $ppRows[] = [
        'order_number'           => $ppBase + $i - 1,
        'operator_name'          => atd_el($operators),
        'items_count'            => rand(5, 30),
        'status'                 => 'completed',
        'created_at'             => atd_at($today, rand(7, 10)),
        'finished_at'            => atd_at($today, rand(12, 16)),
        'expected_finished_date' => $today,
    ];
}
for ($i = 16; $i <= 18; $i++) {
    $ppRows[] = [
        'order_number'           => $ppBase + $i - 1,
        'operator_name'          => atd_el($operators),
        'items_count'            => rand(5, 20),
        'status'                 => 'in_progress',
        'created_at'             => atd_at($today, rand(7, 8)),
        'finished_at'            => null,
        'expected_finished_date' => $ieri,
    ];
}
for ($i = 19; $i <= 20; $i++) {
    $ppRows[] = [
        'order_number'           => $ppBase + $i - 1,
        'operator_name'          => atd_el($operators),
        'items_count'            => rand(8, 25),
        'status'                 => 'in_progress',
        'created_at'             => atd_at($today, rand(9, 11)),
        'finished_at'            => null,
        'expected_finished_date' => $today,
    ];
}

/*
 * Outbound: 20 rows, 75% on time (15 shipped / 20).
 * 14 shipped on time, 1 shipped late, 3 overdue, 2 ready for today.
 * The ready rows are planned for today, so expedieri-urgente.php?period=today lists them.
 */
$obRows = [];
for ($i = 1; $i <= 14; $i++) {
    $planH = rand(13, 17);
    $obRows[] = [
        'order_number'  => $obBase + $i - 1,
        'destination'   => atd_el($destinations),
        'planned_date'  => atd_at($today, $planH),
        'delay_minutes' => 0,
        'status'        => 'shipped',
        'created_at'    => atd_at($today, rand(6, 10)),
        'finished_at'   => atd_at($today, rand(11, 13)),
    ];
}
$obRows[] = [
    'order_number'  => $obBase + 14,
    'destination'   => atd_el($destinations),
    'planned_date'  => atd_at($ieri, rand(14, 16)),
    'delay_minutes' => rand(60, 180),
    'status'        => 'shipped',
    'created_at'    => atd_at($today, rand(6, 7)),
    'finished_at'   => atd_at($today, rand(9, 11)),
];
for ($i = 16; $i <= 18; $i++) {
    $obRows[] = [
        'order_number'  => $obBase + $i - 1,
        'destination'   => atd_el($destinations),
        'planned_date'  => atd_at($ieri, rand(10, 16)),
        'delay_minutes' => rand(30, 120),
        'status'        => 'delayed',
        'created_at'    => atd_at($today, rand(6, 9)),
        'finished_at'   => null,
    ];
}
for ($i = 19; $i <= 20; $i++) {
    $obRows[] = [
        'order_number'  => $obBase + $i - 1,
        'destination'   => atd_el($destinations),
        'planned_date'  => atd_at($today, rand(14, 18)),
        'delay_minutes' => 0,
        'status'        => 'ready',
        'created_at'    => atd_at($today, rand(9, 11)),
        'finished_at'   => null,
    ];
}

function atd_insert(mysqli $c, string $table, array $rows): int {
    $n = 0;
    foreach ($rows as $row) {
        // Column names come from this file; only values come from data and are bound as parameters.
        $cols = '`' . implode('`,`', array_keys($row)) . '`';
        $placeholders = implode(',', array_fill(0, count($row), '?'));
        $values = array_values($row);
        $stmt = $c->prepare("INSERT INTO `$table` ($cols) VALUES ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        if ($stmt->execute()) {
            $n++;
        }
    }
    return $n;
}

// KPI preview: same rules as dashboard-manager.php for period=today.
function atd_kpi(mysqli $c): array {
    $df = "DATE(created_at) = CURDATE()";
    $ib = $c->query("
        SELECT COUNT(*) AS t,
            SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN finished_at IS NULL
                     AND (expected_finished_date < CURDATE() OR DATE(created_at) < CURDATE())
                 THEN 1 ELSE 0 END) AS dl,
            SUM(CASE WHEN finished_at IS NULL
                     AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE())
                     AND DATE(created_at) >= CURDATE()
                 THEN 1 ELSE 0 END) AS ip
        FROM inbound_orders WHERE $df")->fetch_assoc();
    $pp = $c->query("
        SELECT COUNT(*) AS t,
            SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN finished_at IS NULL AND expected_finished_date < CURDATE()
                 THEN 1 ELSE 0 END) AS dl,
            SUM(CASE WHEN finished_at IS NULL
                     AND (expected_finished_date IS NULL OR expected_finished_date >= CURDATE())
                 THEN 1 ELSE 0 END) AS ip
        FROM pickpack_orders WHERE $df")->fetch_assoc();
    $ob = $c->query("
        SELECT COUNT(*) AS t,
            SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN finished_at IS NULL AND DATE(planned_date) < CURDATE()
                 THEN 1 ELSE 0 END) AS dl,
            SUM(CASE WHEN finished_at IS NULL AND DATE(planned_date) >= CURDATE()
                 THEN 1 ELSE 0 END) AS ip
        FROM outbound_orders WHERE $df")->fetch_assoc();

    $out = [];
    foreach (['ib' => $ib, 'pp' => $pp, 'ob' => $ob] as $k => $row) {
        $t = (int)($row['t'] ?? 0);
        $o = (int)($row['ok'] ?? 0);
        $out[$k] = [
            'total'   => $t,
            'in_term' => $o,
            'delayed' => (int)($row['dl'] ?? 0),
            'in_prog' => (int)($row['ip'] ?? 0),
            'grad'    => $t > 0 ? round($o / $t * 100) : 0,
        ];
    }
    $tot = $out['ib']['total']   + $out['pp']['total']   + $out['ob']['total'];
    $trm = $out['ib']['in_term'] + $out['pp']['in_term'] + $out['ob']['in_term'];
    $out['total']   = $tot;
    $out['in_term'] = $trm;
    $out['grad']    = $tot > 0 ? round($trm / $tot * 100) : 0;
    return $out;
}

$insertedIb = $insertedPp = $insertedOb = 0;
$insertError = null;
$afterKpi    = null;

if ($confirm && !$alreadyExists) {
    $conn->begin_transaction();
    try {
        $insertedIb = atd_insert($conn, 'inbound_orders',  $ibRows);
        $insertedPp = atd_insert($conn, 'pickpack_orders', $ppRows);
        $insertedOb = atd_insert($conn, 'outbound_orders', $obRows);
        if ($insertedIb !== 20 || $insertedPp !== 20 || $insertedOb !== 20) {
            throw new Exception(
                "Inserare incompletă: Inbound=$insertedIb, Pick/Pack=$insertedPp, Outbound=$insertedOb"
            );
        }
        $conn->commit();
        $afterKpi = atd_kpi($conn);
    } catch (Exception $e) {
        $conn->rollback();
        $insertError = $e->getMessage();
        $insertedIb  = $insertedPp = $insertedOb = 0;
    }
}

$showSuccess = ($confirm && !$alreadyExists && $insertError === null && $insertedIb > 0);
$showPreview = (!$confirm && !$alreadyExists);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Date demo pentru azi — FlowLogyx Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      font-size: 14px;
      color: #1e293b;
      background: #f1f5f9;
      padding: 32px 20px;
    }
    .page {
      max-width: 800px;
      margin: 0 auto;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 20px rgba(0,0,0,.08);
      padding: 36px 40px;
    }
    .page-header { border-bottom: 2px solid #1e40af; padding-bottom: 16px; margin-bottom: 24px; }
    .page-header h1 { font-size: 20px; font-weight: 700; color: #0f172a; }
    .page-header p  { font-size: 13px; color: #64748b; margin-top: 6px; }

    .notice {
      background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;
      padding: 12px 16px; font-size: 13px; color: #1d4ed8; margin-bottom: 24px; line-height: 1.6;
    }
    .dup-banner {
      background: #fefce8; border: 1px solid #fde68a; border-radius: 8px;
      padding: 14px 18px; color: #92400e; font-size: 14px; font-weight: 600; margin-bottom: 20px;
    }
    .ok-banner {
      background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
      padding: 14px 18px; color: #166534; font-size: 14px; font-weight: 600; margin-bottom: 20px;
    }
    .err-banner {
      background: #fff7f7; border: 1px solid #fecaca; border-radius: 8px;
      padding: 14px 18px; color: #b91c1c; font-size: 13px; margin-bottom: 20px;
    }

    .section-title {
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
      color: #94a3b8; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;
      margin-bottom: 14px; margin-top: 24px;
    }

    table.tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
    table.tbl th {
      background: #f1f5f9; color: #475569; font-weight: 600;
      text-align: left; padding: 8px 12px; border: 1px solid #cbd5e1;
    }
    table.tbl td { padding: 8px 12px; border: 1px solid #e2e8f0; vertical-align: middle; }
    table.tbl tr:nth-child(even) td { background: #f8fafc; }
    table.tbl tfoot td, table.tbl tfoot th {
      font-weight: 700; background: #f1f5f9; border: 1px solid #cbd5e1;
    }
    table.tbl .num { text-align: center; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
    .b-ok     { background: #dcfce7; color: #166534; }
    .b-warn   { background: #fef3c7; color: #92400e; }
    .b-danger { background: #fee2e2; color: #b91c1c; }
    .b-blue   { background: #dbeafe; color: #1d4ed8; }
    .b-gray   { background: #f1f5f9; color: #475569; }
    .b-orange { background: #ffedd5; color: #9a3412; }

    .cnt-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;
    }
    .cnt-box {
      border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; text-align: center;
    }
    .cnt-n { font-size: 28px; font-weight: 700; color: #0f172a; }
    .cnt-l { font-size: 12px; color: #64748b; margin-top: 4px; }

    .dist-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;
    }
    .dist-box {
      border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;
    }
    .dist-box h3 { font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 10px; }
    .dist-row { display: flex; justify-content: space-between; font-size: 12px; padding: 3px 0; }
    .dist-row .lbl { color: #475569; }
    .dist-row .val { font-weight: 600; color: #0f172a; }
    .dist-grad { margin-top: 10px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 13px; font-weight: 700; text-align: center; }

    .total-kpi {
      border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 8px;
      padding: 14px 20px; text-align: center; margin-bottom: 20px;
    }
    .total-kpi .kn { font-size: 32px; font-weight: 700; color: #1d4ed8; }
    .total-kpi .kl { font-size: 13px; color: #64748b; margin-top: 4px; }

    .info-text { font-size: 13px; color: #475569; margin-bottom: 16px; line-height: 1.6; }

    .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; align-items: center; }
    .btn-primary {
      display: inline-block; background: #1e40af; color: #fff; text-decoration: none;
      padding: 10px 22px; border-radius: 6px; font-size: 14px; font-weight: 600;
    }
    .btn-primary:hover { background: #1e3a8a; }
    .btn-sec {
      display: inline-block; background: #e0e7ff; color: #3730a3; text-decoration: none;
      padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600;
    }
    .btn-sec:hover { background: #c7d2fe; }
    .btn-back {
      display: inline-block; color: #64748b; text-decoration: none;
      font-size: 13px; padding: 10px 0;
    }
    .btn-back:hover { color: #1e40af; }
    .btn-confirm-big {
      display: inline-block; background: #15803d; color: #fff; text-decoration: none;
      padding: 12px 28px; border-radius: 6px; font-size: 15px; font-weight: 700; margin-top: 4px;
      border: none; cursor: pointer; font-family: inherit;
    }
    .actions form { display: contents; }
    .btn-confirm-big:hover { background: #166534; }
  </style>
</head>
<body>
<div class="page">

  <div class="page-header">
    <h1>Date demo pentru azi</h1>
    <p>Adaugă date demo echilibrate pentru <strong><?php echo date('d.m.Y'); ?></strong></p>
  </div>

  <?php if ($alreadyExists): ?>
    <!-- Already seeded today -->
    <div class="dup-banner">
      Datele demo pentru azi există deja.
    </div>

    <div class="section-title">Înregistrări existente azi</div>
    <div class="cnt-grid">
      <div class="cnt-box"><div class="cnt-n"><?php echo $curIn; ?></div><div class="cnt-l">Recepții inbound</div></div>
      <div class="cnt-box"><div class="cnt-n"><?php echo $curPp; ?></div><div class="cnt-l">Comenzi Pick / Pack</div></div>
      <div class="cnt-box"><div class="cnt-n"><?php echo $curOb; ?></div><div class="cnt-l">Expediții outbound</div></div>
    </div>

    <div class="actions">
      <a href="admin.php" class="btn-back">&#8592; Înapoi la admin</a>
    </div>

  <?php elseif ($confirm && $insertError !== null): ?>
    <!-- Insert failed -->
    <div class="err-banner">
      <strong>Eroare la inserare:</strong> <?php echo htmlspecialchars($insertError); ?>
      <br>Tranzacția a fost anulată. Nicio înregistrare nu a fost adăugată.
    </div>
    <div class="actions">
      <a href="add_today_demo.php" class="btn-primary">&#8592; Încearcă din nou</a>
      <a href="admin.php" class="btn-back">Înapoi la admin</a>
    </div>

  <?php elseif ($showSuccess): ?>
    <!-- Success -->
    <div class="ok-banner">
      Datele demo pentru azi au fost adăugate cu succes.
    </div>

    <div class="section-title">Rânduri inserate</div>
    <table class="tbl">
      <thead>
        <tr><th>Tabel</th><th class="num">Rânduri</th></tr>
      </thead>
      <tbody>
        <tr><td>inbound_orders</td><td class="num"><?php echo $insertedIb; ?></td></tr>
        <tr><td>pickpack_orders</td><td class="num"><?php echo $insertedPp; ?></td></tr>
        <tr><td>outbound_orders</td><td class="num"><?php echo $insertedOb; ?></td></tr>
      </tbody>
      <tfoot>
        <tr><th>Total</th><th class="num"><?php echo $insertedIb + $insertedPp + $insertedOb; ?></th></tr>
      </tfoot>
    </table>

    <?php if ($afterKpi): ?>
    <div class="section-title">Situație după inserare</div>
    <table class="tbl">
      <thead>
        <tr>
          <th>Flux</th>
          <th class="num">Total</th>
          <th class="num">În termen</th>
          <th class="num">Întârziate</th>
          <th class="num">În lucru</th>
          <th class="num">Grad</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (['ib' => 'Inbound', 'pp' => 'Pick / Pack', 'ob' => 'Outbound'] as $k => $lbl):
          $f = $afterKpi[$k];
          $gc = $f['grad'];
          $gcls = $gc >= 85 ? 'b-ok' : ($gc >= 70 ? 'b-blue' : ($gc >= 50 ? 'b-warn' : 'b-danger'));
        ?>
        <tr>
          <td><?php echo $lbl; ?></td>
          <td class="num"><?php echo $f['total']; ?></td>
          <td class="num"><?php echo $f['in_term']; ?></td>
          <td class="num"><?php echo $f['delayed']; ?></td>
          <td class="num"><?php echo $f['in_prog']; ?></td>
          <td class="num"><span class="badge <?php echo $gcls; ?>"><?php echo $gc; ?>%</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th>Total general</th>
          <th class="num"><?php echo $afterKpi['total']; ?></th>
          <th class="num"><?php echo $afterKpi['in_term']; ?></th>
          <th class="num">—</th>
          <th class="num">—</th>
          <th class="num">
            <?php $g = $afterKpi['grad']; ?>
            <span class="badge <?php echo $g >= 70 ? 'b-ok' : 'b-warn'; ?>">
              <?php echo $g; ?>%
            </span>
          </th>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <div class="actions">
      <a href="admin.php" class="btn-back">&#8592; Înapoi la admin</a>
    </div>

  <?php else: ?>
    <!-- Preview -->

    <div class="section-title">Înregistrări existente azi</div>
    <div class="cnt-grid">
      <div class="cnt-box"><div class="cnt-n"><?php echo $curIn; ?></div><div class="cnt-l">Recepții inbound</div></div>
      <div class="cnt-box"><div class="cnt-n"><?php echo $curPp; ?></div><div class="cnt-l">Comenzi Pick / Pack</div></div>
      <div class="cnt-box"><div class="cnt-n"><?php echo $curOb; ?></div><div class="cnt-l">Expediții outbound</div></div>
    </div>

    <div class="section-title">Ce va fi adăugat</div>

    <div class="dist-grid">
      <div class="dist-box">
        <h3>&#x1F4E5; Inbound</h3>
        <div class="dist-row"><span class="lbl">Finalizate (în termen)</span><span class="val">16</span></div>
        <div class="dist-row"><span class="lbl">Întârziate active</span><span class="val">2</span></div>
        <div class="dist-row"><span class="lbl">În lucru</span><span class="val">2</span></div>
        <div class="dist-row"><span class="lbl">Total</span><span class="val">20</span></div>
        <div class="dist-grad"><span class="badge b-blue">Grad estimat: 80%</span></div>
      </div>
      <div class="dist-box">
        <h3>&#x1F4E6; Pick / Pack</h3>
        <div class="dist-row"><span class="lbl">Finalizate (în termen)</span><span class="val">15</span></div>
        <div class="dist-row"><span class="lbl">Restante (depășit termen)</span><span class="val">3</span></div>
        <div class="dist-row"><span class="lbl">În lucru</span><span class="val">2</span></div>
        <div class="dist-row"><span class="lbl">Total</span><span class="val">20</span></div>
        <div class="dist-grad"><span class="badge b-blue">Grad estimat: 75%</span></div>
      </div>
      <div class="dist-box">
        <h3>&#x1F69A; Outbound</h3>
        <div class="dist-row"><span class="lbl">Expediate la timp</span><span class="val">14</span></div>
        <div class="dist-row"><span class="lbl">Expediat tardiv</span><span class="val">1</span></div>
        <div class="dist-row"><span class="lbl">Întârziate (neexpediate)</span><span class="val">3</span></div>
        <div class="dist-row"><span class="lbl">Gata de expediat</span><span class="val">2</span></div>
        <div class="dist-row"><span class="lbl">Total</span><span class="val">20</span></div>
        <div class="dist-grad"><span class="badge b-blue">Grad estimat: 75%</span></div>
      </div>
    </div>

    <div class="total-kpi">
      <div class="kn">~77%</div>
      <div class="kl">Grad general estimat azi (46 / 60 operațiuni)</div>
    </div>

    <div class="actions">
      <form method="POST" action="add_today_demo.php">
        <?php echo csrf_field(); ?>
        <button type="submit" class="btn-confirm-big">Adaugă date demo pentru azi</button>
      </form>
      <a href="admin.php" class="btn-back">&#8592; Înapoi la admin</a>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
