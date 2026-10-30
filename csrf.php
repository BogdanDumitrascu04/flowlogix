<?php
/*
 * csrf.php
 * Protecție CSRF pentru formularele POST.
 * Fiecare sesiune primește un token aleator, pus ca un câmp ascuns în formulare.
 * Un alt site poate trimite formularul în numele utilizatorului, dar nu poate citi
 * tokenul, așa că cererea lui e respinsă. Necesită session_start() înainte.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Câmpul ascuns care se pune în fiecare <form method="POST">
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

// hash_equals compară în timp constant, ca durata comparației să nu dezvăluie tokenul
function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}
