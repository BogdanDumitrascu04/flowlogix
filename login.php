<?php
// Pe serverul live, config.php are $demoMode = true: cardurile de rol devin intrare directă în demo
require_once 'config.php';
$demoEnabled = !empty($demoMode);

// Rolurile prezentate pe pagină; în demo, fiecare card e un buton care deschide dashboard-ul rolului
$loginRoles = [
    'tura'    => ['icon' => 'bx-time-five',   'name' => 'Șef de tură',      'desc' => 'Monitorizare activă și intervenții rapide'],
    'depozit' => ['icon' => 'bx-buildings',   'name' => 'Șef de depozit',   'desc' => 'Coordonarea fluxurilor și rapoarte operaționale'],
    'manager' => ['icon' => 'bx-line-chart',  'name' => 'Manager logistic', 'desc' => 'Performanță generală și decizii bazate pe date'],
];
if ($demoEnabled) {
    $loginRoles['admin'] = ['icon' => 'bx-shield-quarter', 'name' => 'Administrator', 'desc' => 'Utilizatori și roluri (doar citire în demo)'];
}
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

    .login-brand-content h2 {
      font-size: 26px;
      line-height: 1.25;
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

    /* Demo: the role cards are the buttons that open each role's dashboard */
    .demo-hint {
      font-size: 13px;
      font-weight: 600;
      color: #334155;
      margin: 0 0 10px;
    }

    button.role-preview-card {
      width: 100%;
      align-items: center;
      text-align: left;
      font: inherit;
      color: inherit;
      cursor: pointer;
      background: #ffffff;
    }

    button.role-preview-card:hover,
    button.role-preview-card:focus-visible {
      border-color: #94a3b8;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
      outline: none;
    }

    .role-card-text {
      flex: 1;
    }

    .role-card-enter {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      font-weight: 600;
      color: #2563eb;
      white-space: nowrap;
    }

    .role-preview-card .role-card-enter span {
      display: inline;
      font-size: 12px;
      color: #2563eb;
    }

    .role-card-enter i {
      font-size: 18px;
      color: #2563eb;
      transition: transform 0.15s;
    }

    button.role-preview-card:hover .role-card-enter i {
      transform: translateX(3px);
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

    /* Admin — red */
    .role-preview-card[data-role="admin"] {
      box-shadow: inset 3px 0 0 #ef4444;
    }
    .role-preview-card[data-role="admin"] .role-icon-wrap {
      background: #fef2f2;
    }
    .role-preview-card[data-role="admin"] .role-icon-wrap i {
      color: #dc2626;
    }

    /* ── Page shell: the top bar sits above the cards and scrolls with the page ── */
    .login-shell {
      width: 100%;
      max-width: 1196px;
      display: flex;
      flex-direction: column;
    }

    /* ── Top-right bar: language switch + source code ── */
    .login-topbar {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      padding: 24px 24px 0;
    }

    .login-topbar + .login-container {
      padding-top: 14px;
    }

    .lang-switch {
      display: flex;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      overflow: hidden;
      background: #ffffff;
    }

    .lang-switch button {
      border: none;
      background: none;
      padding: 6px 10px;
      font: inherit;
      font-size: 12px;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
    }

    .lang-switch button.active {
      background: #0f172a;
      color: #ffffff;
    }

    .github-link {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border: 1px solid #0f172a;
      border-radius: 8px;
      background: #ffffff;
      color: #0f172a;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
    }

    .github-link i {
      font-size: 18px;
    }

    .github-link:hover {
      background: #0f172a;
      color: #ffffff;
    }

    /* ── Right card: demo note above the classic sign-in ── */
    .login-form-header h2 {
      font-size: 20px;
    }

    .demo-note {
      display: flex;
      gap: 10px;
      padding: 12px 14px;
      margin-bottom: 20px;
      border-radius: 10px;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      font-size: 13px;
      line-height: 1.5;
      color: #1e3a8a;
    }

    .demo-note i {
      font-size: 18px;
      margin-top: 1px;
    }

    /* ── Input wrap: allow focus ring to paint outside ── */
    .input-wrap {
      overflow: visible;
    }

    .input-wrap > i {
      left: 14px;
    }

    .form-input {
      padding-left: 42px;
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
      .login-topbar {
        padding: 12px 16px 0;
      }
    }
  </style>
</head>
<body class="login-body">
  <div class="login-shell">

  <?php if ($demoEnabled): ?>
  <div class="login-topbar">
    <div class="lang-switch" role="group" aria-label="Language">
      <button type="button" data-lang="ro">RO</button>
      <button type="button" data-lang="en">EN</button>
    </div>
    <a class="github-link" href="https://github.com/BogdanDumitrascu04/flowlogix" target="_blank" rel="noopener">
      <i class='bx bxl-github'></i>
      <span data-i18n="source">Cod sursă</span>
    </a>
  </div>
  <?php endif; ?>

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
            <p data-i18n="tagline">Dashboard operațional logistic</p>
          </div>
        </div>

        <div class="login-brand-content">
          <h2 data-i18n="headline">Platformă logistică pentru control operațional</h2>
          <p data-i18n="intro">
            FlowLogix oferă acces rapid la recepții, comenzi, livrări și rapoarte,
            în funcție de rolul fiecărui utilizator.
          </p>

          <?php if ($demoEnabled): ?>
          <p class="demo-hint" data-i18n="pickRole">Alege un rol pentru a intra în demo:</p>
          <form action="demo_login.php" method="POST" class="login-role-preview">
            <?php foreach ($loginRoles as $roleKey => $role): ?>
            <button type="submit" name="role" value="<?php echo $roleKey; ?>" class="role-preview-card" data-role="<?php echo $roleKey; ?>">
              <div class="role-icon-wrap">
                <i class='bx <?php echo $role['icon']; ?>'></i>
              </div>
              <div class="role-card-text">
                <strong data-i18n="role_<?php echo $roleKey; ?>"><?php echo $role['name']; ?></strong>
                <span data-i18n="desc_<?php echo $roleKey; ?>"><?php echo $role['desc']; ?></span>
              </div>
              <span class="role-card-enter">
                <span data-i18n="enter">Intră</span>
                <i class='bx bx-right-arrow-alt'></i>
              </span>
            </button>
            <?php endforeach; ?>
          </form>
          <?php else: ?>
          <div class="login-role-preview">
            <?php foreach ($loginRoles as $roleKey => $role): ?>
            <div class="role-preview-card" data-role="<?php echo $roleKey; ?>">
              <div class="role-icon-wrap">
                <i class='bx <?php echo $role['icon']; ?>'></i>
              </div>
              <div>
                <strong><?php echo $role['name']; ?></strong>
                <span><?php echo $role['desc']; ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- LOGIN FORM PANEL (RIGHT) -->
    <div class="login-form-section">
      <div class="login-form-card">
        <div class="login-form-header">
          <h2 data-i18n="signInTitle">Autentificare</h2>
          <p data-i18n="signInSubtitle">Conectează-te pentru a accesa dashboard-ul FlowLogix.</p>
        </div>

        <?php if ($demoEnabled): ?>
          <div class="demo-note">
            <i class='bx bx-info-circle'></i>
            <span data-i18n="demoNote">
              Demo live: alege un rol din stânga ca să intri fără cont. Datele se actualizează în fiecare oră
              și se resetează în fiecare noapte.
            </span>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
          <div class="login-alert login-alert-error">
            <i class='bx bx-exclamation-circle'></i>
            <span data-i18n="error">Date invalide. Verifică username-ul și parola.</span>
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
                data-i18n-placeholder="userPlaceholder"
                required
                class="form-input"
              />
            </div>
          </div>

          <div class="form-group">
            <label for="userPassword" data-i18n="password">Parolă</label>
            <div class="input-wrap input-wrap-password">
              <i class='bx bx-lock-alt'></i>
              <input
                type="password"
                id="userPassword"
                name="password"
                placeholder="Introdu parola"
                data-i18n-placeholder="passwordPlaceholder"
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
            <span data-i18n="signInButton">Autentificare</span>
          </button>
        </form>

        <div class="login-helper-info">
          <i class='bx bx-info-circle'></i>
          <p data-i18n="helper">Accesul se face pe baza contului de utilizator, iar rolul este determinat automat.</p>
        </div>
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

    // RO / EN for the login page of the public demo (the app itself stays in Romanian)
    const langSwitch = document.querySelector('.lang-switch');
    if (langSwitch) {
      const english = {
        source: 'Source code',
        tagline: 'Logistics operations dashboard',
        headline: 'A logistics platform for day-to-day operations control',
        intro: 'FlowLogix gives quick access to receptions, orders, shipments and reports, based on each user\'s role.',
        pickRole: 'Pick a role to enter the live demo:',
        role_tura: 'Shift supervisor',
        desc_tura: 'Live monitoring and quick action on blockers',
        role_depozit: 'Warehouse manager',
        desc_depozit: 'Flow coordination and operational reports',
        role_manager: 'Logistics manager',
        desc_manager: 'Overall performance and data-driven decisions',
        role_admin: 'Administrator',
        desc_admin: 'Users and roles (read-only in the demo)',
        enter: 'Enter',
        signInTitle: 'Sign in',
        signInSubtitle: 'Sign in to open the FlowLogix dashboard.',
        demoNote: 'Live demo: pick a role on the left to enter without an account. Data updates every hour and resets every night. The application itself is in Romanian.',
        error: 'Invalid username or password.',
        password: 'Password',
        userPlaceholder: 'Enter username',
        passwordPlaceholder: 'Enter password',
        signInButton: 'Sign in',
        helper: 'Access is based on your user account, and your role is detected automatically.'
      };

      // Keep the Romanian texts from the page, so switching back needs no second dictionary
      const romanian = {};
      document.querySelectorAll('[data-i18n]').forEach(el => {
        romanian[el.dataset.i18n] = el.textContent.trim().replace(/\s+/g, ' ');
      });
      document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        romanian[el.dataset.i18nPlaceholder] = el.placeholder;
      });

      function setLanguage(lang) {
        const texts = lang === 'en' ? english : romanian;
        document.querySelectorAll('[data-i18n]').forEach(el => {
          if (texts[el.dataset.i18n]) el.textContent = texts[el.dataset.i18n];
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
          if (texts[el.dataset.i18nPlaceholder]) el.placeholder = texts[el.dataset.i18nPlaceholder];
        });
        document.documentElement.lang = lang;
        langSwitch.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.lang === lang));
        try { localStorage.setItem('flowlogix_login_lang', lang); } catch (e) {}
      }

      langSwitch.addEventListener('click', e => {
        if (e.target.dataset.lang) setLanguage(e.target.dataset.lang);
      });

      // Saved choice first; otherwise Romanian for Romanian browsers and English for everyone else
      let saved = null;
      try { saved = localStorage.getItem('flowlogix_login_lang'); } catch (e) {}
      const browserIsRomanian = (navigator.language || '').toLowerCase().startsWith('ro');
      setLanguage(saved || (browserIsRomanian ? 'ro' : 'en'));
    }
  </script>
</body>
</html>
