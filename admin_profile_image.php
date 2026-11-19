<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    exit;
}

$username = $_SESSION['admin_username'] ?? '';
if (!is_string($username) || $username === '') {
    http_response_code(401);
    exit;
}

try {
    $pdo = database();
    ensureAdminProfileTable($pdo);
    $statement = $pdo->prepare(
        'SELECT avatar_gif FROM admin_profiles WHERE username_key = ?'
    );
    $statement->execute([strtolower($username)]);
    $gifData = $statement->fetchColumn();
} catch (PDOException $exception) {
    error_log('Adminprofilens GIF kunne ikke hentes: ' . $exception->getMessage());
    http_response_code(500);
    exit;
}

if (is_resource($gifData)) {
    $gifData = stream_get_contents($gifData);
}
if (!is_string($gifData) || $gifData === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: image/gif');
header('Content-Length: ' . strlen($gifData));
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $gifData;
