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
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/profile_helpers.php';

function studentH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function studentCsrfToken(): string
{
    if (!isset($_SESSION['elev_csrf_token'])) {
        $_SESSION['elev_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['elev_csrf_token'];
}

function studentFlash(string $category, string $message): void
{
    $_SESSION['elev_flashes'][] = ['category' => $category, 'message' => $message];
}

function studentRedirect(?string $target = null): never
{
    if ($target === null) {
        $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $target = in_array($scriptName, ['elev_login.php', 'elev_opret_konto.php', 'elev_2fa.php'], true)
            ? $scriptName
            : 'elev.php';
    }

    header('Location: ' . $target);
    exit;
}

$pdo = null;
$pageError = null;
try {
    $pdo = database();
    ensureStudentProfileTable($pdo);
} catch (PDOException $exception) {
    error_log('Elevportal kunne ikke forbinde til databasen: ' . $exception->getMessage());
    $pageError = 'Elevportalen kan ikke forbinde til databasen lige nu.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(studentCsrfToken(), $token)) {
        http_response_code(400);
        exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
    }

    $action = $_POST['action'] ?? '';
    if (!is_string($action)) {
        http_response_code(400);
        exit('Ugyldig handling.');
    }

    try {
        if ($action === 'opret_konto') {
            $elevnummer = trim((string) ($_POST['elevnummer'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $passwordConfirmation = $_POST['password_confirmation'] ?? '';
            if (!is_string($password) || !is_string($passwordConfirmation) ||
                strlen($password) < 10 || $password !== $passwordConfirmation) {
                throw new RuntimeException('Adgangskoden skal være mindst 10 tegn, og felterne skal være ens.');
            }
            if ($elevnummer === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Skriv elevnummer og den e-mailadresse, der står i elevregisteret.');
            }
            if ($pdo === null) {
                throw new RuntimeException($pageError ?? 'Databasen er ikke tilgængelig.');
            }

            $pdo->beginTransaction();
            $studentQuery = $pdo->prepare(
                'SELECT elevnummer, email, adgangskode_hash
                 FROM brugere WHERE elevnummer = ? FOR UPDATE'
            );
            $studentQuery->execute([$elevnummer]);
            $student = $studentQuery->fetch();
            if (!$student || strcasecmp((string) $student['email'], $email) !== 0 || $student['adgangskode_hash'] !== null) {
                throw new RuntimeException(
                    'Kontoen kunne ikke oprettes. Kontrollér elevnummer og registreret e-mail, eller kontakt skolens IT-administrator.'
                );
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Adgangskoden kunne ikke gemmes sikkert.');
            }
            $pdo->prepare('UPDATE brugere SET adgangskode_hash = ? WHERE elevnummer = ?')
                ->execute([$passwordHash, $elevnummer]);
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['elev_elevnummer'] = $elevnummer;
            studentFlash('success', 'Din konto er klar. Her kan du se og aflevere dit udstyr.');
            studentRedirect('elev.php');
        }

        if ($action === 'bekraeft_logind_otp') {
            $pending = $_SESSION['elev_pending_login'] ?? null;
            $otp = trim((string) ($_POST['otp'] ?? ''));
            if (!is_array($pending) || !isset($pending['elevnummer'], $pending['otp'], $pending['expires_at']) || (int) $pending['expires_at'] < time()) {
                unset($_SESSION['elev_pending_login']);
                throw new RuntimeException('2-faktor-koden er udløbet. Log ind igen.');
            }
            if (!preg_match('/^\d{6}$/', $otp) || !hash_equals((string) $pending['otp'], $otp)) {
                throw new RuntimeException('Forkert 2-faktor-kode. Prøv igen.');
            }

            unset($_SESSION['elev_pending_login']);
            unset($_SESSION['elev_login_attempts']);
            session_regenerate_id(true);
            $_SESSION['elev_elevnummer'] = (string) $pending['elevnummer'];
            studentFlash('success', 'Du er logget ind med 2-faktor-bekræftelse.');
            studentRedirect('elev.php');
        }

        if ($action === 'log_ind') {
            $elevnummer = trim((string) ($_POST['elevnummer'] ?? ''));
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || $elevnummer === '' || $pdo === null) {
                throw new RuntimeException('Elevnummer eller adgangskode er forkert.');
            }
            $attempts = $_SESSION['elev_login_attempts'] ?? ['count' => 0, 'started' => time()];
            if (time() - (int) $attempts['started'] > 900) {
                $attempts = ['count' => 0, 'started' => time()];
            }
            if ((int) $attempts['count'] >= 5) {
                throw new RuntimeException('For mange loginforsøg. Vent 15 minutter, og prøv igen.');
            }

            $studentQuery = $pdo->prepare(
                'SELECT elevnummer, email, navn, adgangskode_hash FROM brugere WHERE elevnummer = ?'
            );
            $studentQuery->execute([$elevnummer]);
            $student = $studentQuery->fetch();
            if (!$student || !$student['adgangskode_hash'] ||
                !password_verify($password, $student['adgangskode_hash'])) {
                $attempts['count'] = (int) $attempts['count'] + 1;
                $_SESSION['elev_login_attempts'] = $attempts;
                throw new RuntimeException('Elevnummer eller adgangskode er forkert.');
            }

            $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['elev_pending_login'] = [
                'elevnummer' => $elevnummer,
                'email' => (string) $student['email'],
                'otp' => $otp,
                'expires_at' => time() + 300,
            ];
            try {
                sendLoginOtpEmail($_SESSION['elev_pending_login']['email'], (string) $student['navn'], $otp, 'elevportalen');
            } catch (Throwable $mailException) {
                unset($_SESSION['elev_pending_login']);
                error_log('Elev 2FA-kode kunne ikke sendes: ' . $mailException->getMessage());
                throw new RuntimeException('Kunne ikke sende 2-faktor-koden til din e-mail. Kontakt skolens IT-administrator.');
            }

            studentFlash('success', 'Vi har sendt en 6-cifret kode til din e-mail. Indtast den for at fuldføre login.');
            studentRedirect('elev_2fa.php');
        }

        if ($action === 'save_student_profile') {
            $studentNumber = $_SESSION['elev_elevnummer'] ?? null;
            if (!is_string($studentNumber) || $studentNumber === '' || $pdo === null) {
                throw new RuntimeException('Log ind på din egen elevkonto for at ændre profilen.');
            }

            $displayNameInput = $_POST['profile_name'] ?? '';
            if (!is_string($displayNameInput)) {
                throw new RuntimeException('Profilnavnet er ugyldigt.');
            }
            $displayName = trim($displayNameInput);
            if (!isValidProfileDisplayName($displayName)) {
                throw new RuntimeException('Profilnavnet skal være mellem 1 og 80 tegn.');
            }
            $animation = is_string($_POST['profile_animation'] ?? null) ? $_POST['profile_animation'] : '';
            $background = is_string($_POST['profile_background'] ?? null) ? $_POST['profile_background'] : '';
            if (!array_key_exists($animation, profileAnimationOptions()) ||
                !array_key_exists($background, profileBackgroundOptions())) {
                throw new RuntimeException('Vælg en gyldig profilanimation og baggrund.');
            }

            $gifData = readProfileGifUpload('profile_gif');
            $removeGif = ($_POST['remove_profile_gif'] ?? '') === '1';
            if ($gifData !== null && $removeGif) {
                throw new RuntimeException('Vælg enten en ny GIF eller fjern det nuværende billede.');
            }

            if ($gifData !== null) {
                $profileUpdate = $pdo->prepare(
                    'INSERT INTO elev_profiler (elevnummer, display_name, animation, background, avatar_gif)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                         animation = VALUES(animation), background = VALUES(background),
                         avatar_gif = VALUES(avatar_gif), updated_at = CURRENT_TIMESTAMP'
                );
                $profileUpdate->bindValue(1, $studentNumber);
                $profileUpdate->bindValue(2, $displayName);
                $profileUpdate->bindValue(3, $animation);
                $profileUpdate->bindValue(4, $background);
                $profileUpdate->bindValue(5, $gifData, PDO::PARAM_LOB);
                $profileUpdate->execute();
            } elseif ($removeGif) {
                $pdo->prepare(
                    'INSERT INTO elev_profiler (elevnummer, display_name, animation, background, avatar_gif)
                     VALUES (?, ?, ?, ?, NULL)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                         animation = VALUES(animation), background = VALUES(background),
                         avatar_gif = NULL, updated_at = CURRENT_TIMESTAMP'
                )->execute([$studentNumber, $displayName, $animation, $background]);
            } else {
                $pdo->prepare(
                    'INSERT INTO elev_profiler (elevnummer, display_name, animation, background)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                         animation = VALUES(animation), background = VALUES(background),
                         updated_at = CURRENT_TIMESTAMP'
                )->execute([$studentNumber, $displayName, $animation, $background]);
            }

            studentFlash('success', 'Din profil er opdateret.');
            studentRedirect();
        }

        if ($action === 'change_my_password') {
            $studentNumber = $_SESSION['elev_elevnummer'] ?? null;
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $passwordConfirmation = $_POST['password_confirmation'] ?? '';
            if (!is_string($studentNumber) || $studentNumber === '' || $pdo === null ||
                !is_string($currentPassword) || !is_string($newPassword) || !is_string($passwordConfirmation)) {
                throw new RuntimeException('Log ind på din egen elevkonto for at ændre adgangskoden.');
            }
            if (strlen($newPassword) < 10 || $newPassword !== $passwordConfirmation) {
                throw new RuntimeException('Den nye adgangskode skal være mindst 10 tegn, og begge felter skal være ens.');
            }

            $pdo->beginTransaction();
            $passwordQuery = $pdo->prepare(
                'SELECT adgangskode_hash FROM brugere WHERE elevnummer = ? FOR UPDATE'
            );
            $passwordQuery->execute([$studentNumber]);
            $storedHash = $passwordQuery->fetchColumn();
            if (!is_string($storedHash) || $storedHash === '' || !password_verify($currentPassword, $storedHash)) {
                throw new RuntimeException('Den nuværende adgangskode er forkert.');
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Adgangskoden kunne ikke gemmes sikkert.');
            }
            $pdo->prepare(
                'UPDATE brugere SET adgangskode_hash = ?,
                    reset_token = NULL, reset_otp = NULL, reset_expires = NULL
                 WHERE elevnummer = ?'
            )->execute([$passwordHash, $studentNumber]);
            $pdo->commit();
            session_regenerate_id(true);
            studentFlash('success', 'Din adgangskode er ændret på din egen konto.');
            studentRedirect();
        }

        if ($action === 'anmod_om_ny_adgangskode') {
            $authenticatedStudentNumber = $_SESSION['elev_elevnummer'] ?? null;
            if ($pdo === null) {
                throw new RuntimeException('Elevportalen kan ikke forbinde til databasen lige nu.');
            }
            if (is_string($authenticatedStudentNumber) && $authenticatedStudentNumber !== '') {
                $student = $pdo->prepare(
                    'SELECT elevnummer, navn, email FROM brugere WHERE elevnummer = ? LIMIT 1'
                );
                $student->execute([$authenticatedStudentNumber]);
                $studentRecord = $student->fetch();
            } else {
                $elevnummer = trim((string) ($_POST['elevnummer'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                if ($elevnummer === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Udfyld både elevnummer og den registrerede e-mailadresse.');
                }
                $student = $pdo->prepare(
                    'SELECT elevnummer, navn, email FROM brugere WHERE elevnummer = ? AND email = ? LIMIT 1'
                );
                $student->execute([$elevnummer, $email]);
                $studentRecord = $student->fetch();
            }
            if ($studentRecord !== false) {
                $token = bin2hex(random_bytes(32));
                $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                $pdo->prepare(
                    'UPDATE brugere SET reset_token = ?, reset_otp = ?, reset_expires = ? WHERE elevnummer = ?'
                )->execute([$token, $otp, $expiresAt, $studentRecord['elevnummer']]);
                sendStudentPasswordResetEmail($studentRecord, $token, $otp);
            }

            studentFlash('success', 'Hvis oplysningerne matcher vores register, har vi sendt et link og en verifikationskode til din e-mail.');
            studentRedirect();
        }

        if ($action === 'slet_min_konto') {
            $studentNumber = $_SESSION['elev_elevnummer'] ?? null;
            if (!is_string($studentNumber) || $pdo === null) {
                throw new RuntimeException('Du skal være logget ind for at slette din konto.');
            }

            $pdo->beginTransaction();
            $hasActiveLoans = $pdo->prepare('SELECT COUNT(*) FROM udlaan WHERE elevnummer = ? AND afleveret = 0');
            $hasActiveLoans->execute([$studentNumber]);
            if ((int) $hasActiveLoans->fetchColumn() > 0) {
                throw new RuntimeException('Du kan ikke slette din konto, mens du har aktive lån. Aflever først dit udstyr eller kontakt skolens IT-administrator.');
            }

            $deleteLoanHistory = $pdo->prepare('DELETE FROM udlaan WHERE elevnummer = ?');
            $deleteLoanHistory->execute([$studentNumber]);
            $pdo->prepare('DELETE FROM elev_profiler WHERE elevnummer = ?')
                ->execute([$studentNumber]);

            $delete = $pdo->prepare('DELETE FROM brugere WHERE elevnummer = ?');
            $delete->execute([$studentNumber]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Kontoen blev ikke fundet.');
            }
            $pdo->commit();
            unset($_SESSION['elev_elevnummer']);
            session_regenerate_id(true);
            studentFlash('success', 'Din elevkonto er nu slettet.');
            studentRedirect();
        }

        if ($action === 'log_ud') {
            unset($_SESSION['elev_elevnummer'], $_SESSION['elev_login_attempts']);
            session_regenerate_id(true);
            studentFlash('success', 'Du er logget ud.');
            studentRedirect();
        }

        if ($action === 'aflever') {
            $studentNumber = $_SESSION['elev_elevnummer'] ?? null;
            $loanId = filter_var($_POST['laan_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_string($studentNumber) || $loanId === false || $loanId === null || $pdo === null) {
                throw new RuntimeException('Afleveringen kunne ikke registreres. Log ind igen, og prøv igen.');
            }

            $pdo->beginTransaction();
            $loanQuery = $pdo->prepare(
                'SELECT u.computernummer, u.elevnummer, u.udloebsdato,
                        b.navn, b.email, c.fabrikat, c.model, c.mus
                 FROM udlaan u
                 JOIN brugere b ON b.elevnummer = u.elevnummer
                 JOIN computere c ON c.computernummer = u.computernummer
                 WHERE u.id = ? AND u.elevnummer = ? AND u.afleveret = 0
                 FOR UPDATE'
            );
            $loanQuery->execute([$loanId, $studentNumber]);
            $loan = $loanQuery->fetch();
            if (!$loan) {
                throw new RuntimeException('Det aktive lån blev ikke fundet på din konto.');
            }
            $pdo->prepare('UPDATE udlaan SET afleveret = 1, afleveringsdato = NOW() WHERE id = ?')
                ->execute([$loanId]);
            $pdo->prepare("UPDATE computere SET status = 'tilgængelig' WHERE computernummer = ?")
                ->execute([$loan['computernummer']]);

            $lateReturns = $pdo->prepare(
                'SELECT COUNT(*) FROM udlaan
                 WHERE elevnummer = ? AND afleveret = 1
                   AND afleveringsdato IS NOT NULL AND DATE(afleveringsdato) > udloebsdato'
            );
            $lateReturns->execute([$studentNumber]);
            $lateCount = (int) $lateReturns->fetchColumn();
            if ($lateCount >= LATE_RETURN_BLACKLIST_THRESHOLD) {
                $pdo->prepare(
                    'UPDATE brugere SET blacklisted = 1, blacklist_reason = ? WHERE elevnummer = ?'
                )->execute([
                    'Automatisk: ' . $lateCount . ' forsinkede afleveringer (grænse: ' .
                        LATE_RETURN_BLACKLIST_THRESHOLD . ').',
                    $studentNumber,
                ]);
            }
            $returnDate = $pdo->prepare('SELECT afleveringsdato FROM udlaan WHERE id = ?');
            $returnDate->execute([$loanId]);
            $loan['afleveringsdato'] = $returnDate->fetchColumn();
            $pdo->commit();
            try {
                sendReturnConfirmationEmail($loan);
                studentFlash('success', 'Afleveringen er registreret, og en kvittering er sendt til ' . $loan['email'] . '.');
            } catch (Throwable $mailException) {
                error_log('Aflevering registreret, men kvitteringsmail fejlede: ' . $mailException->getMessage());
                studentFlash('error', 'Afleveringen er registreret, men kvitteringen kunne ikke sendes. Kontakt skolens IT-administrator.');
            }
            studentRedirect();
        }

        throw new RuntimeException('Ukendt handling.');
    } catch (Throwable $exception) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Elevportal-handling fejlede: ' . $exception->getMessage());

        $message = $exception instanceof PDOException
            ? 'Handlingen kunne ikke gemmes. Prøv igen, eller kontakt skolens IT-administrator.'
            : $exception->getMessage();

        if ($exception instanceof PDOException) {
            $dbMessage = $exception->getMessage();
            if (stripos($dbMessage, 'foreign key') !== false ||
                stripos($dbMessage, 'integrity constraint') !== false ||
                stripos($dbMessage, 'cannot delete or update a parent row') !== false) {
                $message = 'Din konto har tilknyttede lån eller registreringer. Aflever først udstyret eller kontakt skolens IT-administrator.';
            }
        }

        studentFlash('error', $message);
        studentRedirect();
    }
}

$messages = $_SESSION['elev_flashes'] ?? [];
unset($_SESSION['elev_flashes']);
$pendingStudentLogin = $_SESSION['elev_pending_login'] ?? null;
if (is_array($pendingStudentLogin) && isset($pendingStudentLogin['expires_at']) && (int) $pendingStudentLogin['expires_at'] < time()) {
    unset($_SESSION['elev_pending_login']);
    $pendingStudentLogin = null;
}
$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'elev.php'));
$isDedicatedAuthPage = in_array($currentScript, ['elev_login.php', 'elev_opret_konto.php', 'elev_2fa.php'], true);
$isStudentRegistrationPage = $currentScript === 'elev_opret_konto.php';
$isStudentVerificationPage = $currentScript === 'elev_2fa.php';
if ($isStudentVerificationPage && !is_array($pendingStudentLogin)) {
    studentFlash('error', '2-faktor-koden er udløbet. Log ind igen.');
    studentRedirect('elev_login.php');
}
$studentNumber = $_SESSION['elev_elevnummer'] ?? null;
$student = null;
$studentLoans = [];
$studentProfileSettings = [
    'display_name' => '',
    'animation' => 'none',
    'background' => 'blue',
    'has_avatar' => false,
];
if (is_string($studentNumber) && $pdo !== null) {
    try {
        $studentQuery = $pdo->prepare(
            'SELECT elevnummer, navn, email, stamklasse, blacklisted
             FROM brugere WHERE elevnummer = ?'
        );
        $studentQuery->execute([$studentNumber]);
        $student = $studentQuery->fetch();
        if (!$student) {
            unset($_SESSION['elev_elevnummer']);
            $studentNumber = null;
        } else {
            $studentProfileSettings['display_name'] = (string) $student['navn'];
            $profileQuery = $pdo->prepare(
                'SELECT display_name, animation, background, avatar_gif IS NOT NULL AS has_avatar
                 FROM elev_profiler WHERE elevnummer = ?'
            );
            $profileQuery->execute([$studentNumber]);
            $savedProfile = $profileQuery->fetch();
            if ($savedProfile !== false) {
                $studentProfileSettings['display_name'] = (string) $savedProfile['display_name'];
                $studentProfileSettings['animation'] = (string) $savedProfile['animation'];
                $studentProfileSettings['background'] = (string) $savedProfile['background'];
                $studentProfileSettings['has_avatar'] = (bool) $savedProfile['has_avatar'];
            }

            $loanQuery = $pdo->prepare(
                'SELECT u.id, u.computernummer, c.fabrikat, c.model, c.mus,
                        u.udlaansdato, u.udloebsdato, u.afleveret, u.afleveringsdato,
                        DATEDIFF(CURDATE(), u.udloebsdato) AS dage_over_tid
                 FROM udlaan u
                 JOIN computere c ON c.computernummer = u.computernummer
                 WHERE u.elevnummer = ?
                 ORDER BY u.afleveret ASC, u.udloebsdato DESC'
            );
            $loanQuery->execute([$studentNumber]);
            $studentLoans = $loanQuery->fetchAll();
            $completedLoans = 0;
            $onTimeLoans = 0;
            $lateLoans = 0;
            $activeLoans = 0;
            foreach ($studentLoans as $loan) {
                if ((int) $loan['afleveret'] === 1) {
                    $completedLoans++;
                    if ($loan['afleveringsdato'] !== null && date('Y-m-d', strtotime((string) $loan['afleveringsdato'])) <= (string) $loan['udloebsdato']) {
                        $onTimeLoans++;
                    } else {
                        $lateLoans++;
                    }
                } else {
                    $activeLoans++;
                }
            }
            $trackedLoans = max(1, $completedLoans + $activeLoans);
            $reputationScore = $completedLoans > 0
                ? min(100, max(0, round(($onTimeLoans / $completedLoans) * 100)))
                : 92;
            $reputationLabel = $lateLoans === 0 && $completedLoans > 0 ? 'Elite-låner' : ($onTimeLoans >= 1 ? 'Punktlig' : 'Ny låner');
            $studentWarning = null;
            if ($reputationScore < 50) {
                $studentWarning = 'Din reputation er under 50. Du har fået en skriftlig advarsel og skal forbedre dine afleveringer for at undgå yderligere konsekvenser.';
                if (empty($_SESSION['student_reputation_warning_sent'])) {
                    try {
                        sendReputationWarningEmail([
                            'navn' => (string) $student['navn'],
                            'email' => (string) $student['email'],
                        ], $reputationScore);
                        $_SESSION['student_reputation_warning_sent'] = true;
                    } catch (Throwable $mailException) {
                        error_log('Skriftlig advarsel til elev kunne ikke sendes: ' . $mailException->getMessage());
                    }
                }
            } else {
                unset($_SESSION['student_reputation_warning_sent']);
            }
            $studentBadges = [];
            if ($completedLoans >= 1 && $lateLoans === 0) {
                $studentBadges[] = ['title' => 'Punktlig låner', 'icon' => 'fa-medal', 'color' => 'emerald'];
            }
            if ($completedLoans >= 2 && $lateLoans === 0) {
                $studentBadges[] = ['title' => 'Ansvarlig elev', 'icon' => 'fa-shield-heart', 'color' => 'blue'];
            }
            if ($activeLoans === 0 && $completedLoans >= 1) {
                $studentBadges[] = ['title' => 'Nøgleholder', 'icon' => 'fa-key', 'color' => 'violet'];
            }
            if ($lateLoans > 0) {
                $studentBadges[] = ['title' => 'Fokus på afleveringer', 'icon' => 'fa-bolt', 'color' => 'amber'];
            }
            if ($studentBadges === []) {
                $studentBadges[] = ['title' => 'Ny i systemet', 'icon' => 'fa-star', 'color' => 'slate'];
            }
        }
    } catch (PDOException $exception) {
        error_log('Elevportal kunne ikke hente elevens lån: ' . $exception->getMessage());
        $pageError = 'Dine låneoplysninger kunne ikke hentes lige nu.';
    }
}

$studentProfileAnimationClass = profileAnimationClasses()[$studentProfileSettings['animation']] ?? '';
$studentProfileBackgroundClass = profileBackgroundClasses()[$studentProfileSettings['background']] ?? profileBackgroundClasses()['blue'];
if (is_array($student) && $isDedicatedAuthPage) {
    header('Location: elev.php');
    exit;
}
if (!is_array($student) && !$isDedicatedAuthPage) {
    header('Location: elev_login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elevportal · IT-Udlån</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .auth-page {
            background: #05070f;
        }
        .auth-page .auth-backdrop {
            position: fixed;
            z-index: 0;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            background-color: #05070f;
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.035) 1px, transparent 1px),
                radial-gradient(ellipse at 50% 0%, rgba(30, 41, 59, 0.8), transparent 65%);
            background-size: 56px 56px, 56px 56px, auto;
        }
        .auth-page .auth-backdrop::before,
        .auth-page .auth-backdrop::after {
            position: absolute;
            content: "";
            width: min(52vw, 680px);
            height: min(52vw, 680px);
            border-radius: 9999px;
            filter: blur(100px);
            opacity: 0.24;
            will-change: transform;
        }
        .auth-page .auth-backdrop::before {
            top: -34%;
            left: -12%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.9), transparent 68%);
            animation: auth-drift 19s ease-in-out infinite alternate;
        }
        .auth-page .auth-backdrop::after {
            right: -14%;
            bottom: -42%;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.85), transparent 68%);
            animation: auth-drift 24s ease-in-out -7s infinite alternate-reverse;
        }
        .auth-page > header,
        .auth-page > main {
            position: relative;
            z-index: 1;
        }
        .auth-topbar {
            border-bottom: 1px solid rgba(148, 163, 184, 0.12);
            background: rgba(3, 7, 18, 0.76);
            box-shadow: 0 18px 55px rgba(0, 0, 0, 0.24);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        @keyframes auth-drift {
            from { transform: translate3d(-3%, -2%, 0) scale(0.94); }
            to { transform: translate3d(5%, 4%, 0) scale(1.08); }
        }
        .profile-menu > summary { list-style: none; }
        .profile-menu > summary::-webkit-details-marker { display: none; }
        .profile-float { animation: profile-float 3.8s ease-in-out infinite; }
        .profile-pulse { animation: profile-pulse 2.8s ease-in-out infinite; }
        .profile-glow { animation: profile-glow 3.2s ease-in-out infinite; }
        @keyframes profile-float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }
        @keyframes profile-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        @keyframes profile-glow {
            0%, 100% { box-shadow: 0 0 0 rgba(129, 140, 248, 0); }
            50% { box-shadow: 0 0 24px rgba(129, 140, 248, 0.65); }
        }
        @media (prefers-reduced-motion: reduce) {
            .profile-float, .profile-pulse, .profile-glow,
            .auth-page .auth-backdrop::before, .auth-page .auth-backdrop::after { animation: none; }
        }
    </style>
</head>
<body class="<?= $isDedicatedAuthPage ? 'auth-page min-h-screen bg-[#05070f] text-slate-100' : 'min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(59,130,246,0.14),transparent_25%),radial-gradient(circle_at_bottom_right,_rgba(168,85,247,0.12),transparent_30%),linear-gradient(135deg,_#eff6ff_0%,_#eef2ff_40%,_#f8fafc_100%)] text-slate-800' ?>">
<?php if ($isDedicatedAuthPage): ?>
    <div class="auth-backdrop" aria-hidden="true"></div>
<?php endif; ?>
<header class="<?= $isDedicatedAuthPage ? 'auth-topbar text-white' : 'bg-gradient-to-r from-slate-950 via-blue-950 to-indigo-950 text-white shadow-[0_20px_60px_rgba(30,41,59,0.25)]' ?>">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-2 px-3 py-4 sm:gap-4 sm:px-5">
        <a href="<?= $isDedicatedAuthPage ? 'elev_login.php' : 'elev.php' ?>" class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-lg text-white shadow-lg shadow-blue-950/40 sm:h-11 sm:w-11"><i class="fa-solid fa-laptop-code"></i></span>
            <span><span class="block text-lg font-black tracking-tight">IT-Udlån</span><span class="text-xs font-medium text-blue-200">Elevportal</span></span>
        </a>
        <?php if ($isDedicatedAuthPage && !$isStudentVerificationPage): ?>
            <nav aria-label="Elevportal" class="flex items-center gap-2">
                <a href="elev_login.php" aria-current="<?= $isStudentRegistrationPage ? 'false' : 'page' ?>" class="rounded-full px-2.5 py-2 text-xs font-bold transition sm:px-3 sm:text-sm <?= $isStudentRegistrationPage ? 'text-slate-300 hover:bg-white/10 hover:text-white' : 'bg-white/10 text-white shadow-inner' ?>">Log ind</a>
                <a href="elev_opret_konto.php" aria-current="<?= $isStudentRegistrationPage ? 'page' : 'false' ?>" class="inline-flex items-center gap-1.5 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 px-3 py-2 text-xs font-extrabold text-white shadow-lg shadow-blue-950/40 ring-1 ring-white/20 transition hover:-translate-y-0.5 hover:from-blue-400 hover:to-indigo-400 hover:shadow-blue-500/25 focus:outline-none focus:ring-4 focus:ring-blue-300/30 sm:gap-2 sm:px-4 sm:py-2.5 sm:text-sm">
                    <i class="fa-solid fa-user-plus text-xs" aria-hidden="true"></i><span>Opret konto</span>
                </a>
            </nav>
            <div class="hidden items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2 text-xs font-semibold text-slate-300 lg:flex">
                <i class="fa-solid fa-shield-halved text-emerald-300" aria-hidden="true"></i>
                <span>Sikker elevadgang</span>
            </div>
        <?php elseif ($isStudentVerificationPage): ?>
            <span class="inline-flex items-center gap-2 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-2 text-xs font-semibold text-emerald-100">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>Sikker bekræftelse
            </span>
        <?php else: ?>
            <div class="rounded-full border border-white/15 bg-white/5 px-3 py-2 text-xs font-bold uppercase tracking-[0.2em] text-blue-100">Skole IT service</div>
        <?php endif; ?>
    </div>
</header>

<main class="<?= $isDedicatedAuthPage ? 'mx-auto max-w-6xl px-5 py-10 md:py-14' : 'mx-auto max-w-6xl px-5 py-10' ?>">
    <?php if ($pageError !== null): ?>
        <div role="alert" class="mb-6 rounded-xl border border-rose-300 bg-rose-50 p-4 text-rose-800"><?= studentH($pageError) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $message): ?>
        <div role="alert" class="mb-4 rounded-xl border p-4 <?= $message['category'] === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-rose-300 bg-rose-50 text-rose-800' ?>">
            <?= studentH($message['message']) ?>
        </div>
    <?php endforeach; ?>

    <?php if (is_array($student)): ?>
        <section class="mb-8 flex flex-wrap items-center justify-between gap-5 rounded-3xl bg-white p-7 shadow-sm">
            <div>
                <p class="text-sm font-bold uppercase tracking-wider text-blue-600">Min låneoversigt</p>
                <h1 class="mt-2 text-3xl font-black">Hej <?= studentH($studentProfileSettings['display_name']) ?></h1>
                <p class="mt-2 text-slate-500"><?= studentH($student['stamklasse']) ?> · <?= studentH($student['email']) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <nav class="flex flex-wrap items-center gap-2 rounded-full border border-slate-200 bg-slate-50 p-1">
                    <a href="#oversigt" class="rounded-full px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">Oversigt</a>
                    <a href="#mine-laan" class="rounded-full px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">Mine lån</a>
                    <a href="#skift-adgangskode" class="rounded-full px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">Skift kode</a>
                </nav>
                <details class="profile-menu relative z-50">
                    <summary class="flex cursor-pointer items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm transition hover:border-blue-300">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br <?= studentH($studentProfileBackgroundClass) ?> text-lg text-white <?= studentH($studentProfileAnimationClass) ?>">
                            <?php if ($studentProfileSettings['has_avatar']): ?>
                                <img src="student_profile_image.php" alt="" class="h-full w-full object-cover">
                            <?php else: ?>
                                <i class="fa-solid fa-user" aria-hidden="true"></i>
                            <?php endif; ?>
                        </span>
                        <span class="max-w-[8rem] truncate text-sm font-bold text-slate-800"><?= studentH($studentProfileSettings['display_name']) ?></span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i>
                    </summary>
                    <div class="absolute right-0 mt-3 max-h-[80vh] w-80 max-w-[90vw] overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-2xl">
                        <div class="bg-gradient-to-br <?= studentH($studentProfileBackgroundClass) ?> p-5 text-white">
                            <div class="flex items-center gap-4">
                                <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-white/80 bg-white/20 text-2xl shadow-lg <?= studentH($studentProfileAnimationClass) ?>">
                                    <?php if ($studentProfileSettings['has_avatar']): ?>
                                        <img src="student_profile_image.php" alt="" class="h-full w-full object-cover">
                                    <?php else: ?>
                                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </span>
                                <div class="min-w-0">
                                    <p class="break-words text-lg font-black"><?= studentH($studentProfileSettings['display_name']) ?></p>
                                    <p class="mt-1 truncate text-sm text-white/80">Elev <?= studentH($student['elevnummer']) ?></p>
                                </div>
                            </div>
                        </div>
                        <form method="post" enctype="multipart/form-data" action="elev.php" class="space-y-4 p-5">
                            <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                            <input type="hidden" name="action" value="save_student_profile">
                            <label class="block text-sm font-semibold text-slate-700">
                                Visningsnavn
                                <input type="text" name="profile_name" maxlength="80" required value="<?= studentH($studentProfileSettings['display_name']) ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900">
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">
                                Profilanimation
                                <select name="profile_animation" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900">
                                    <?php foreach (profileAnimationOptions() as $value => $label): ?>
                                        <option value="<?= studentH($value) ?>" <?= $studentProfileSettings['animation'] === $value ? 'selected' : '' ?>><?= studentH($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">
                                Profilbaggrund
                                <select name="profile_background" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900">
                                    <?php foreach (profileBackgroundOptions() as $value => $label): ?>
                                        <option value="<?= studentH($value) ?>" <?= $studentProfileSettings['background'] === $value ? 'selected' : '' ?>><?= studentH($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">
                                GIF-profilbillede
                                <input type="file" name="profile_gif" accept="image/gif,.gif" class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2 text-sm">
                                <span class="mt-1 block text-xs font-normal text-slate-500">GIF · højst 512 KB og 512 × 512 pixels</span>
                            </label>
                            <?php if ($studentProfileSettings['has_avatar']): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" name="remove_profile_gif" value="1" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                    Fjern nuværende GIF
                                </label>
                            <?php endif; ?>
                            <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700">Gem profil</button>
                        </form>
                    </div>
                </details>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                    <input type="hidden" name="action" value="log_ud">
                    <button class="rounded-xl border border-slate-200 px-4 py-2 font-semibold hover:bg-slate-50" type="submit">Log ud</button>
                </form>
            </div>
        </section>

        <?php if ((int) $student['blacklisted'] === 1): ?>
            <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">
                Din konto kan stadig se aktive lån og registrere afleveringer. Kontakt skolens IT-administrator, hvis du har spørgsmål om nye lån.
            </div>
        <?php endif; ?>
        <?php if ($studentWarning !== null): ?>
            <div class="mb-6 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-rose-900 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-lg text-rose-700"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.15em] text-rose-700">Skriftlig advarsel</p>
                        <p class="mt-1 text-sm leading-6"><?= studentH($studentWarning) ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <section id="oversigt" class="mb-8 grid gap-4 md:grid-cols-4">
            <div class="rounded-3xl bg-gradient-to-br from-indigo-600 to-blue-600 p-5 text-white shadow-lg shadow-indigo-200">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-100">Reputation</span>
                    <i class="fa-solid fa-chart-line text-xl text-indigo-100"></i>
                </div>
                <div class="mt-4 flex items-end gap-2">
                    <span class="text-4xl font-black"><?= studentH((string) $reputationScore) ?></span>
                    <span class="mb-1 text-lg text-indigo-100">/100</span>
                </div>
                <p class="mt-3 text-sm text-indigo-100"><?= studentH($reputationLabel) ?></p>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between text-slate-500">
                    <span class="text-sm font-semibold uppercase tracking-[0.2em]">Aktive</span>
                    <i class="fa-solid fa-laptop text-blue-600"></i>
                </div>
                <div class="mt-4 text-3xl font-black text-slate-900"><?= studentH((string) $activeLoans) ?></div>
                <p class="mt-2 text-sm text-slate-500">Lån i brug</p>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between text-slate-500">
                    <span class="text-sm font-semibold uppercase tracking-[0.2em]">Afleveret</span>
                    <i class="fa-solid fa-check-circle text-emerald-600"></i>
                </div>
                <div class="mt-4 text-3xl font-black text-slate-900"><?= studentH((string) $completedLoans) ?></div>
                <p class="mt-2 text-sm text-slate-500">Fuldførte afleveringer</p>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between text-slate-500">
                    <span class="text-sm font-semibold uppercase tracking-[0.2em]">Badges</span>
                    <i class="fa-solid fa-award text-amber-500"></i>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($studentBadges as $badge): ?>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-700">
                            <i class="fa-solid <?= studentH($badge['icon']) ?> mr-1"></i><?= studentH($badge['title']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="skift-adgangskode" class="mb-8 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-blue-600">Sikkerhed</p>
                    <h2 class="mt-1 text-2xl font-black">Adgangskode til din konto</h2>
                </div>
            </div>
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <h3 class="font-bold text-slate-900">Skift adgangskode</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Du kan kun ændre adgangskoden til din egen konto. Bekræft med din nuværende adgangskode.</p>
                    <form method="post" class="mt-4 space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                        <input type="hidden" name="action" value="change_my_password">
                        <label class="block text-sm font-semibold text-slate-700">Nuværende adgangskode
                            <input type="password" name="current_password" autocomplete="current-password" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-slate-900">
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Ny adgangskode
                            <input type="password" name="new_password" minlength="10" autocomplete="new-password" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-slate-900">
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Gentag ny adgangskode
                            <input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-slate-900">
                        </label>
                        <button class="rounded-xl bg-blue-600 px-4 py-3 font-bold text-white hover:bg-blue-700" type="submit">Skift min adgangskode</button>
                    </form>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <h3 class="font-bold text-slate-900">Glemt adgangskoden?</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Send et sikkert nulstillingslink til e-mailadressen, der er registreret på din konto.</p>
                    <form method="post" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                        <input type="hidden" name="action" value="anmod_om_ny_adgangskode">
                        <button class="rounded-xl border border-blue-200 bg-white px-4 py-3 font-bold text-blue-700 hover:bg-blue-50" type="submit">Send link til min e-mail</button>
                    </form>
                </div>
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
                <form method="post" class="w-full max-w-md" onsubmit="return confirm('Bekræft, at du vil slette din elevkonto? Dette kan ikke fortrydes.')">
                    <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                    <input type="hidden" name="action" value="slet_min_konto">
                    <button class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 font-semibold text-rose-700 hover:bg-rose-100" type="submit">Slet min konto</button>
                </form>
            </div>
        </section>

        <section id="mine-laan" class="overflow-hidden rounded-3xl bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5">
                <h2 class="text-xl font-bold">Mine lån</h2>
                <p class="mt-1 text-sm text-slate-500">Her kan du se dine aktive og tidligere lån. Registrér aflevering, også når du afleverer før fristen.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="p-4">Udstyr</th><th class="p-4">Mus</th><th class="p-4">Udlånsdato</th><th class="p-4">Afleveringsfrist</th><th class="p-4">Status</th><th class="p-4">Handling</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php if (!$studentLoans): ?>
                        <tr><td colspan="6" class="p-10 text-center text-slate-500">Du har ingen lån endnu.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($studentLoans as $loan): ?>
                        <tr>
                            <td class="p-4"><span class="font-bold"><?= studentH($loan['computernummer']) ?></span><br><span class="text-slate-500"><?= studentH($loan['fabrikat'] . ' ' . $loan['model']) ?></span></td>
                            <td class="p-4"><?= studentH($loan['mus']) ?></td>
                            <td class="p-4"><?= studentH($loan['udlaansdato']) ?></td>
                            <td class="p-4 <?= (int) $loan['dage_over_tid'] > 0 && (int) $loan['afleveret'] === 0 ? 'font-bold text-rose-600' : '' ?>"><?= studentH($loan['udloebsdato']) ?></td>
                            <td class="p-4">
                                <?php if ((int) $loan['afleveret'] === 1): ?>
                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Afleveret<?= $loan['afleveringsdato'] ? ' ' . studentH($loan['afleveringsdato']) : '' ?></span>
                                <?php elseif ((int) $loan['dage_over_tid'] > 0): ?>
                                    <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-bold text-rose-800">Forfalden</span>
                                <?php else: ?>
                                    <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800">Aktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4">
                                <?php if ((int) $loan['afleveret'] === 0): ?>
                                    <form method="post" onsubmit="return confirm('Bekræft, at du har afleveret denne computer og mus?')">
                                        <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="aflever">
                                        <input type="hidden" name="laan_id" value="<?= studentH($loan['id']) ?>">
                                        <button class="whitespace-nowrap rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700" type="submit"><i class="fa-solid fa-check mr-1"></i> Jeg har afleveret</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <?php if ($isStudentRegistrationPage): ?>
            <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                <section class="relative isolate overflow-hidden rounded-[2rem] bg-gradient-to-br from-indigo-950 via-blue-900 to-slate-900 p-8 text-white shadow-2xl md:p-10">
                    <div class="absolute -right-20 -top-20 -z-10 h-64 w-64 rounded-full bg-blue-400/20 blur-3xl"></div>
                    <p class="text-xs font-bold uppercase tracking-[0.25em] text-blue-200">Elevportal · IT-Udlån</p>
                    <h1 class="mt-5 text-3xl font-black leading-tight md:text-4xl">Opret din konto</h1>
                    <p class="mt-4 max-w-md leading-7 text-blue-100">Aktivér din elevkonto én gang, så kan du fremover følge dine lån og registrere afleveringer online.</p>
                    <div class="mt-8 space-y-4">
                        <div class="flex gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-blue-200"><i class="fa-solid fa-id-card"></i></span>
                            <p class="text-sm leading-6 text-blue-50">Brug elevnummeret og e-mailadressen, som skolen har registreret på dig.</p>
                        </div>
                        <div class="flex gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-blue-200"><i class="fa-solid fa-lock"></i></span>
                            <p class="text-sm leading-6 text-blue-50">Vælg en adgangskode på mindst 10 tegn. Du bekræfter senere login med en kode på e-mail.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-[2rem] border border-slate-200 bg-white p-7 shadow-xl shadow-slate-900/5 md:p-9">
                    <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-2xl text-indigo-700"><i class="fa-solid fa-user-plus"></i></div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-indigo-700">Kom godt i gang</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-900">Dine kontooplysninger</h2>
                    <p class="mb-6 mt-2 text-sm leading-6 text-slate-500">Du skal allerede være oprettet af skolen i elevregisteret.</p>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                        <input type="hidden" name="action" value="opret_konto">
                        <label class="block text-sm font-semibold text-slate-700">Elevnummer
                            <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3.5 text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" name="elevnummer" autocomplete="username" required>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Registreret e-mail
                            <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3.5 text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" type="email" name="email" autocomplete="email" required>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Ny adgangskode
                            <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3.5 text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" type="password" name="password" minlength="10" autocomplete="new-password" required>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Gentag adgangskode
                            <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3.5 text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required>
                        </label>
                        <button class="w-full rounded-xl bg-indigo-600 px-5 py-3.5 font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200" type="submit">Opret konto</button>
                    </form>
                    <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">Elevnummer og e-mail kontrolleres mod skolens register. Kontooprettelsen bekræfter ikke i sig selv ejerskab af e-mailadressen; senere login kræver en kode sendt til den registrerede mail.</p>
                </section>
            </div>
        <?php elseif ($isStudentVerificationPage): ?>
            <section role="dialog" aria-modal="true" aria-labelledby="student-otp-title" class="mx-auto max-w-lg overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/90 text-white shadow-2xl shadow-blue-950/40 backdrop-blur-xl">
                <div class="bg-gradient-to-br from-blue-600/30 via-indigo-600/20 to-transparent p-8 sm:p-10">
                    <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/10 text-2xl text-blue-100"><i class="fa-solid fa-shield-halved"></i></div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-blue-200">Et ekstra sikkerhedstjek</p>
                    <h1 id="student-otp-title" class="mt-2 text-3xl font-black">Bekræft dit login</h1>
                    <p class="mt-3 text-sm leading-6 text-slate-300">Vi har sendt en 6-cifret kode til <strong class="text-white"><?= studentH($pendingStudentLogin['email'] ?? 'din registrerede e-mail') ?></strong>. Koden udløber om 5 minutter.</p>
                </div>
                <form method="post" class="space-y-5 p-8 sm:p-10">
                    <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                    <input type="hidden" name="action" value="bekraeft_logind_otp">
                    <label class="block text-sm font-semibold text-slate-200">6-cifret kode
                        <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-4 text-center text-2xl font-black tracking-[0.4em] text-white outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20" placeholder="000000">
                    </label>
                    <button class="w-full rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 px-5 py-3.5 font-bold text-white shadow-lg transition hover:brightness-110" type="submit">
                        <i class="fa-solid fa-check mr-2"></i>Bekræft og fortsæt
                    </button>
                    <a href="elev_login.php" class="block text-center text-sm font-semibold text-slate-400 transition hover:text-white">Tilbage til login</a>
                </form>
            </section>
        <?php else: ?>
            <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1.05fr_0.95fr]">
                <section class="relative isolate flex min-h-[420px] flex-col justify-between overflow-hidden rounded-[2rem] bg-gradient-to-br from-slate-950 via-blue-950 to-indigo-900 p-8 text-white shadow-2xl md:p-10">
                    <div class="absolute -right-24 -top-24 -z-10 h-80 w-80 rounded-full bg-cyan-400/20 blur-3xl"></div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.25em] text-blue-200">Elevportal · IT-Udlån</p>
                        <h1 class="mt-6 max-w-lg text-4xl font-black leading-tight md:text-5xl">Dit udstyr. Dine lån. Ét samlet overblik.</h1>
                        <p class="mt-5 max-w-lg leading-7 text-blue-100">Log ind for at følge dine aktive lån, se afleveringsfrister og registrere, når du afleverer udstyr.</p>
                    </div>
                    <div class="mt-10 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <i class="fa-solid fa-laptop mb-3 text-xl text-cyan-300"></i>
                            <p class="font-bold">Hold styr på dine lån</p>
                            <p class="mt-1 text-sm text-blue-100">Status og frister samlet ét sted.</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <i class="fa-solid fa-shield-halved mb-3 text-xl text-cyan-300"></i>
                            <p class="font-bold">Sikkert login</p>
                            <p class="mt-1 text-sm text-blue-100">Login bekræftes med en kode på e-mail.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-[2rem] border border-slate-200 bg-white p-7 shadow-xl shadow-slate-900/5 md:p-9">
                    <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-50 text-2xl text-blue-700"><i class="fa-solid fa-right-to-bracket"></i></div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-700">Velkommen tilbage</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-900">Log ind på elevportalen</h2>
                    <p class="mb-6 mt-2 text-sm leading-6 text-slate-500">Brug dit elevnummer og den adgangskode, du selv har oprettet.</p>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                        <input type="hidden" name="action" value="log_ind">
                        <label class="block text-sm font-semibold text-slate-700">Elevnummer
                            <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3.5 text-slate-900 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="elevnummer" autocomplete="username" required>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Adgangskode
                            <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3.5 text-slate-900 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" type="password" name="password" autocomplete="current-password" required>
                        </label>
                        <button class="w-full rounded-xl bg-blue-600 px-5 py-3.5 font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-200" type="submit">Log ind</button>
                    </form>

                    <details class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <summary class="cursor-pointer text-sm font-bold text-slate-700">Har du glemt adgangskoden?</summary>
                        <form method="post" class="mt-4 space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= studentH(studentCsrfToken()) ?>">
                            <input type="hidden" name="action" value="anmod_om_ny_adgangskode">
                            <label class="block text-sm font-semibold text-slate-700">Elevnummer
                                <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-slate-900" name="elevnummer" required>
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">Registreret e-mail
                                <input class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-slate-900" type="email" name="email" required>
                            </label>
                            <button class="w-full rounded-xl bg-slate-800 px-5 py-3 font-bold text-white hover:bg-slate-900" type="submit">Send nulstillingslink</button>
                        </form>
                    </details>
                </section>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($isDedicatedAuthPage || is_array($student)): ?>
    <?php require __DIR__ . '/support_widget.php'; ?>
<?php endif; ?>
</body>
</html>
