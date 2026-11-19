<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/php_config.php';

function adminChangePasswordFlash(string $category, string $message): void
{
    $_SESSION['admin_change_password_flash'] = ['category' => $category, 'message' => $message];
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php?redirect=' . rawurlencode('admin_skift_adgangskode.php'));
    exit;
}

$currentUsername = $_SESSION['admin_username'] ?? null;
if (!is_string($currentUsername) || trim($currentUsername) === '') {
    unset($_SESSION['admin_logged_in'], $_SESSION['admin_username']);
    session_regenerate_id(true);
    header('Location: admin_login.php?redirect=' . rawurlencode('admin_skift_adgangskode.php'));
    exit;
}
$currentUsername = trim($currentUsername);
if (!isset($_SESSION['admin_password_csrf_token'])) {
    $_SESSION['admin_password_csrf_token'] = bin2hex(random_bytes(32));
}
$notice = $_SESSION['admin_change_password_flash'] ?? null;
unset($_SESSION['admin_change_password_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) ||
        !hash_equals((string) $_SESSION['admin_password_csrf_token'], $csrfToken)) {
        http_response_code(400);
        exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $repeatPassword = $_POST['new_password_confirmation'] ?? '';

    if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($repeatPassword)) {
        adminChangePasswordFlash('error', 'Formularen er ugyldig. Prøv igen.');
        header('Location: admin_skift_adgangskode.php');
        exit;
    }

    $storedHash = adminPasswordHashFor($currentUsername);
    if ($storedHash === null || !password_verify($currentPassword, $storedHash)) {
        adminChangePasswordFlash('error', 'Den nuværende adgangskode er forkert.');
        header('Location: admin_skift_adgangskode.php');
        exit;
    }

    if (strlen($newPassword) < 6 || $newPassword !== $repeatPassword) {
        adminChangePasswordFlash('error', 'Den nye adgangskode skal være mindst 6 tegn, og begge felter skal være ens.');
        header('Location: admin_skift_adgangskode.php');
        exit;
    }

    try {
        saveAdminPassword($currentUsername, $newPassword);
        adminChangePasswordFlash('success', 'Din admin-adgangskode er nu ændret.');
        header('Location: admin_skift_adgangskode.php');
        exit;
    } catch (Throwable $exception) {
        adminChangePasswordFlash('error', $exception->getMessage());
        header('Location: admin_skift_adgangskode.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skift admin adgangskode</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(59,130,246,0.18),transparent_25%),radial-gradient(circle_at_bottom_right,_rgba(168,85,247,0.12),transparent_28%),linear-gradient(135deg,_#eff6ff_0%,_#f8fafc_40%,_#eef2ff_100%)] text-slate-800">
    <main class="mx-auto flex min-h-screen max-w-xl items-center justify-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-[30px] border border-white/80 bg-white/85 shadow-[0_30px_80px_rgba(15,23,42,0.12)] backdrop-blur-xl">
            <div class="bg-gradient-to-r from-slate-950 via-blue-950 to-indigo-950 px-8 py-7 text-white">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">IT-Udlån Pro</p>
                <h1 class="mt-2 text-3xl font-black">Skift admin-adgangskode</h1>
            </div>
            <div class="p-8">
                <?php if ($notice !== null): ?>
                    <div class="mb-6 rounded-xl border p-4 text-sm <?= $notice['category'] === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-rose-300 bg-rose-50 text-rose-800' ?>">
                        <?= htmlspecialchars($notice['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['admin_password_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <label class="block text-sm font-semibold text-slate-700">
                        Nuværende adgangskode
                        <input type="password" name="current_password" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-slate-900" autocomplete="current-password">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Ny adgangskode
                        <input type="password" name="new_password" minlength="6" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-slate-900" autocomplete="new-password">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Gentag ny adgangskode
                        <input type="password" name="new_password_confirmation" minlength="6" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-slate-900" autocomplete="new-password">
                    </label>

                    <button class="w-full rounded-xl bg-blue-600 px-5 py-3 font-bold text-white hover:bg-blue-700" type="submit">
                        Gem ny adgangskode
                    </button>
                </form>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="index.php" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 font-bold text-white hover:bg-slate-800">Tilbage til dashboard</a>
                    <form method="post" action="admin.php">
                        <input type="hidden" name="action" value="logout">
                        <button class="rounded-xl border border-slate-200 bg-white px-5 py-3 font-bold text-slate-700 hover:bg-slate-50" type="submit">Log ud</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
