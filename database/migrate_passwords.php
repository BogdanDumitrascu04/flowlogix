<?php
/*
 * migrate_passwords.php
 * Migrare unică: înlocuiește parolele salvate în clar cu hash-uri bcrypt.
 * Se rulează din linia de comandă, din folderul proiectului:
 *   php database/migrate_passwords.php
 * Poate fi rulat de mai multe ori: parolele care sunt deja hash-uri sunt sărite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acest script se rulează doar din linia de comandă.\n");
}

require_once __DIR__ . '/../config.php';

$result = $conn->query("SELECT id, password FROM users");
$update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");

$migrated = 0;
$skipped  = 0;

while ($user = $result->fetch_assoc()) {
    // password_get_info recunoaște un hash creat cu password_hash; pentru text simplu întoarce 'unknown'
    if (password_get_info($user['password'])['algoName'] !== 'unknown') {
        $skipped++;
        continue;
    }

    $passwordHash = password_hash($user['password'], PASSWORD_DEFAULT);
    $id = (int)$user['id'];
    $update->bind_param("si", $passwordHash, $id);
    $update->execute();
    $migrated++;
}

echo "Parole migrate: {$migrated}. Deja hash-uite (sărite): {$skipped}.\n";
