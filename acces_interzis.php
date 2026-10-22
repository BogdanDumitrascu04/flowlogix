<?php
/*
 * acces_interzis.php
 * Pagină comună de acces restricționat (HTTP 403).
 * Inclusă de gărzile de rol când un utilizator autentificat
 * încearcă să acceseze o pagină care nu îi este permisă rolului său.
 * Se oprește pe URL-ul paginii cerute (fără redirecționare).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

http_response_code(403);

$role = $_SESSION['role'] ?? '';

$dashboards = [
    'tura'    => 'dashboard-tura.php',
    'depozit' => 'dashboard-depozit.php',
    'manager' => 'dashboard-manager.php',
    'admin'   => 'admin.php',
];

$homeLink  = $dashboards[$role] ?? 'login.php';
$roleLabel = $role !== '' ? htmlspecialchars($role) : 'necunoscut';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <title>FlowLogix | Acces interzis</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f1f5f9;
      font-family: 'Segoe UI', Tahoma, sans-serif;
      color: #0f172a;
    }
    .denied-card {
      width: 100%;
      max-width: 460px;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
      padding: 40px 36px;
      text-align: center;
    }
    .denied-icon {
      width: 76px;
      height: 76px;
      margin: 0 auto 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: #fee2e2;
      color: #dc2626;
      font-size: 40px;
    }
    .denied-code {
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #dc2626;
      margin-bottom: 8px;
    }
    .denied-card h1 {
      font-size: 24px;
      margin: 0 0 12px;
    }
    .denied-card p {
      font-size: 15px;
      line-height: 1.6;
      color: #475569;
      margin: 0 0 8px;
    }
    .denied-role {
      font-weight: 600;
      color: #0f172a;
    }
    .denied-actions {
      margin-top: 26px;
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }
    .denied-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 11px 20px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      transition: background 0.15s, border-color 0.15s;
    }
    .denied-btn-primary {
      background: #2563eb;
      color: #fff;
    }
    .denied-btn-primary:hover { background: #1d4ed8; }
    .denied-btn-ghost {
      background: transparent;
      color: #334155;
      border: 1.5px solid #cbd5e1;
    }
    .denied-btn-ghost:hover { border-color: #2563eb; color: #2563eb; }
  </style>
</head>
<body>
  <div class="denied-card">
    <div class="denied-icon"><i class='bx bx-lock-alt'></i></div>
    <div class="denied-code">Eroare 403</div>
    <h1>Acces interzis</h1>
    <p>Nu aveți permisiunea de a accesa această pagină.</p>
    <p>Pagina solicitată nu este disponibilă pentru rolul
       <span class="denied-role"><?php echo $roleLabel; ?></span>.</p>
    <div class="denied-actions">
      <a class="denied-btn denied-btn-primary" href="<?php echo htmlspecialchars($homeLink); ?>">
        <i class='bx bx-home-alt'></i> Înapoi la panoul meu
      </a>
      <a class="denied-btn denied-btn-ghost" href="deconectare.php">
        <i class='bx bx-log-out'></i> Deconectare
      </a>
    </div>
  </div>
</body>
</html>
