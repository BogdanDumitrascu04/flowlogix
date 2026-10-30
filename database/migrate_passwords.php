<?php
/*
 * One-off migration: replaces plain-text passwords with bcrypt hashes.
 *   php database/migrate_passwords.php
 * Safe to run again: passwords that are already hashed are skipped.
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
    // password_get_info() returns 'unknown' for anything that is not a password_hash() hash
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
