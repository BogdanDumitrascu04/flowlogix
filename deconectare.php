<?php
session_start();

// Distruge sesiunea
session_destroy();

// Redirecționează la login
header("Location: login.php");
exit;