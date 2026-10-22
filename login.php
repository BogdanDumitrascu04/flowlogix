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
