<?php
/*
 * demo_login.php
 * Intrare cu un click pentru vizitatorii demo-ului public: alegi un rol și ajungi
 * direct în dashboard-ul lui, fără parolă.
 * Funcționează doar dacă în config.php e setat $demoMode = true (doar pe serverul live).
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

// Cardurile de rol sunt un formular: fără tokenul de pe login.php, cererea nu vine de acolo
if (!csrf_valid()) {
    header("Location: login.php?error=expired");
    exit;
}

// Sesiune nouă, ca un vizitator să nu preia o sesiune veche
session_regenerate_id(true);

// În datele demo, fiecare cont poartă numele rolului (admin, manager, depozit, tura)
$_SESSION['user'] = $role;
$_SESSION['role'] = $role;

header("Location: " . $demoHomes[$role]);
exit;
