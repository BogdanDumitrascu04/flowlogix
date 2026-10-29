<?php

date_default_timezone_set('Europe/Bucharest');

// Copiază fișierul ca config.php și completează datele bazei de date.
// Valorile de mai jos sunt cele implicite într-o instalare nouă de WampServer.
$host = "localhost";
$dbname = "flowlogix";
$username = "root";
$password = "";

// true doar pe serverul demo public: pagina de login arată intrarea cu un click pe rol,
// iar utilizatorii din pagina de administrare nu pot fi modificați
$demoMode = false;

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexiunea a eșuat: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
