<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/php_config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';

function adminFlash(string $category, string $message): void
{
    $_SESSION['admin_flashes'][] = ['category' => $category, 'message' => $message];
}

function adminCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function adminRedirectTarget(mixed $target): string
{
    if (!is_string($target) ||
        !preg_match('~^/?(?:index\.php(?:\?view=[a-z_]+)?|admin_skift_adgangskode\.php|admin_support\.php)$~', $target)) {
        return 'index.php';
    }

    return ltrim($target, '/');
}

function adminIssueOtp(string $username, string $recipient, string $redirect): string
{
    $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['pending_admin_login'] = [
        'username' => $username,
        'email' => $recipient,
        'otp' => $otp,
        'expires_at' => time() + 300,
        'redirect' => $redirect,
    ];
    return $otp;
}

function adminRedirect(string $target = 'index.php'): never
{
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!is_string($action)) {
        http_response_code(400);
        exit('Ugyldig handling.');
    }
    if (in_array($action, ['login', 'verify_login_otp'], true)) {
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !hash_equals(adminCsrfToken(), $submittedToken)) {
            http_response_code(400);
            exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
        }
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        adminFlash('success', 'Du er logget ud.');
        adminRedirect('admin_login.php');
    }

    if ($action === 'request_admin_access') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !hash_equals(adminCsrfToken(), $submittedToken)) {
            http_response_code(400);
            exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
        }

        $newUsernameInput = $_POST['new_username'] ?? '';
        $newEmailInput = $_POST['new_email'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $passwordConfirmation = $_POST['password_confirmation'] ?? '';
        if (!is_string($newUsernameInput) || !is_string($newEmailInput)) {
            adminFlash('error', 'Brugernavn og e-mailadresse er ugyldige.');
            adminRedirect('admin_signup.php');
        }
        $newUsername = trim($newUsernameInput);
        $newEmail = trim($newEmailInput);
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $newUsername)) {
            adminFlash('error', 'Brugernavnet skal være 3-32 tegn og må kun indeholde bogstaver, tal, punktum, bindestreg eller underscore.');
            adminRedirect('admin_signup.php');
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            adminFlash('error', 'Indtast en gyldig e-mailadresse.');
            adminRedirect('admin_signup.php');
        }
        if (!is_string($newPassword) || !is_string($passwordConfirmation) ||
            strlen($newPassword) < 10 || $newPassword !== $passwordConfirmation) {
            adminFlash('error', 'Adgangskoden skal være mindst 10 tegn, og felterne skal være ens.');
            adminRedirect('admin_signup.php');
        }

        foreach (adminCredentialStore() as $account) {
            if (!is_array($account)) {
                continue;
            }
            if (isset($account['username']) && is_string($account['username']) &&
                strcasecmp($newUsername, $account['username']) === 0) {
                adminFlash('error', 'Brugernavnet er allerede i brug.');
                adminRedirect('admin_signup.php');
            }
            if (isset($account['email']) && is_string($account['email']) &&
                filter_var($account['email'], FILTER_VALIDATE_EMAIL) &&
                strcasecmp($newEmail, $account['email']) === 0) {
                adminFlash('error', 'E-mailadressen er allerede knyttet til en admin-konto.');
                adminRedirect('admin_signup.php');
            }
        }

        try {
            $pdo = database();
            ensureAdminRegistrationTable($pdo);
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Adgangskoden kunne ikke gemmes sikkert.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO admin_registrations (username, username_key, email, password_hash)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$newUsername, strtolower($newUsername), $newEmail, $passwordHash]);

            // Gem admin i brugere-tabellen i databasen (automatisk)
            try {
                $pdo->prepare(
                    'INSERT INTO brugere 
                        (elevnummer, navn, email, stamklasse, rolle, adgangskode_hash, blacklisted)
                     VALUES 
                        (?, ?, ?, ?, ?, ?, 0)
                     ON DUPLICATE KEY UPDATE 
                        navn = VALUES(navn),
                        email = VALUES(email),
                        rolle = VALUES(rolle),
                        adgangskode_hash = VALUES(adgangskode_hash)'
                )->execute([
                    $newUsername,
                    $newUsername,
                    $newEmail,
                    'ADMIN',
                    'admin',
                    $passwordHash
                ]);
            } catch (Throwable $brugereException) {
                error_log('Kunne ikke gemme admin i brugere-tabellen: ' . $brugereException->getMessage());
            }

            try {
                sendAdminApplicationStatusEmail($newEmail, $newUsername, 'received');
                adminFlash('success', 'Din ansøgning er sendt, og en kvittering er sendt til ' . $newEmail . '. Nikolaj skal godkende den, før du kan logge ind.');
            } catch (Throwable $mailException) {
                error_log('Kvittering for adminansøgning kunne ikke sendes: ' . $mailException->getMessage());
                adminFlash('error', 'Din ansøgning er gemt, men kvitteringsmailen kunne ikke sendes. Kontakt administratoren.');
            }
        } catch (PDOException $exception) {
            error_log('Adminansøgning kunne ikke gemmes: ' . $exception->getMessage());
            adminFlash(
                'error',
                (string) $exception->getCode() === '23000'
                    ? 'Der findes allerede en ansøgning med dette brugernavn eller denne e-mail.'
                    : 'Adminansøgningen kunne ikke gemmes. Kontrollér databaseforbindelsen.'
            );
        } catch (RuntimeException $exception) {
            adminFlash('error', $exception->getMessage());
        }
        adminRedirect('admin_signup.php');
    }

    if ($action === 'slet_admin') {
        // Kun Nikolaj må slette admins
        if (!isset($_SESSION['admin_username']) || $_SESSION['admin_username'] !== NIKOLAJ_ADMIN_USERNAME) {
            adminFlash('error', 'Kun Nikolaj kan slette administratorer.');
            adminRedirect('index.php');
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !hash_equals(adminCsrfToken(), $submittedToken)) {
            http_response_code(400);
            exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
        }

        $sletElevnummer = trim((string) ($_POST['slet_elevnummer'] ?? ''));
        if ($sletElevnummer === '') {
            adminFlash('error', 'Vælg en administrator, der skal slettes.');
            adminRedirect('index.php');
        }

        // Må ikke slette sig selv
        if (strcasecmp($sletElevnummer, $_SESSION['admin_username']) === 0) {
            adminFlash('error', 'Du kan ikke slette dig selv.');
            adminRedirect('index.php');
        }

        try {
            $pdo = database();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'DELETE FROM brugere 
                 WHERE elevnummer = ? AND rolle = \'admin\''
            );
            $statement->execute([$sletElevnummer]);

            // Slet relaterede poster fra admin_registrations-tabellen
            // Brug strtolower fordi username_key gemmes med lowercase ved oprettelse
            $adminStatement = $pdo->prepare(
                'DELETE FROM admin_registrations WHERE LOWER(username_key) = ?'
            );
            $adminStatement->execute([strtolower($sletElevnummer)]);

            // Slet relaterede poster fra admin_profiles-tabellen
            // Brug strtolower fordi username_key gemmes med lowercase ved oprettelse
            $adminProfileStatement = $pdo->prepare(
                'DELETE FROM admin_profiles WHERE username_key = ?'
            );
            $adminProfileStatement->execute([strtolower($sletElevnummer)]);

            // Fjern admin-legitimationsoplysninger fra JSON-filen
            // Så adminen ikke længere vises i admin-oversigten / ansøgninger
            // Brug samme sti metode som php_config.php bruger (filen inkluderes allerede)
            try {
                global $appDataDirectory;
                $credentialsFile = $appDataDirectory . DIRECTORY_SEPARATOR . 'admin_credentials.json';
                
                // Tjek om filen findes før vi forsøger at redigere
                if (!file_exists($credentialsFile)) {
                    error_log('Admin-credentials fil findes ikke: ' . $credentialsFile);
                } else {
                    // Læs nuværende credentials
                    $content = file_get_contents($credentialsFile);
                    if ($content === false) {
                        error_log(' kunne ikke læse admin-credentials fil: ' . $credentialsFile);
                    } else {
                        $credentials = json_decode($content, true);
                        if ($credentials === null && json_last_err() !== JSON_ERR_NONE) {
                            error_log('Fejl ved parsing af admin-credentials JSON: ' . json_last_err_msg());
                            $credentials = [];
                        }
                        if (!is_array($credentials)) {
                            $credentials = [];
                        }
                        
                        $key = strtolower(trim($sletElevnummer));
                        
                        // Tjek om nøglen findes før vi forsøger at fjerne den
                        if (array_key_exists($key, $credentials)) {
                            unset($credentials[$key]);
                            
                            // Gem de opdaterede credentials tilbage til filen
                            $encoded = json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            if ($encoded === false) {
                                error_log('Fejl ved encoding af admin-credentials JSON');
                            } elseif (file_put_contents($credentialsFile, $encoded, LOCK_EX) === false) {
                                error_log('Fejl ved skrivning af admin-credentials fil');
                            } else {
                                error_log('Admin ' . $sletElevnummer . ' fjernet fra credentials fil');
                            }
                        } else {
                            error_log('Admin-nøgle ikke fandt i credentials fil (nøgle: ' . $key . ')');
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Kunne ikke opdatere admin-legitimationsfil: ' . $e->getMessage());
                error_log('Fejlinformation: ' . $e->getTraceAsString());
            }

            if ($statement->rowCount() === 1) {
                adminFlash('success', 'Administratoren er slettet fra databasen.');
            } else {
                adminFlash('error', 'Administratoren blev ikke fundet i databasen.');
            }
        } catch (PDOException $exception) {
            $pdo->rollBack();
            error_log('Kunne ikke slette admin: ' . $exception->getMessage());
            adminFlash('error', 'Administratoren kunne ikke slettes.');
        }

        adminRedirect('index.php');
    }

    if ($action === 'verify_login_otp') {
        $pending = $_SESSION['pending_admin_login'] ?? null;
        $otp = trim((string) ($_POST['otp'] ?? ''));
        if (!is_array($pending) || !isset($pending['username'], $pending['otp'], $pending['expires_at']) || (int) $pending['expires_at'] < time()) {
            unset($_SESSION['pending_admin_login']);
            adminFlash('error', '2-faktor-bekræftelsen er udløbet. Log ind igen.');
            adminRedirect('admin_login.php');
        }

        if (!preg_match('/^\d{6}$/', $otp) || !hash_equals((string) $pending['otp'], $otp)) {
            adminFlash('error', 'Forkert 2-faktor-kode. Prøv igen.');
            adminRedirect('admin_2fa.php');
        }

        unset($_SESSION['pending_admin_login']);
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = (string) $pending['username'];
        adminFlash('success', 'Du er logget ind i administrationsmodulet med 2-faktor-bekræftelse.');
        $redirect = $pending['redirect'] ?? 'index.php';
        adminRedirect((string) $redirect);
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $adminMatches = false;
    foreach (adminCredentialStore() as $account) {
        if (!is_array($account) || !isset($account['username'], $account['password_hash']) ||
            !is_string($account['username']) || !is_string($account['password_hash'])) {
            continue;
        }
        if (strcasecmp($username, $account['username']) === 0 && password_verify($password, $account['password_hash'])) {
            $adminMatches = true;
            $username = $account['username'];
            break;
        }
    }
    if ($action === 'login' && $adminMatches) {
        $emailRecipient = adminEmailFor($username);
        if ($emailRecipient === null) {
            adminFlash('error', 'Admin-kontoen mangler en gyldig personlig e-mailadresse. Kontakt administratoren for at få den registreret.');
            adminRedirect('admin_login.php');
        }

        $redirect = adminRedirectTarget($_POST['redirect'] ?? 'index.php');
        $otp = adminIssueOtp($username, $emailRecipient, $redirect);
        try {
            sendLoginOtpEmail($emailRecipient, $username === NIKOLAJ_ADMIN_USERNAME ? 'Nikolaj' : 'Administrator', $otp, 'administrationsmodulet');
        } catch (Throwable $exception) {
            unset($_SESSION['pending_admin_login']);
            error_log('Admin 2FA-kode kunne ikke sendes: ' . $exception->getMessage());
            adminFlash('error', 'Kunne ikke sende 2-faktor-koden til e-mailen. Kontroller SMTP-konfigurationen.');
            adminRedirect('admin_login.php');
        }
        adminFlash('success', 'Tjek din e-mail. Vi har sendt en 6-cifret kode, så du kan fuldføre login.');
        adminRedirect('admin_2fa.php');
    }

    adminFlash('error', 'Forkert brugernavn eller adgangskode.');
    adminRedirect('admin_login.php');
}

$isLoggedIn = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if ($isLoggedIn) {
    adminRedirect(adminRedirectTarget($_GET['redirect'] ?? 'index.php'));
}

$authPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'admin_login.php'));
if ($authPage === 'admin.php') {
    $legacyRedirect = adminRedirectTarget($_GET['redirect'] ?? 'index.php');
    $legacyTarget = 'admin_login.php?redirect=' . rawurlencode($legacyRedirect);
    if (isset($_GET['register']) && $_GET['register'] === '1') {
        $legacyTarget = 'admin_signup.php';
    } elseif (isset($_GET['step']) && $_GET['step'] === 'verify') {
        $legacyTarget = 'admin_2fa.php?redirect=' . rawurlencode((string) $legacyRedirect);
    }
    adminRedirect($legacyTarget);
}

$pendingAdminLogin = $_SESSION['pending_admin_login'] ?? null;
if (is_array($pendingAdminLogin) && isset($pendingAdminLogin['expires_at']) && (int) $pendingAdminLogin['expires_at'] < time()) {
    unset($_SESSION['pending_admin_login']);
    $pendingAdminLogin = null;
}
if ($authPage === 'admin_2fa.php' && (!is_array($pendingAdminLogin) || !isset($pendingAdminLogin['username']))) {
    adminFlash('error', '2-faktor-login er udløbet. Log ind igen.');
    adminRedirect('admin_login.php');
}

$messages = $_SESSION['admin_flashes'] ?? [];
unset($_SESSION['admin_flashes']);
$redirectTarget = is_array($pendingAdminLogin)
    ? adminRedirectTarget($pendingAdminLogin['redirect'] ?? 'index.php')
    : adminRedirectTarget($_GET['redirect'] ?? 'index.php');
$showRegistration = $authPage === 'admin_signup.php';
$showVerification = $authPage === 'admin_2fa.php';
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $showVerification ? 'Bekræft login' : ($showRegistration ? 'Adminansøgning' : 'Admin-login') ?> · IT-Udlån</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #05070f;
        }
        .admin-auth-glow {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            background:
                linear-gradient(rgba(148, 163, 184, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.035) 1px, transparent 1px),
                radial-gradient(ellipse at 50% 0%, rgba(30, 41, 59, 0.8), transparent 65%),
                #05070f;
            background-size: 56px 56px, 56px 56px, auto;
        }
        .admin-auth-glow::before,
        .admin-auth-glow::after {
            position: absolute;
            width: min(52vw, 680px);
            height: min(52vw, 680px);
            border-radius: 9999px;
            content: "";
            filter: blur(100px);
            opacity: 0.22;
            animation: admin-auth-drift 20s ease-in-out infinite alternate;
        }
        .admin-auth-glow::before {
            top: -34%;
            left: -12%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.9), transparent 68%);
        }
        .admin-auth-glow::after {
            right: -14%;
            bottom: -42%;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.85), transparent 68%);
            animation-delay: -7s;
            animation-direction: alternate-reverse;
        }
        @keyframes admin-auth-drift {
            from { transform: translate3d(-3%, -2%, 0) scale(0.94); }
            to { transform: translate3d(5%, 4%, 0) scale(1.08); }
        }
        @media (prefers-reduced-motion: reduce) {
            .admin-auth-glow::before,
            .admin-auth-glow::after { animation: none; }
        }
    </style>
</head>
<body class="min-h-screen text-slate-100">
    <div class="admin-auth-glow" aria-hidden="true"></div>
    <header class="relative z-10 border-b border-white/10 bg-slate-950/70 backdrop-blur-xl">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <a href="admin_login.php" class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-lg text-white shadow-lg"><i class="fa-solid fa-shield-halved"></i></span>
                <span><span class="block font-black tracking-tight text-white">IT-Udlån Pro</span><span class="text-xs text-slate-400">Administration</span></span>
            </a>
            <?php if (!$showVerification): ?>
                <nav aria-label="Admin-adgang" class="flex items-center gap-2">
                    <a href="admin_login.php" aria-current="<?= $showRegistration ? 'false' : 'page' ?>" class="rounded-full px-3 py-2 text-sm font-bold <?= $showRegistration ? 'text-slate-300 transition hover:bg-white/10 hover:text-white' : 'bg-white/10 text-white' ?>">Log ind</a>
                    <a href="admin_signup.php" aria-current="<?= $showRegistration ? 'page' : 'false' ?>" class="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 px-4 py-2.5 text-sm font-extrabold text-white shadow-lg shadow-blue-950/40 ring-1 ring-white/20 transition hover:-translate-y-0.5 hover:from-blue-400 hover:to-indigo-400">
                        <i class="fa-solid fa-user-plus text-xs" aria-hidden="true"></i>Ansøg om konto
                    </a>
                </nav>
            <?php else: ?>
                <span class="hidden items-center gap-2 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-2 text-xs font-semibold text-emerald-100 sm:inline-flex">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>Sikker bekræftelse
                </span>
            <?php endif; ?>
        </div>
    </header>

    <main class="relative z-10 mx-auto flex min-h-[calc(100vh-76px)] max-w-6xl items-center justify-center px-4 py-10 sm:px-6">
        <div class="w-full max-w-5xl">
            <?php foreach ($messages as $message): ?>
                <div role="alert" class="mx-auto mb-5 max-w-2xl rounded-xl border p-4 text-sm <?= $message['category'] === 'success' ? 'border-emerald-300/30 bg-emerald-400/10 text-emerald-100' : 'border-rose-300/30 bg-rose-400/10 text-rose-100' ?>">
                    <?= htmlspecialchars($message['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>

            <?php if ($showVerification): ?>
                <section role="dialog" aria-modal="true" aria-labelledby="admin-otp-title" class="mx-auto max-w-lg overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/90 shadow-2xl shadow-blue-950/40 backdrop-blur-xl">
                    <div class="bg-gradient-to-br from-blue-600/30 via-indigo-600/20 to-transparent p-8 sm:p-10">
                        <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/10 text-2xl text-blue-100"><i class="fa-solid fa-shield-halved"></i></div>
                        <p class="text-xs font-bold uppercase tracking-[0.22em] text-blue-200">Et ekstra sikkerhedstjek</p>
                        <h1 id="admin-otp-title" class="mt-2 text-3xl font-black text-white">Bekræft dit login</h1>
                        <p class="mt-3 text-sm leading-6 text-slate-300">Vi har sendt en 6-cifret kode til <strong class="text-white"><?= htmlspecialchars((string) ($pendingAdminLogin['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>. Koden udløber om 5 minutter.</p>
                    </div>
                    <form method="post" class="space-y-5 p-8 sm:p-10">
                        <input type="hidden" name="action" value="verify_login_otp">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(adminCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <label class="block text-sm font-semibold text-slate-200">6-cifret kode
                            <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-4 text-center text-2xl font-black tracking-[0.4em] text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20" placeholder="000000">
                        </label>
                        <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 px-4 py-3.5 text-sm font-bold text-white shadow-lg transition hover:brightness-110">
                            <i class="fa-solid fa-check mr-2"></i>Bekræft og fortsæt
                        </button>
                        <a href="admin_login.php" class="block text-center text-sm font-semibold text-slate-400 transition hover:text-white">Tilbage til login</a>
                    </form>
                </section>
            <?php else: ?>
                <div class="grid items-stretch gap-6 lg:grid-cols-[1.05fr_0.95fr]">
                    <section class="relative isolate flex min-h-[400px] flex-col justify-between overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-br from-slate-900 via-blue-950 to-indigo-950 p-8 shadow-2xl sm:p-10">
                        <div class="absolute -right-20 -top-20 -z-10 h-72 w-72 rounded-full bg-blue-400/20 blur-3xl"></div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.22em] text-blue-200">Skolens udstyrsportal</p>
                            <h1 class="mt-5 text-4xl font-black leading-tight text-white sm:text-5xl"><?= $showRegistration ? 'Ansøg om adminadgang' : 'Velkommen tilbage' ?></h1>
                            <p class="mt-5 max-w-lg leading-7 text-slate-300"><?= $showRegistration ? 'Send en ansøgning om en admin-konto. Nikolaj skal godkende den, før kontoen kan bruges.' : 'Administrér udlån, elevkonti, computere og reservationer fra ét sikkert sted.' ?></p>
                        </div>
                        <div class="mt-10 rounded-2xl border border-white/10 bg-white/5 p-5">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-400/10 text-emerald-200"><i class="fa-solid fa-lock"></i></span>
                                <div>
                                    <p class="font-bold text-white">To-faktor-login på alle admin-konti</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-300">Efter adgangskoden skal du bekræfte login med en kode sendt til din personlige e-mail.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-[2rem] border border-white/10 bg-slate-900/90 p-7 shadow-2xl shadow-black/30 backdrop-blur-xl sm:p-9">
                        <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-500/10 text-2xl text-blue-300">
                            <i class="fa-solid <?= $showRegistration ? 'fa-user-plus' : 'fa-right-to-bracket' ?>"></i>
                        </div>
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-300"><?= $showRegistration ? 'Ny admin-konto' : 'Sikker adgang' ?></p>
                        <h2 class="mt-2 text-2xl font-black text-white"><?= $showRegistration ? 'Dine oplysninger' : 'Admin-login' ?></h2>
                        <?php if ($showRegistration): ?>
                            <form method="post" class="mt-6 space-y-4">
                                <input type="hidden" name="action" value="request_admin_access">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(adminCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <label class="block text-sm font-semibold text-slate-200">Brugernavn
                                    <input type="text" name="new_username" minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]+" autocomplete="username" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20">
                                </label>
                                <label class="block text-sm font-semibold text-slate-200">E-mail
                                    <input type="email" name="new_email" maxlength="254" autocomplete="email" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20">
                                </label>
                                <label class="block text-sm font-semibold text-slate-200">Adgangskode (mindst 10 tegn)
                                    <input type="password" name="new_password" minlength="10" autocomplete="new-password" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20">
                                </label>
                                <label class="block text-sm font-semibold text-slate-200">Gentag adgangskode
                                    <input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20">
                                </label>
                                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 px-4 py-3.5 text-sm font-bold text-white shadow-lg transition hover:brightness-110">Send ansøgning</button>
                            </form>
                        <?php else: ?>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Brug din personlige admin-konto.</p>
                            <form method="post" class="mt-6 space-y-4">
                                <input type="hidden" name="action" value="login">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(adminCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <label class="block text-sm font-semibold text-slate-200">Brugernavn
                                    <input type="text" name="username" autocomplete="username" autofocus required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20">
                                </label>
                                <label class="block text-sm font-semibold text-slate-200">Adgangskode
                                    <input type="password" name="password" autocomplete="current-password" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20">
                                </label>
                                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-900/30 transition hover:brightness-110">
                                    <i class="fa-solid fa-right-to-bracket mr-2"></i>Log ind
                                </button>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
