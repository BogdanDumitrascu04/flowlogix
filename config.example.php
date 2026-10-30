<?php

date_default_timezone_set('Europe/Bucharest');

// Copy to config.php. The defaults match a fresh WampServer install.
$host = "localhost";
$dbname = "flowlogix";
$username = "root";
$password = "";

// Public demo server only: enables one-click role login and makes user management read-only.
$demoMode = false;

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexiunea a eșuat: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
