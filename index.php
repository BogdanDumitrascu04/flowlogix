<?php
/*
 * index.php
 * Pagina de start: trimite utilizatorul logat la dashboard-ul rolului său,
 * iar pe cel nelogat la login. Logo-ul din meniu duce tot aici.
 */
session_start();

$homes = [
    'tura'    => 'dashboard-tura.php',
    'depozit' => 'dashboard-depozit.php',
    'manager' => 'dashboard-manager.php',
    'admin'   => 'admin.php',
];

$role = $_SESSION['role'] ?? '';

header("Location: " . ($homes[$role] ?? 'login.php'));
exit;
