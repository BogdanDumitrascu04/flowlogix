<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

// Limita de încercări greșite: după 5 de pe același IP în 15 minute, login-ul e refuzat
$maxFailedLogins = 5;
$failedLoginWindowMinutes = 15;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

// Formularul trebuie să poarte tokenul primit pe login.php; altfel a expirat sau vine de pe alt site
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

// Câte încercări greșite au venit de pe acest IP în ultimele 15 minute
$sql = "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL ? MINUTE";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Eroare SQL: " . $conn->error);
}

$stmt->bind_param("si", $ip, $failedLoginWindowMinutes);
$stmt->execute();
$failedCount = (int) $stmt->get_result()->fetch_row()[0];

// Peste limită parola nici nu se mai verifică, deci ghicirea ei prin încercări repetate se oprește aici
if ($failedCount >= $maxFailedLogins) {
    header("Location: login.php?error=locked");
    exit;
}

// Caută utilizatorul doar după nume; parola se verifică în PHP, nu în SQL
$sql = "SELECT id, role, password FROM users WHERE name = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Eroare SQL: " . $conn->error);
}

$stmt->bind_param("s", $name);
$stmt->execute();
$result = $stmt->get_result();
$user = ($result->num_rows === 1) ? $result->fetch_assoc() : null;

// password_verify recalculează hash-ul parolei introduse cu salt-ul salvat în hash
if ($user && password_verify($password, $user['password'])) {
    $role = $user['role'];

    // ID nou de sesiune la login: un ID aflat sau impus de altcineva înainte (session fixation) nu mai e valabil
    session_regenerate_id(true);

    // Salvează în sesiune
    $_SESSION['user'] = $name;
    $_SESSION['role'] = $role;

    // După un login reușit, încercările greșite de pe acest IP nu mai contează
    $clear = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $clear->bind_param("s", $ip);
    $clear->execute();

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
    // Se notează încercarea greșită; rândurile mai vechi de o zi se șterg, ca tabelul să rămână mic
    $log = $conn->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())");
    $log->bind_param("s", $ip);
    $log->execute();
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");

    header("Location: login.php?error=1");
    exit;
}
