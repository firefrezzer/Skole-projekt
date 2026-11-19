<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/database.php';

$studentNumber = $_SESSION['elev_elevnummer'] ?? null;
if (!is_string($studentNumber) || $studentNumber === '') {
    http_response_code(401);
    exit;
}

try {
    $pdo = database();
    ensureStudentProfileTable($pdo);
    $statement = $pdo->prepare(
        'SELECT avatar_gif FROM elev_profiler WHERE elevnummer = ?'
    );
    $statement->execute([$studentNumber]);
    $gifData = $statement->fetchColumn();
} catch (PDOException $exception) {
    error_log('Elevprofilens GIF kunne ikke hentes: ' . $exception->getMessage());
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
