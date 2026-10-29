<?php
// Pe serverul live, config.php are $demoMode = true și pagina arată intrarea rapidă pe rol
require_once 'config.php';
$demoEnabled = !empty($demoMode);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Autentificare</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=13">
  <link rel="stylesheet" href="css/styles.css?v=13">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
    /* ── Background: branded soft blobs (orange tură + blue depozit + purple manager) ── */
    .login-body {
      background:
        radial-gradient(ellipse 75% 65% at 12% 18%, rgba(249, 115, 22, 0.12) 0%, transparent 72%),
        radial-gradient(ellipse 70% 60% at 88% 22%, rgba(59, 130, 246, 0.14) 0%, transparent 72%),
        radial-gradient(ellipse 80% 70% at 50% 100%, rgba(139, 92, 246, 0.12) 0%, transparent 72%),
        #f8fafc;
    }

    /* ── Equal-height cards ──────────────────────────── */
    .login-brand-inner {
      display: flex;
      flex-direction: column;
      min-height: 520px;
    }

    .login-brand-content {
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    /* ── Logo: bare image, no decorative container ────── */
    .login-logo-wrap {
      width: auto;
      height: auto;
      background: none;
      border: none;
      border-radius: 0;
      display: block;
      flex-shrink: 0;
      line-height: 0;
    }

    .login-logo-wrap img {
      width: 68px;
      height: 68px;
      object-fit: contain;
      display: block;
    }

    /* ── Role card icon wrap ─────────────────────────── */
    .role-icon-wrap {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .role-preview-card i {
      margin-top: 0;
      flex-shrink: unset;
    }

    .role-preview-card {
      padding: 14px 16px;
      gap: 14px;
    }

    /* Tura — warm orange */
    .role-preview-card[data-role="tura"] {
      box-shadow: inset 3px 0 0 #f97316;
    }
    .role-preview-card[data-role="tura"] .role-icon-wrap {
      background: #fff7ed;
    }
    .role-preview-card[data-role="tura"] .role-icon-wrap i {
      color: #ea580c;
    }

    /* Depozit — blue */
    .role-preview-card[data-role="depozit"] {
      box-shadow: inset 3px 0 0 #3b82f6;
    }
    .role-preview-card[data-role="depozit"] .role-icon-wrap {
      background: #eff6ff;
    }
    .role-preview-card[data-role="depozit"] .role-icon-wrap i {
      color: #2563eb;
    }

    /* Manager — purple */
    .role-preview-card[data-role="manager"] {
      box-shadow: inset 3px 0 0 #8b5cf6;
    }
    .role-preview-card[data-role="manager"] .role-icon-wrap {
      background: #f5f3ff;
    }
    .role-preview-card[data-role="manager"] .role-icon-wrap i {
      color: #7c3aed;
    }

    /* ── Public demo: one-click entry per role ───────── */
    .demo-panel {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 16px;
      margin-bottom: 20px;
      background: #f8fafc;
    }

    .demo-panel h3 {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 15px;
      margin: 0 0 6px;
    }

    .demo-panel p {
      font-size: 13px;
      color: #475569;
      margin: 0 0 12px;
      line-height: 1.45;
    }

    .demo-panel p em {
      display: block;
      color: #64748b;
    }

    .demo-roles {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }

    .demo-role-btn {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      background: #fff;
      cursor: pointer;
      text-align: left;
      font: inherit;
      transition: border-color 0.15s, box-shadow 0.15s;
    }

    .demo-role-btn strong {
      font-size: 13px;
      color: #0f172a;
    }

    .demo-role-btn span {
      font-size: 11px;
      color: #64748b;
    }

    .demo-role-btn:hover {
      border-color: #94a3b8;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    }

    .demo-role-btn[value="tura"] { box-shadow: inset 3px 0 0 #f97316; }
    .demo-role-btn[value="depozit"] { box-shadow: inset 3px 0 0 #3b82f6; }
    .demo-role-btn[value="manager"] { box-shadow: inset 3px 0 0 #8b5cf6; }
    .demo-role-btn[value="admin"] { box-shadow: inset 3px 0 0 #ef4444; }

    .demo-panel-footer {
      font-size: 11px;
      color: #64748b;
      margin-top: 10px;
    }

    .demo-panel-footer a {
      color: #2563eb;
    }

    .demo-divider {
      text-align: center;
      font-size: 12px;
      color: #94a3b8;
      margin: 4px 0 16px;
    }

    /* ── Input wrap: allow focus ring to paint outside ── */
    .input-wrap {
      overflow: visible;
    }

    /* ── Responsive ──────────────────────────────────── */
    @media (max-width: 600px) {
      .login-logo-wrap img {
        width: 52px;
        height: 52px;
      }
      .login-brand-inner {
        min-height: auto;
      }
    }
  </style>
</head>
<body class="login-body">

  <div class="login-container">
    <!-- BRANDING PANEL (LEFT) -->
    <div class="login-brand-section">
      <div class="login-brand-inner">
        <div class="login-brand-top">
          <div class="login-logo-wrap">
            <img src="FlowLogix.png" alt="FlowLogix logo">
          </div>
          <div>
            <h1>FlowLogix</h1>
            <p>Dashboard operațional logistic</p>
          </div>
        </div>

        <div class="login-brand-content">
          <h2>Platformă logistică pentru control operațional</h2>
          <p>
            FlowLogix oferă acces rapid la recepții, comenzi, livrări și rapoarte,
            în funcție de rolul fiecărui utilizator.
          </p>

          <div class="login-role-preview">
            <div class="role-preview-card" data-role="tura">
              <div class="role-icon-wrap">
                <i class='bx bx-time-five'></i>
              </div>
              <div>
                <strong>Șef de tură</strong>
                <span>Monitorizare activă și intervenții rapide</span>
              </div>
            </div>

            <div class="role-preview-card" data-role="depozit">
              <div class="role-icon-wrap">
                <i class='bx bx-buildings'></i>
              </div>
              <div>
                <strong>Șef de depozit</strong>
                <span>Coordonarea fluxurilor și rapoarte operaționale</span>
              </div>
            </div>

            <div class="role-preview-card" data-role="manager">
              <div class="role-icon-wrap">
                <i class='bx bx-line-chart'></i>
              </div>
              <div>
                <strong>Manager logistic</strong>
                <span>Performanță generală și decizii bazate pe date</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- LOGIN FORM PANEL (RIGHT) -->
    <div class="login-form-section">
      <div class="login-form-card">
        <div class="login-form-header">
          <h2>Autentificare</h2>
          <p>Conectează-te pentru a accesa dashboard-ul FlowLogix.</p>
        </div>

        <?php if ($demoEnabled): ?>
          <div class="demo-panel">
            <h3><i class='bx bx-play-circle'></i> Demo live</h3>
            <p>
              Aplicația rulează cu date demo, actualizate la fiecare oră. Alege un rol pentru a intra.
              <em>Live demo with sample data that updates every hour. Pick a role to look around.</em>
            </p>
            <form action="demo_login.php" method="POST" class="demo-roles">
              <button type="submit" name="role" value="manager" class="demo-role-btn">
                <strong>Manager logistic</strong>
                <span>Logistics manager</span>
              </button>
              <button type="submit" name="role" value="depozit" class="demo-role-btn">
                <strong>Șef de depozit</strong>
                <span>Warehouse manager</span>
              </button>
              <button type="submit" name="role" value="tura" class="demo-role-btn">
                <strong>Șef de tură</strong>
                <span>Shift supervisor</span>
              </button>
              <button type="submit" name="role" value="admin" class="demo-role-btn">
                <strong>Administrator</strong>
                <span>Admin (read-only)</span>
              </button>
            </form>
            <div class="demo-panel-footer">
              Datele se resetează în fiecare noapte · Data resets every night ·
              <a href="https://github.com/BogdanDumitrascu04/flowlogix" target="_blank" rel="noopener">Cod sursă / Source code</a>
            </div>
          </div>
          <div class="demo-divider">sau autentifică-te cu un cont · or sign in</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
          <div class="login-alert login-alert-error">
            <i class='bx bx-exclamation-circle'></i>
            <span>Date invalide. Verifică username-ul și parola.</span>
          </div>
        <?php endif; ?>

        <form action="authenticate.php" method="POST" class="login-form">
          <div class="form-group">
            <label for="userName">Username</label>
            <div class="input-wrap">
              <i class='bx bx-user'></i>
              <input
                type="text"
                id="userName"
                name="name"
                placeholder="Introdu username"
                required
                class="form-input"
              />
            </div>
          </div>

          <div class="form-group">
            <label for="userPassword">Parolă</label>
            <div class="input-wrap input-wrap-password">
              <i class='bx bx-lock-alt'></i>
              <input
                type="password"
                id="userPassword"
                name="password"
                placeholder="Introdu parola"
                required
                class="form-input"
              />
              <button
                type="button"
                class="password-toggle"
                id="passwordToggle"
                aria-label="Arată/Ascunde parola"
              >
                <i class='bx bx-hide'></i>
              </button>
            </div>
          </div>

          <button type="submit" class="login-submit-btn">
            <i class='bx bx-log-in-circle'></i>
            <span>Autentificare</span>
          </button>
        </form>

        <div class="login-helper-info">
          <i class='bx bx-info-circle'></i>
          <p>Accesul se face pe baza contului de utilizator, iar rolul este determinat automat.</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Toggle Password Visibility
    const passwordToggle = document.getElementById('passwordToggle');
    const passwordInput = document.getElementById('userPassword');

    if (passwordToggle && passwordInput) {
      passwordToggle.addEventListener('click', function(e) {
        e.preventDefault();
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';

        // Update icon
        const icon = this.querySelector('i');
        icon.className = isPassword ? 'bx bx-show' : 'bx bx-hide';
      });
    }

    // Focus effect on inputs
    const inputs = document.querySelectorAll('.form-input');
    inputs.forEach(input => {
      input.addEventListener('focus', function() {
        this.parentElement.classList.add('focused');
      });

      input.addEventListener('blur', function() {
        this.parentElement.classList.remove('focused');
      });
    });
  </script>
</body>
</html>
