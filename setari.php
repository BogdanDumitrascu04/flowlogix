<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$currentPage = 'setari';

$roles = [
  'default' => [
    'title' => 'Setări',
    'subtitle' => 'Personalizare interfeței și a temei vizuale.',
    'label' => 'Utilizator',
    'icon' => 'bx-user'
  ],
  'tura' => [
    'title' => 'Setări tură',
    'subtitle' => 'Preferințe rapide pentru vizualizarea operațională.',
    'label' => 'Șef de tură',
    'icon' => 'bx-time'
  ],
  'depozit' => [
    'title' => 'Setări depozit',
    'subtitle' => 'Configurări pentru monitorizarea fluxurilor din depozit.',
    'label' => 'Șef de depozit',
    'icon' => 'bx-store'
  ],
  'manager' => [
    'title' => 'Setări manager',
    'subtitle' => 'Preferințe pentru vizualizări sintetice și rapoarte.',
    'label' => 'Manager logistic',
    'icon' => 'bx-briefcase'
  ]
];

// The role always comes from the session, never from the query string.
$roleKey = isset($_SESSION['role']) && isset($roles[$_SESSION['role']]) ? $_SESSION['role'] : 'default';
$role = $roles[$roleKey];

$userName = isset($_SESSION['user']) ? $_SESSION['user'] : 'Admin User';

$themeNames = [
  'theme-flowlogix-classic' => 'FlowLogix Classic',
  'theme-flowlogix-ocean' => 'FlowLogix Ocean',
  'theme-flowlogix-orange' => 'FlowLogix Orange',
  'theme-flowlogix-dark' => 'FlowLogix Dark',
  'theme-flowlogix-ice' => 'FlowLogix Minimal'
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>FlowLogix | Setări</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/sidebar.css?v=14">
  <link rel="stylesheet" href="css/styles.css?v=14">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />

  <script src="js/app.js?v=<?php echo time(); ?>" defer></script>
</head>
<body data-page="setari">
  <?php include 'sidebar.php'; ?>

  <main class="main">
    <div class="page-shell">
      <div class="breadcrumb">
        <span>FlowLogix</span>
        <i class='bx bx-chevron-right'></i>
        <strong>Setări</strong>
      </div>

      <div class="topbar">
        <div class="topbar-left">
          <h1><?php echo $role['title']; ?></h1>
          <p><?php echo $role['subtitle']; ?></p>
        </div>
      </div>

      <div class="settings-grid">
        <div class="settings-column">
          <div class="card card-theme">
            <div class="card-header">
              <div class="card-title-wrap">
                <h2>
                  <i class='bx bx-palette'></i>
                  Temă interfață
                </h2>
                <p>Selectează tema vizuală care ți se potrivește cel mai bine. Schimbarea se aplică instant.</p>
              </div>
            </div>

            <div class="theme-preview-grid">
              <div class="theme-preview-card" data-theme-card="theme-flowlogix-classic">
                <div class="theme-swatch swatch-classic"></div>
                <strong>Classic</strong>
                <p>Albastru & Portocaliu</p>
                <span class="theme-badge" style="display:none;">Activă</span>
              </div>

              <div class="theme-preview-card" data-theme-card="theme-flowlogix-ocean">
                <div class="theme-swatch swatch-ocean"></div>
                <strong>Ocean</strong>
                <p>Cyan Modern</p>
                <span class="theme-badge" style="display:none;">Activă</span>
              </div>

              <div class="theme-preview-card" data-theme-card="theme-flowlogix-orange">
                <div class="theme-swatch swatch-orange"></div>
                <strong>Orange</strong>
                <p>Energie maximă</p>
                <span class="theme-badge" style="display:none;">Activă</span>
              </div>

              <div class="theme-preview-card" data-theme-card="theme-flowlogix-dark">
                <div class="theme-swatch swatch-dark"></div>
                <strong>Dark</strong>
                <p>Mod noapte</p>
                <span class="theme-badge" style="display:none;">Activă</span>
              </div>

              <div class="theme-preview-card" data-theme-card="theme-flowlogix-ice">
                <div class="theme-swatch swatch-ice"></div>
                <strong>Minimal</strong>
                <p>Sidebar luminos · clean</p>
                <span class="theme-badge" style="display:none;">Activă</span>
              </div>
            </div>

            <div class="settings-note">
              <i class='bx bx-info-circle'></i>
              <p>Tema selectată este salvată în browser și va persista la următoarele accesări.</p>
            </div>
          </div>
        </div>

        <div class="settings-column">
          <div class="card card-profile">
            <div class="card-header">
              <div class="card-title-wrap">
                <h2>
                  <i class='bx bx-user-circle'></i>
                  Profil curent
                </h2>
                <p>Informații despre sesiunea ta.</p>
              </div>
            </div>

            <div class="profile-info">
              <div class="profile-item">
                <div class="profile-item-label">
                  <i class='bx bx-user'></i>
                  Utilizator
                </div>
                <div class="profile-item-value">
                  <?php echo htmlspecialchars($userName); ?>
                </div>
              </div>

              <div class="profile-item">
                <div class="profile-item-label">
                  <i class='bx bx-shield-alt'></i>
                  Rol activ
                </div>
                <div class="profile-item-value">
                  <?php echo $role['label']; ?>
                </div>
              </div>

              <div class="profile-item">
                <div class="profile-item-label">
                  <i class='bx bx-palette'></i>
                  Tema selectată
                </div>
                <div class="profile-item-value" id="activeThemeLabel">
                  FlowLogix Classic
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const themeCards = document.querySelectorAll('[data-theme-card]');
      const savedTheme = localStorage.getItem('flowlogix_theme') || 'theme-flowlogix-classic';

      function updateThemeLabel(themeName) {
        const labelEl = document.getElementById('activeThemeLabel');
        if (labelEl) {
          const themeNameMap = {
            'theme-flowlogix-classic': 'FlowLogix Classic',
            'theme-flowlogix-ocean': 'FlowLogix Ocean',
            'theme-flowlogix-orange': 'FlowLogix Orange',
            'theme-flowlogix-dark': 'FlowLogix Dark',
            'theme-flowlogix-ice': 'FlowLogix Minimal'
          };
          labelEl.textContent = themeNameMap[themeName] || 'FlowLogix Classic';
        }
      }

      themeCards.forEach(card => {
        if (card.dataset.themeCard === savedTheme) {
          card.classList.add('active-theme');
          card.querySelector('.theme-badge').style.display = 'block';
        }
        updateThemeLabel(savedTheme);
      });

      themeCards.forEach(card => {
        card.addEventListener('click', () => {
          const themeName = card.dataset.themeCard;
          
          if (typeof changeTheme === 'function') {
            changeTheme(themeName);
          }

          themeCards.forEach(c => {
            c.classList.remove('active-theme');
            c.querySelector('.theme-badge').style.display = 'none';
          });
          card.classList.add('active-theme');
          card.querySelector('.theme-badge').style.display = 'block';

          updateThemeLabel(themeName);
        });
      });
    });
  </script>
</body>
</html>
