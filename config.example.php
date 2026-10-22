<?php

date_default_timezone_set('Europe/Bucharest');

$host = "localhost";
$dbname = "your_database_name";
$username = "your_username";
$password = "your_password";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexiunea a eșuat: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
