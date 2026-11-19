<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

function skiftH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = database();
$token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$otp = trim((string) ($_POST['otp'] ?? ''));
$notice = null;
$student = null;

if ($token !== '') {
    $studentQuery = $pdo->prepare(
        'SELECT elevnummer, navn, email, reset_otp, reset_expires FROM brugere WHERE reset_token = ? LIMIT 1'
    );
    $studentQuery->execute([$token]);
    $student = $studentQuery->fetch();

    if ($student === false || !isset($student['reset_expires']) || strtotime((string) $student['reset_expires']) < time()) {
        $notice = 'Linket er ugyldigt eller er udløbet. Bed administratoren om et nyt link.';
        $student = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
    $otp = trim((string) ($_POST['otp'] ?? ''));
    $password = $_POST['password'] ?? '';
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if ($token === '') {
        $notice = 'Linket mangler. Gå tilbage til den e-mail, du modtog.';
    } else {
        $studentQuery = $pdo->prepare(
            'SELECT elevnummer, navn, email, reset_otp, reset_expires FROM brugere WHERE reset_token = ? LIMIT 1'
        );
        $studentQuery->execute([$token]);
        $student = $studentQuery->fetch();

        if ($student === false || !isset($student['reset_expires']) || strtotime((string) $student['reset_expires']) < time()) {
            $notice = 'Linket er ugyldigt eller er udløbet. Bed administratoren om et nyt link.';
            $student = null;
        } elseif (!is_string($password) || !is_string($passwordConfirmation) || strlen($password) < 10 || $password !== $passwordConfirmation) {
            $notice = 'Adgangskoden skal være mindst 10 tegn, og begge felter skal være ens.';
        } elseif (!preg_match('/^\d{6}$/', $otp) || !hash_equals((string) ($student['reset_otp'] ?? ''), $otp)) {
            $notice = 'Verifikationskoden er forkert. Brug koden fra den e-mail, du fik.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                $notice = 'Adgangskoden kunne ikke gemmes sikkert. Prøv igen.';
            } else {
                $pdo->prepare(
                    'UPDATE brugere SET adgangskode_hash = ?, reset_token = NULL, reset_otp = NULL, reset_expires = NULL WHERE elevnummer = ?'
                )->execute([$hash, $student['elevnummer']]);
                $notice = 'Din adgangskode er nu opdateret. Du kan logge ind på elevportalen.';
                $student = null;
                $token = '';
                $otp = '';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skift adgangskode</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(59,130,246,0.18),transparent_25%),radial-gradient(circle_at_bottom_right,_rgba(168,85,247,0.12),transparent_28%),linear-gradient(135deg,_#eff6ff_0%,_#f8fafc_40%,_#eef2ff_100%)] text-slate-800">
    <main class="mx-auto flex min-h-screen max-w-xl items-center justify-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_30px_80px_rgba(15,23,42,0.12)] backdrop-blur-xl">
            <div class="bg-gradient-to-r from-slate-950 via-blue-950 to-indigo-950 px-8 py-7 text-white">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">IT-Udlån</p>
                <h1 class="mt-2 text-3xl font-black">Skift adgangskode</h1>
            </div>
            <div class="p-8">
                <?php if ($notice !== null): ?>
                    <div class="mb-6 rounded-xl border <?= $student === null && stripos($notice, 'opdateret') !== false ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900' ?> p-4 text-sm leading-6">
                        <?= skiftH($notice) ?>
                    </div>
                <?php endif; ?>

                <?php if ($student !== null): ?>
                    <p class="mb-4 text-sm text-slate-600">Hej <?= skiftH((string) $student['navn']) ?>. Vælg en ny adgangskode til din elevkonto.</p>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="token" value="<?= skiftH($token) ?>">
                        <label class="block text-sm font-semibold text-slate-700">Verifikationskode (6 cifre)
                            <input class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-slate-900" type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Ny adgangskode
                            <input class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-slate-900" type="password" name="password" minlength="10" required>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Gentag adgangskode
                            <input class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-slate-900" type="password" name="password_confirmation" minlength="10" required>
                        </label>
                        <button class="w-full rounded-xl bg-blue-600 px-5 py-3 font-bold text-white hover:bg-blue-700" type="submit">Gem ny adgangskode</button>
                    </form>
                <?php elseif (stripos((string) $notice, 'opdateret') !== false): ?>
                    <div class="flex flex-col gap-3">
                        <a href="elev_login.php" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 font-bold text-white hover:bg-slate-800">Gå til elevportalen</a>
                    </div>
                <?php else: ?>
                    <p class="text-slate-600">Linket er ugyldigt, eller du skal bruge det link, der blev sendt til din registrerede e-mail.</p>
                    <div class="mt-6">
                        <a href="elev_login.php" class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 font-bold text-white hover:bg-blue-700">Tilbage til log ind</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>