<?php
session_start();
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

$name = trim($_POST["name"] ?? "");
$password = trim($_POST["password"] ?? "");

if ($name === "" || $password === "") {
    header("Location: login.php?error=1");
    exit;
}

// Verifică utilizatorul și recuperează rolul din DB
$sql = "SELECT id, role FROM users WHERE name = ? AND password = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Eroare SQL: " . $conn->error);
}

$stmt->bind_param("ss", $name, $password);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    $role = $user['role'];
    
    // Salvează în sesiune
    $_SESSION['user'] = $name;
    $_SESSION['role'] = $role;
    
    // Redirecționare bazată pe rolul din DB
    if ($role === "tura") {
        header("Location: dashboard-tura.php?user=" . urlencode($name) . "&role=" . urlencode($role));
    } elseif ($role === "depozit") {
        header("Location: dashboard-depozit.php?user=" . urlencode($name) . "&role=" . urlencode($role));
    } elseif ($role === "manager") {
        header("Location: dashboard-manager.php?user=" . urlencode($name) . "&role=" . urlencode($role));
    } elseif ($role === "admin") {
        header("Location: admin.php");
    } else {
        header("Location: login.php?error=1");
    }
    exit;
} else {
    header("Location: login.php?error=1");
    exit;
}