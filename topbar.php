<?php
$userName = "Utilizator";
$roleKey = "default";

// Utilizează valorile din sesiune, nu din GET
if (isset($_SESSION['user']) && $_SESSION['user'] !== '') {
  $userName = htmlspecialchars($_SESSION['user']);
}

if (isset($_SESSION['role']) && $_SESSION['role'] !== '') {
  $roleKey = $_SESSION['role'];
}

if (!isset($period)) {
  $period = '30d';
}

$pageTitle = "Dashboard";
$pageSubtitle = "Monitorizare operațională.";
$topbarActions = "";

if ($roleKey === 'tura') {
  $pageTitle = "Dashboard operațional tură";
  $pageSubtitle = "Monitorizare în timp real a activității din schimbul curent și a excepțiilor operaționale.";

  $topbarActions = '
    <a href="raport-tura.php?period=' . urlencode($period) . '" target="_blank" class="control-button primary">
      <i class="bx bx-file"></i>
      <span>Raport PDF</span>
    </a>
  ';
}

if ($roleKey === 'depozit') {
  $pageTitle = "Dashboard coordonare depozit";
  $pageSubtitle = "Privire de ansamblu asupra fluxurilor logistice, capacității operaționale și performanței generale a depozitului.";

  $topbarActions = '
    <select class="control-select" id="periodSelect" onchange="changePeriod(this.value)">
      <option value="today" ' . (($period === 'today') ? 'selected' : '') . '>Astăzi</option>
      <option value="7d" ' . (($period === '7d') ? 'selected' : '') . '>Ultimele 7 zile</option>
      <option value="30d" ' . (($period === '30d') ? 'selected' : '') . '>Ultimele 30 zile</option>
      <option value="month" ' . (($period === 'month') ? 'selected' : '') . '>Luna curentă</option>
    </select>

    <a href="raport-depozit.php?period=' . urlencode($period) . '" target="_blank" class="control-button primary">
      <i class="bx bx-file"></i>
      <span>Raport PDF</span>
    </a>
  ';
}

if ($roleKey === 'manager') {
  $pageTitle = "Dashboard manager logistic";
  $pageSubtitle = "Privire consolidată asupra operațiunilor pe toate fluxurile, cu accent pe gradul de respectare a termenelor și pe întârzierile înregistrate.";

  $topbarActions = '
    <select class="control-select" id="periodSelect" onchange="changePeriod(this.value)">
      <option value="today" ' . (($period === 'today') ? 'selected' : '') . '>Astăzi</option>
      <option value="7d" ' . (($period === '7d') ? 'selected' : '') . '>Ultimele 7 zile</option>
      <option value="30d" ' . (($period === '30d') ? 'selected' : '') . '>Ultimele 30 zile</option>
      <option value="month" ' . (($period === 'month') ? 'selected' : '') . '>Luna curentă</option>
    </select>

    <a href="raport-manager.php?period=' . urlencode($period) . '" target="_blank" class="control-button primary">
      <i class="bx bx-file"></i>
      <span>Raport PDF</span>
    </a>
  ';
}
?>

<div class="topbar role-topbar role-<?php echo htmlspecialchars($roleKey); ?>">
  <div class="topbar-left">
    <h1><?php echo $pageTitle; ?></h1>
    <p><?php echo $pageSubtitle; ?></p>
  </div>

  <div class="topbar-right">
    <?php echo $topbarActions; ?>
  </div>
</div>