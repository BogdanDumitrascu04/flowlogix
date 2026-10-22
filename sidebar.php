<?php
// Nu apelam session_start() aici - e apelat în paginile principale (admin.php, dashboard-uri, etc.)

// Fallback pentru $currentPage dacă nu e definită
if (!isset($currentPage)) {
    $currentPage = '';
}

$displayName = "Utilizator";      // Numele afisat langa "Hello,"
$userName = "Utilizator";         // Nume intern
$userRole = "Vizitator";          // Rol afisat
$roleKey = "default";             // Cheie rol pentru CSS

// Detectează dacă suntem pe pagina admin
$isAdminPage = isset($currentPage) && $currentPage === 'admin';

// ADMIN PAGE: afișare specială
if ($isAdminPage) {
  $displayName = "Admin";
  $userRole = "Admin";
  $roleKey = "admin";
}
// USER NORMAL: citire din sesie, nu din GET
else {
  if (isset($_SESSION['user_name']) && $_SESSION['user_name'] !== '') {
    $userName = htmlspecialchars($_SESSION['user_name']);
    $displayName = $userName;  // Afișează numele utilizatorului
    $roleKey = $_SESSION['user_role'] ?? 'default';
  } elseif (isset($_SESSION['user']) && $_SESSION['user'] !== '') {
    // Fallback: citire din $_SESSION['user']
    $userName = htmlspecialchars($_SESSION['user']);
    $displayName = $userName;
    $roleKey = $_SESSION['role'] ?? 'default';
  }

  // Mapează role la etichete
  if ($roleKey === 'tura') {
    $userRole = 'Șef de tură';
  } elseif ($roleKey === 'depozit') {
    $userRole = 'Șef de depozit';
  } elseif ($roleKey === 'manager') {
    $userRole = 'Manager logistic';
  } elseif ($roleKey === 'admin') {
    $userRole = 'Administrator sistem';
  }
}

function isActive($pageName, $currentPage) {
  return $pageName === $currentPage ? 'active' : '';
}
?>
<nav class="sidebar">
  <div class="sidebar-top">
    <a href="index.php" class="brand">
      <div class="brand-logo">
        <img src="FlowLogix.png" alt="FlowLogix logo">
      </div>
      <div class="brand-text">
        <span class="brand-title">FlowLogix</span>
        <span class="brand-subtitle">Dashboard operațional</span>
      </div>
    </a>

    <div class="sidebar-user-card">
      <div class="sidebar-user-avatar role-<?php echo htmlspecialchars($roleKey); ?>">
        <?php echo strtoupper(mb_substr($displayName, 0, 1)); ?>
      </div>
      <div class="sidebar-user-info">
        <span class="sidebar-user-greeting">Bună, <?php echo $displayName; ?></span>
        <span class="sidebar-user-role role-<?php echo htmlspecialchars($roleKey); ?>">
          <?php echo $userRole; ?>
        </span>
      </div>
    </div>
  </div>

  <?php if ($isAdminPage): ?>
    <!-- SIDEBAR SIMPLIFICAT PENTRU PAGINA ADMIN -->
    <div class="sidebar-section">
      <span class="sidebar-label">Administrare sistem</span>
      <ul class="menu-list">
        <li class="menu-item <?php echo isActive('admin', $currentPage); ?>">
          <a href="admin.php">
            <i class='bx bx-group'></i>
            <span>Utilizatori</span>
          </a>
        </li>
      </ul>
    </div>

  <?php else: ?>
    <!-- SIDEBAR COMPLET PENTRU UTILIZATORI NORMALI -->
    <?php if ($roleKey === 'tura'): ?>
      <!-- MENIU ȘEF DE TURĂ -->
      <div class="sidebar-section">
        <span class="sidebar-label">Operațiuni tură</span>
        <ul class="menu-list">
          <li class="menu-item <?php echo isActive('dashboard-tura', $currentPage); ?>">
            <a href="dashboard-tura.php">
              <i class='bx bx-grid-alt'></i>
              <span>Dashboard tură</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('receptii-active', $currentPage); ?>">
            <a href="receptii-active.php">
              <i class='bx bx-log-in-circle'></i>
              <span>Recepții active</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('picking-packing-tura', $currentPage); ?>">
            <a href="picking-packing-tura.php">
              <i class='bx bx-package'></i>
              <span>Pick/Pack operațional</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('expedieri-urgente', $currentPage); ?>">
            <a href="expedieri-urgente.php">
              <i class='bx bx-log-out-circle'></i>
              <span>Expedieri urgente</span>
            </a>
          </li>
        </ul>
      </div>

      <div class="sidebar-section">
        <span class="sidebar-label">Utilități</span>
        <ul class="menu-list">
          <li class="menu-item <?php echo isActive('setari', $currentPage); ?>">
            <a href="setari.php">
              <i class='bx bx-cog'></i>
              <span>Setări</span>
            </a>
          </li>
        </ul>
      </div>

    <?php elseif ($roleKey === 'depozit'): ?>
      <!-- MENIU ȘEF DE DEPOZIT -->
      <div class="sidebar-section">
        <span class="sidebar-label">Coordonare depozit</span>
        <ul class="menu-list">
          <li class="menu-item <?php echo isActive('dashboard', $currentPage); ?>">
            <a href="dashboard-depozit.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-grid-alt'></i>
              <span>Dashboard depozit</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('inbound', $currentPage); ?>">
            <a href="inbound.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-log-in-circle'></i>
              <span>Inbound</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('pickpack', $currentPage); ?>">
            <a href="pickpack.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-package'></i>
              <span>Pick / Pack</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('outbound', $currentPage); ?>">
            <a href="outbound.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-log-out-circle'></i>
              <span>Outbound</span>
            </a>
          </li>
        </ul>
      </div>

      <div class="sidebar-section">
        <span class="sidebar-label">Administrare</span>
        <ul class="menu-list">
          <li class="menu-item <?php echo isActive('rapoarte', $currentPage); ?>">
            <a href="rapoarte.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-file'></i>
              <span>Rapoarte</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('setari', $currentPage); ?>">
            <a href="setari.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-cog'></i>
              <span>Setări</span>
            </a>
          </li>
        </ul>
      </div>

    <?php elseif ($roleKey === 'manager'): ?>
      <!-- MENIU MANAGER LOGISTIC -->
      <div class="sidebar-section">
        <span class="sidebar-label">Operațiuni</span>
        <ul class="menu-list">
          <li class="menu-item <?php echo isActive('dashboard', $currentPage); ?>">
            <a href="dashboard-manager.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-line-chart'></i>
              <span>Dashboard manager</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('rapoarte', $currentPage); ?>">
            <a href="rapoarte.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-bar-chart-alt-2'></i>
              <span>Rapoarte manageriale</span>
            </a>
          </li>
        </ul>
      </div>

      <div class="sidebar-section">
        <span class="sidebar-label">Analiză și control</span>
        <ul class="menu-list">
          <li class="menu-item">
            <a href="dashboard-manager.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>#performanta-fluxuri">
              <i class='bx bx-pie-chart-alt-2'></i>
              <span>Performanță pe fluxuri</span>
            </a>
          </li>

          <li class="menu-item">
            <a href="dashboard-manager.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>#situatii-intarziate">
              <i class='bx bx-time-five'></i>
              <span>Situații întârziate</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('setari', $currentPage); ?>">
            <a href="setari.php?user=<?php echo urlencode($userName); ?>&role=<?php echo urlencode($roleKey); ?>">
              <i class='bx bx-cog'></i>
              <span>Setări</span>
            </a>
          </li>
        </ul>
      </div>

    <?php else: ?>
      <!-- MENIU DEFAULT -->
      <div class="sidebar-section">
        <span class="sidebar-label">Navigare principală</span>
        <ul class="menu-list">
          <li class="menu-item <?php echo isActive('dashboard', $currentPage); ?>">
            <a href="index.php">
              <i class='bx bx-grid-alt'></i>
              <span>Dashboard</span>
            </a>
          </li>

          <li class="menu-item <?php echo isActive('setari', $currentPage); ?>">
            <a href="setari.php">
              <i class='bx bx-cog'></i>
              <span>Setări</span>
            </a>
          </li>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Secțiunea "Administrare sistem" DOAR pentru admin -->
    <?php if ($roleKey === 'admin'): ?>
    <div class="sidebar-section">
      <span class="sidebar-label">Administrare sistem</span>
      <ul class="menu-list">
        <li class="menu-item <?php echo isActive('admin', $currentPage); ?>">
          <a href="admin.php">
            <i class='bx bx-group'></i>
            <span>Utilizatori</span>
          </a>
        </li>
      </ul>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="sidebar-section sidebar-section-footer">
    <span class="sidebar-label">Sesiune</span>
    <ul class="menu-list">
      <li class="menu-item menu-item-danger">
        <a href="login.php" id="logoutBtn">
          <i class='bx bx-log-out'></i>
          <span>Deconectare</span>
        </a>
      </li>
    </ul>
  </div>

</nav>