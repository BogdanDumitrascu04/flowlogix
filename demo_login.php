<?php
/*
 * One-click role login for visitors of the public demo, without a password.
 * Disabled unless config.php sets $demoMode = true (live server only).
 */
session_start();
require_once 'config.php';
require_once 'csrf.php';

$demoHomes = [
    'manager' => 'dashboard-manager.php',
    'depozit' => 'dashboard-depozit.php',
    'tura'    => 'dashboard-tura.php',
    'admin'   => 'admin.php',
];

$role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

if (empty($demoMode) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($demoHomes[$role])) {
    header("Location: login.php");
    exit;
}

if (!csrf_valid()) {
    header("Location: login.php?error=expired");
    exit;
}

// Prevents session fixation.
session_regenerate_id(true);

// Demo accounts are named after their role.
$_SESSION['user'] = $role;
$_SESSION['role'] = $role;

header("Location: " . $demoHomes[$role]);
exit;
