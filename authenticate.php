<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

// Brute-force protection: an IP with 5 failed logins in 15 minutes is refused.
$maxFailedLogins = 5;
$failedLoginWindowMinutes = 15;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

// No valid token: the login page expired or the form was posted from another site.
if (!csrf_valid()) {
    header("Location: login.php?error=expired");
    exit;
}

$name = trim($_POST["name"] ?? "");
$password = trim($_POST["password"] ?? "");

if ($name === "" || $password === "") {
    header("Location: login.php?error=1");
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$sql = "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL ? MINUTE";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Eroare SQL: " . $conn->error);
}

$stmt->bind_param("si", $ip, $failedLoginWindowMinutes);
$stmt->execute();
$failedCount = (int) $stmt->get_result()->fetch_row()[0];

// Checked before password_verify(), so a locked-out IP gets no answer about the password.
if ($failedCount >= $maxFailedLogins) {
    header("Location: login.php?error=locked");
    exit;
}

$sql = "SELECT id, role, password FROM users WHERE name = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Eroare SQL: " . $conn->error);
}

$stmt->bind_param("s", $name);
$stmt->execute();
$result = $stmt->get_result();
$user = ($result->num_rows === 1) ? $result->fetch_assoc() : null;

if ($user && password_verify($password, $user['password'])) {
    $role = $user['role'];

    // Prevents session fixation.
    session_regenerate_id(true);

    $_SESSION['user'] = $name;
    $_SESSION['role'] = $role;

    $clear = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $clear->bind_param("s", $ip);
    $clear->execute();

    if ($role === "tura") {
        header("Location: dashboard-tura.php");
    } elseif ($role === "depozit") {
        header("Location: dashboard-depozit.php");
    } elseif ($role === "manager") {
        header("Location: dashboard-manager.php");
    } elseif ($role === "admin") {
        header("Location: admin.php");
    } else {
        header("Location: login.php?error=1");
    }
    exit;
} else {
    // Old rows are pruned here, so the table needs no separate cleanup job.
    $log = $conn->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())");
    $log->bind_param("s", $ip);
    $log->execute();
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");

    header("Location: login.php?error=1");
    exit;
}
