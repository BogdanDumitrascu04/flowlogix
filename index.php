<?php
// Entry point and logo link: logged-in users go to their role's dashboard, everyone else to the login page.
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
