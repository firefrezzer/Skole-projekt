<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/php_config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/profile_helpers.php';
require_once __DIR__ . '/support_service.php';

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectTo(string $view = 'dashboard'): void
{
    header('Location: index.php?view=' . rawurlencode($view));
    exit;
}

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function flash(string $category, string $message): void
{
    $_SESSION['flashes'][] = ['category' => $category, 'message' => $message];
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireValidPost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Metoden er ikke tilladt.');
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals(csrfToken(), $submittedToken)) {
        http_response_code(400);
        exit('Formularen er udløbet. Gå tilbage, genindlæs siden, og prøv igen.');
    }
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function generateInventoryAssetNumber(PDO $pdo, string $prefix): string
{
    if (!in_array($prefix, ['PC', 'HW'], true)) {
        throw new InvalidArgumentException('Ugyldig type for udstyrsnummer.');
    }

    $exists = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM computere WHERE computernummer = ?)
            + (SELECT COUNT(*) FROM hardware_inventory WHERE asset_number = ?)'
    );
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $assetNumber = $prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
        $exists->execute([$assetNumber, $assetNumber]);
        if ((int) $exists->fetchColumn() === 0) {
            return $assetNumber;
        }
    }

    throw new RuntimeException('Et unikt udstyrsnummer kunne ikke oprettes. Prøv igen.');
}

$hardwareCategoryOptions = [
    'Skærm', 'Tastatur', 'Mus', 'Printer', 'Tablet', 'Projektor',
    'Headset', 'Dockingstation', 'Netværksudstyr', 'Andet',
];
$hardwareStatusLabels = [
    'pa_lager' => 'På lager',
    'i_brug' => 'I brug',
    'service' => 'Til reparation',
    'udgaaet' => 'Udgået',
];

function acquireComputerCapacityLock(PDO $pdo): void
{
    $result = $pdo->query("SELECT GET_LOCK('skole_udlaan_capacity', 10)")->fetchColumn();
    if ((int) $result !== 1) {
        throw new RuntimeException('Computerkapaciteten er optaget. Prøv igen om et øjeblik.');
    }
}

function assertCapacityAvailable(PDO $pdo, string $startDate, string $endDate, int $requested): void
{
    $totalComputers = (int) $pdo->query('SELECT COUNT(*) FROM computere WHERE archived_at IS NULL')->fetchColumn();
    if ($totalComputers < 1) {
        throw new RuntimeException('Der er ingen computere i systemet.');
    }

    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    if ($start->diff($end)->days > 365) {
        throw new RuntimeException('En reservation eller et udlån kan højst vare 366 dage.');
    }

    $loanCount = $pdo->prepare(
        'SELECT COUNT(*) FROM udlaan
         WHERE udlaansdato <= ?
           AND (
               (afleveret = 0 AND udloebsdato >= ?)
               OR (afleveret = 1 AND DATE(afleveringsdato) >= ?)
           )'
    );
    $reservationCount = $pdo->prepare(
        "SELECT COALESCE(SUM(antal_computere), 0) FROM computer_reservationer
         WHERE status = 'aktiv' AND startdato <= ? AND slutdato >= ?"
    );

    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $date = $day->format('Y-m-d');
        $loanCount->execute([$date, $date, $date]);
        $activeLoans = (int) $loanCount->fetchColumn();
        $reservationCount->execute([$date, $date]);
        $reservedComputers = (int) $reservationCount->fetchColumn();
        if ($activeLoans + $reservedComputers + $requested > $totalComputers) {
            throw new RuntimeException(
                'Der er ikke nok ledige computere i hele den valgte periode. Kontrollér andre udlån og reservationer.'
            );
        }
    }
}

$currentAdminUsername = (string) ($_SESSION['admin_username'] ?? '');
$canManageAdminPermissions = strcasecmp($currentAdminUsername, NIKOLAJ_ADMIN_USERNAME) === 0;
$canApproveAdminRequests = adminCanManageApplications($currentAdminUsername);
$view = $_GET['view'] ?? 'dashboard';
$allowedViews = ['dashboard', 'udlaan', 'computere', 'brugere', 'undervisere', 'reservationer', 'blacklist', 'tidligere_laan', 'admin_requests', 'admins'];
if (!is_string($view) || !in_array($view, $allowedViews, true)) {
    $view = 'dashboard';
}
if ($view === 'admin_requests' && !$canApproveAdminRequests) {
    redirectTo('dashboard');
}

$pdo = null;
$databaseError = null;
$openSupportCount = 0;
try {
    $pdo = database();
    ensureLoanApprovalTable($pdo);
    ensureAdminRegistrationTable($pdo);
    ensureAdminProfileTable($pdo);
    ensureSupportTables($pdo);
    ensureHardwareInventoryTable($pdo);
    ensureComputerSerialNumberColumn($pdo);
    ensureComputerArchivedAtColumn($pdo);
    ensureStudentCprColumn($pdo);
    expireIdleSupportTickets($pdo);
    $openSupportCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM support_tickets WHERE status = 'open'"
    )->fetchColumn();
} catch (PDOException $exception) {
    error_log('Databaseforbindelse fejlede: ' . $exception->getMessage());
    $databaseError = 'Kunne ikke forbinde til databasen. Kontrollér databaseindstillingerne i php_config.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidPost();
    $action = postString('action');
    if ($pdo === null) {
        flash('error', $databaseError ?? 'Databaseforbindelsen er ikke tilgængelig.');
        redirectTo($view);
    }

    try {
        if ($action === 'save_admin_profile') {
            $displayName = postString('profile_name');
            if (!isValidProfileDisplayName($displayName)) {
                throw new RuntimeException('Profilnavnet skal være mellem 1 og 80 tegn.');
            }

            $animation = postString('profile_animation');
            $background = postString('profile_background');
            if (!array_key_exists($animation, profileAnimationOptions()) ||
                !array_key_exists($background, profileBackgroundOptions())) {
                throw new RuntimeException('Vælg en gyldig profilanimation og baggrund.');
            }

            $gifData = readProfileGifUpload('profile_gif');
            $removeGif = ($_POST['remove_profile_gif'] ?? '') === '1';
            if ($gifData !== null && $removeGif) {
                throw new RuntimeException('Vælg enten en ny GIF eller fjern det nuværende billede.');
            }

            $profileKey = strtolower($currentAdminUsername);
            if ($gifData !== null) {
                $profileUpdate = $pdo->prepare(
                    'INSERT INTO admin_profiles (username_key, display_name, animation, background, avatar_gif)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                         animation = VALUES(animation), background = VALUES(background),
                         avatar_gif = VALUES(avatar_gif), updated_at = CURRENT_TIMESTAMP'
                );
                $profileUpdate->bindValue(1, $profileKey);
                $profileUpdate->bindValue(2, $displayName);
                $profileUpdate->bindValue(3, $animation);
                $profileUpdate->bindValue(4, $background);
                $profileUpdate->bindValue(5, $gifData, PDO::PARAM_LOB);
                $profileUpdate->execute();
            } elseif ($removeGif) {
                $pdo->prepare(
                    'INSERT INTO admin_profiles (username_key, display_name, animation, background, avatar_gif)
                     VALUES (?, ?, ?, ?, NULL)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                         animation = VALUES(animation), background = VALUES(background),
                         avatar_gif = NULL, updated_at = CURRENT_TIMESTAMP'
                )->execute([$profileKey, $displayName, $animation, $background]);
            } else {
                $pdo->prepare(
                    'INSERT INTO admin_profiles (username_key, display_name, animation, background)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                         animation = VALUES(animation), background = VALUES(background),
                         updated_at = CURRENT_TIMESTAMP'
                )->execute([$profileKey, $displayName, $animation, $background]);
            }

            flash('success', 'Din profil er opdateret.');
            redirectTo($view);
        }

        if ($action === 'update_admin_application_permission') {
            if (!$canManageAdminPermissions) {
                throw new RuntimeException('Kun Nikolaj må ændre, hvem der kan se adminansøgninger.');
            }

            $adminUsername = postString('admin_username');
            $permissionValue = postString('allow');
            if (!in_array($permissionValue, ['0', '1'], true)) {
                throw new RuntimeException('Den valgte rettighed er ugyldig.');
            }
            setAdminApplicationPermission($adminUsername, $permissionValue === '1');
            flash(
                'success',
                $permissionValue === '1'
                    ? 'Adgang til adminansøgninger er givet til ' . $adminUsername . '.'
                    : 'Adgang til adminansøgninger er fjernet fra ' . $adminUsername . '.'
            );
            redirectTo('admin_requests');
        }

        if ($action === 'approve_admin_request' || $action === 'reject_admin_request') {
            if (!$canApproveAdminRequests) {
                throw new RuntimeException('Du har ikke rettighed til at behandle adminansøgninger.');
            }

            $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
            if ($requestId === false || $requestId === null || $requestId < 1) {
                throw new RuntimeException('Adminansøgningen har et ugyldigt ID.');
            }

            $pdo->beginTransaction();
            $requestQuery = $pdo->prepare(
                'SELECT id, username, email, password_hash
                 FROM admin_registrations WHERE id = ? FOR UPDATE'
            );
            $requestQuery->execute([$requestId]);
            $adminRequest = $requestQuery->fetch();
            if (!$adminRequest) {
                throw new RuntimeException('Adminansøgningen findes ikke længere.');
            }

            if ($action === 'approve_admin_request') {
                $accountKey = strtolower((string) $adminRequest['username']);
                $existingAccount = adminCredentialStore()[$accountKey] ?? null;
                if (is_array($existingAccount)) {
                    $sameAccount = isset($existingAccount['username'], $existingAccount['email'], $existingAccount['password_hash']) &&
                        is_string($existingAccount['username']) &&
                        is_string($existingAccount['email']) &&
                        is_string($existingAccount['password_hash']) &&
                        strcasecmp($existingAccount['username'], (string) $adminRequest['username']) === 0 &&
                        strcasecmp($existingAccount['email'], (string) $adminRequest['email']) === 0 &&
                        hash_equals($existingAccount['password_hash'], (string) $adminRequest['password_hash']);
                    if (!$sameAccount) {
                        throw new RuntimeException('Brugernavnet er allerede knyttet til en anden admin-konto.');
                    }
                } else {
                    saveAdminAccount(
                        (string) $adminRequest['username'],
                        (string) $adminRequest['email'],
                        (string) $adminRequest['password_hash']
                    );
                }
            }

            $deleteRequest = $pdo->prepare('DELETE FROM admin_registrations WHERE id = ?');
            $deleteRequest->execute([$requestId]);
            if ($deleteRequest->rowCount() !== 1) {
                throw new RuntimeException('Adminansøgningen kunne ikke fjernes fra køen.');
            }
            $pdo->commit();

            $decision = $action === 'approve_admin_request' ? 'approved' : 'rejected';
            try {
                sendAdminApplicationStatusEmail(
                    (string) $adminRequest['email'],
                    (string) $adminRequest['username'],
                    $decision
                );
                flash(
                    'success',
                    $decision === 'approved'
                        ? 'Admin-kontoen ' . $adminRequest['username'] . ' er godkendt, og besked er sendt på e-mail.'
                        : 'Adminansøgningen er afvist, og besked er sendt på e-mail.'
                );
            } catch (Throwable $mailException) {
                error_log('Statusmail for adminansøgning kunne ikke sendes: ' . $mailException->getMessage());
                flash(
                    'error',
                    $decision === 'approved'
                        ? 'Admin-kontoen er godkendt, men beskedmailen kunne ikke sendes. Giv ansøgeren besked manuelt.'
                        : 'Ansøgningen er afvist, men beskedmailen kunne ikke sendes.'
                );
            }
            redirectTo('admin_requests');
        }

        if ($action === 'aflever') {
            $loanId = filter_var($_POST['laan_id'] ?? null, FILTER_VALIDATE_INT);
            if ($loanId === false || $loanId === null) {
                throw new RuntimeException('Udlånet har et ugyldigt ID.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT u.computernummer, u.elevnummer, u.udloebsdato,
                        b.navn, b.email, c.fabrikat, c.model, c.mus
                 FROM udlaan u
                 JOIN brugere b ON b.elevnummer = u.elevnummer
                 JOIN computere c ON c.computernummer = u.computernummer
                 WHERE u.id = ? AND u.afleveret = 0
                 FOR UPDATE'
            );
            $statement->execute([$loanId]);
            $loan = $statement->fetch();
            if (!$loan) {
                throw new RuntimeException('Udlånet blev ikke fundet eller er allerede afleveret.');
            }
            $token = bin2hex(random_bytes(24));
            $expiresAt = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
            $approveInsert = $pdo->prepare(
                'INSERT INTO udlaan_godkendelser (loan_id, group_id, status, token, expires_at)
                 VALUES (?, NULL, "pending", ?, ?)
                 ON DUPLICATE KEY UPDATE group_id = NULL, status = "pending", token = VALUES(token), expires_at = VALUES(expires_at), response_at = NULL, response_note = NULL, renewed_until = NULL'
            );
            $approveInsert->execute([$loanId, $token, $expiresAt]);
            $pdo->prepare('UPDATE udlaan SET afleveret = 1, afleveringsdato = NOW() WHERE id = ?')
                ->execute([$loanId]);
            $pdo->prepare("UPDATE computere SET status = 'tilgængelig' WHERE computernummer = ?")
                ->execute([$loan['computernummer']]);
            $lateReturns = $pdo->prepare(
                'SELECT COUNT(*) FROM udlaan
                 WHERE elevnummer = ? AND afleveret = 1
                   AND afleveringsdato IS NOT NULL AND DATE(afleveringsdato) > udloebsdato'
            );
            $lateReturns->execute([$loan['elevnummer']]);
            $lateCount = (int) $lateReturns->fetchColumn();
            if ($lateCount >= LATE_RETURN_BLACKLIST_THRESHOLD) {
                $blacklistReason = 'Automatisk: ' . $lateCount . ' forsinkede afleveringer (grænse: ' .
                    LATE_RETURN_BLACKLIST_THRESHOLD . ').';
                $pdo->prepare(
                    'UPDATE brugere SET blacklisted = 1, blacklist_reason = ? WHERE elevnummer = ?'
                )->execute([$blacklistReason, $loan['elevnummer']]);
                try {
                    sendBlacklistNotificationEmail($loan, $blacklistReason);
                } catch (Throwable $mailException) {
                    error_log('Blacklist-mail kunne ikke sendes til ' . $loan['email'] . ': ' . $mailException->getMessage());
                }
            }
            $returnDate = $pdo->prepare('SELECT afleveringsdato FROM udlaan WHERE id = ?');
            $returnDate->execute([$loanId]);
            $loan['afleveringsdato'] = $returnDate->fetchColumn();
            $pdo->commit();
            $returnMessage = $lateCount >= LATE_RETURN_BLACKLIST_THRESHOLD
                ? 'Udstyret er afleveret og venter på elevens bekræftelse. Låneren er sat på blacklist efter mindst ' .
                    LATE_RETURN_BLACKLIST_THRESHOLD . ' forsinkede afleveringer.'
                : 'Udstyret er registreret som afleveret. Eleven skal godkende afleveringen via mailen.';
            try {
                sendReturnApprovalRequestEmail($loan, $token, $expiresAt);
                flash('success', $returnMessage . ' En godkendelsesmail er sendt til eleven.');
            } catch (Throwable $mailException) {
                error_log('Administrator registrerede aflevering, men godkendelsesmail fejlede: ' . $mailException->getMessage());
                flash('error', $returnMessage . ' Godkendelsesmailen kunne ikke sendes; kontrollér elevens e-mail og mailopsætningen.');
            }
            redirectTo('dashboard');
        }

        if ($action === 'aflever_flere') {
            $submittedLoanIds = $_POST['loan_ids'] ?? null;
            if (!is_array($submittedLoanIds)) {
                throw new RuntimeException('Vælg mindst ét aktivt lån.');
            }

            $loanIds = [];
            foreach ($submittedLoanIds as $submittedLoanId) {
                if (!is_string($submittedLoanId) && !is_int($submittedLoanId)) {
                    throw new RuntimeException('Et af de valgte lån har et ugyldigt ID.');
                }
                $loanId = filter_var($submittedLoanId, FILTER_VALIDATE_INT);
                if ($loanId === false || $loanId === null || $loanId < 1) {
                    throw new RuntimeException('Et af de valgte lån har et ugyldigt ID.');
                }
                $loanIds[$loanId] = $loanId;
            }
            $loanIds = array_values($loanIds);
            if ($loanIds === [] || count($loanIds) > 25) {
                throw new RuntimeException('Vælg mellem 1 og 25 aktive lån ad gangen.');
            }
            sort($loanIds, SORT_NUMERIC);

            $placeholders = implode(', ', array_fill(0, count($loanIds), '?'));
            $pdo->beginTransaction();
            $loanQuery = $pdo->prepare(
                'SELECT u.id, u.elevnummer, u.computernummer, u.udloebsdato,
                        b.navn, b.email, c.fabrikat, c.model, c.mus
                 FROM udlaan u
                 JOIN brugere b ON b.elevnummer = u.elevnummer
                 JOIN computere c ON c.computernummer = u.computernummer
                 WHERE u.id IN (' . $placeholders . ') AND u.afleveret = 0
                 FOR UPDATE'
            );
            $loanQuery->execute($loanIds);
            $loansToReturn = $loanQuery->fetchAll();
            if (count($loansToReturn) !== count($loanIds)) {
                throw new RuntimeException('Et eller flere valgte lån er ikke længere aktive. Opdatér siden, og prøv igen.');
            }

            $studentNumber = (string) $loansToReturn[0]['elevnummer'];
            foreach ($loansToReturn as $loan) {
                if ((string) $loan['elevnummer'] !== $studentNumber) {
                    throw new RuntimeException('Vælg kun udlån, der tilhører den samme elev.');
                }
            }

            $groupToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
            $pdo->prepare(
                'INSERT INTO udlaan_godkendelsesgrupper (token, status, expires_at)
                 VALUES (?, "pending", ?)'
            )->execute([$groupToken, $expiresAt]);
            $groupId = (int) $pdo->lastInsertId();

            $approvalInsert = $pdo->prepare(
                'INSERT INTO udlaan_godkendelser (loan_id, group_id, status, token, expires_at)
                 VALUES (?, ?, "pending", ?, ?)
                 ON DUPLICATE KEY UPDATE
                    group_id = VALUES(group_id), status = "pending", token = VALUES(token),
                    expires_at = VALUES(expires_at), response_at = NULL,
                    response_note = NULL, renewed_until = NULL'
            );
            $markReturned = $pdo->prepare(
                'UPDATE udlaan SET afleveret = 1, afleveringsdato = NOW() WHERE id = ? AND afleveret = 0'
            );
            $releaseComputer = $pdo->prepare(
                "UPDATE computere SET status = 'tilgængelig' WHERE computernummer = ?"
            );
            foreach ($loansToReturn as $loan) {
                $approvalInsert->execute([
                    $loan['id'],
                    $groupId,
                    bin2hex(random_bytes(32)),
                    $expiresAt,
                ]);
                $markReturned->execute([$loan['id']]);
                if ($markReturned->rowCount() !== 1) {
                    throw new RuntimeException('Et af de valgte lån ændrede sig under registreringen. Prøv igen.');
                }
                $releaseComputer->execute([$loan['computernummer']]);
            }

            $lateReturns = $pdo->prepare(
                'SELECT COUNT(*) FROM udlaan
                 WHERE elevnummer = ? AND afleveret = 1
                   AND afleveringsdato IS NOT NULL AND DATE(afleveringsdato) > udloebsdato'
            );
            $lateReturns->execute([$studentNumber]);
            $lateCount = (int) $lateReturns->fetchColumn();
            $blacklistReason = null;
            if ($lateCount >= LATE_RETURN_BLACKLIST_THRESHOLD) {
                $blacklistReason = 'Automatisk: ' . $lateCount . ' forsinkede afleveringer (grænse: ' .
                    LATE_RETURN_BLACKLIST_THRESHOLD . ').';
                $pdo->prepare(
                    'UPDATE brugere SET blacklisted = 1, blacklist_reason = ? WHERE elevnummer = ?'
                )->execute([$blacklistReason, $studentNumber]);
            }
            $pdo->commit();

            $returnMessage = count($loansToReturn) . ' lån er registreret som afleveret og venter på elevens godkendelse.';
            if ($blacklistReason !== null) {
                $returnMessage .= ' Eleven er også sat på blacklist efter gentagne forsinkede afleveringer.';
            }
            try {
                sendReturnApprovalGroupEmail($loansToReturn, $groupToken, $expiresAt);
                flash('success', $returnMessage . ' Én samlet godkendelsesmail er sendt til eleven.');
            } catch (Throwable $mailException) {
                error_log('Samlet afleveringsgodkendelse blev registreret, men mail fejlede: ' . $mailException->getMessage());
                flash('error', $returnMessage . ' Godkendelsesmailen kunne ikke sendes; kontrollér e-mail og mailopsætning.');
            }
            if ($blacklistReason !== null) {
                try {
                    sendBlacklistNotificationEmail($loansToReturn[0], $blacklistReason);
                } catch (Throwable $mailException) {
                    error_log('Blacklist-mail kunne ikke sendes efter samlet aflevering: ' . $mailException->getMessage());
                }
            }
            redirectTo('dashboard');
        }

        if ($action === 'godkend_aflevering_admin') {
            $loanId = filter_var($_POST['laan_id'] ?? null, FILTER_VALIDATE_INT);
            if ($loanId === false || $loanId === null) {
                throw new RuntimeException('Udlånet har et ugyldigt ID.');
            }
            $pdo->beginTransaction();
            $approvalQuery = $pdo->prepare(
                'SELECT group_id FROM udlaan_godkendelser WHERE loan_id = ? AND status = "pending" FOR UPDATE'
            );
            $approvalQuery->execute([$loanId]);
            $approval = $approvalQuery->fetch();
            if ($approval === false) {
                $pdo->rollBack();
                throw new RuntimeException('Der er ingen afventende godkendelse for dette lån.');
            }
            $pdo->prepare(
                'UPDATE udlaan_godkendelser
                 SET status = "accepted", response_at = NOW(), response_note = "Admin godkendte afleveringen"
                 WHERE loan_id = ? AND status = "pending"'
            )->execute([$loanId]);
            if (!empty($approval['group_id'])) {
                $pdo->prepare(
                    'UPDATE udlaan_godkendelser
                     SET status = "accepted", response_at = NOW(), response_note = "Admin godkendte afleveringen"
                     WHERE group_id = ? AND status = "pending"'
                )->execute([$approval['group_id']]);
                $pdo->prepare(
                    'UPDATE udlaan_godkendelsesgrupper
                     SET status = "accepted", response_at = NOW(), response_note = "Admin godkendte afleveringen"
                     WHERE id = ? AND status = "pending"'
                )->execute([$approval['group_id']]);
            }
            $pdo->commit();
            flash('success', 'Afleveringen er godkendt af dig som admin.');
            redirectTo('tidligere_laan');
        }

        if ($action === 'slet_udlaan') {
            $loanId = filter_var($_POST['laan_id'] ?? null, FILTER_VALIDATE_INT);
            if ($loanId === false || $loanId === null) {
                throw new RuntimeException('Udlånet har et ugyldigt ID.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT computernummer FROM udlaan WHERE id = ? AND afleveret = 0 FOR UPDATE'
            );
            $statement->execute([$loanId]);
            $loan = $statement->fetch();
            if (!$loan) {
                throw new RuntimeException('Udlånet blev ikke fundet eller er allerede lukket.');
            }
            $pdo->prepare('DELETE FROM udlaan WHERE id = ?')->execute([$loanId]);
            $pdo->prepare("UPDATE computere SET status = 'tilgængelig' WHERE computernummer = ?")
                ->execute([$loan['computernummer']]);
            $pdo->commit();
            flash('success', 'Udlånet er slettet, og computeren er nu tilgængelig igen.');
            redirectTo('dashboard');
        }

        if ($action === 'ny_udlaan') {
            $studentNumber = postString('elevnummer');
            $computerNumber = postString('computernummer');
            $loanDate = postString('udlaansdato');
            $dueDate = postString('udloebsdato');
            if ($studentNumber === '' || $computerNumber === '' ||
                !isValidDate($loanDate) ||
                !isValidDate($dueDate) ||
                $dueDate < $loanDate) {
                throw new RuntimeException('Udfyld alle felter, og kontrollér udlåns- og afleveringsdatoerne.');
            }

            acquireComputerCapacityLock($pdo);
            $confirmation = null;
            try {
                $pdo->beginTransaction();
                $student = $pdo->prepare(
                    'SELECT elevnummer, navn, email, blacklisted FROM brugere WHERE elevnummer = ? FOR UPDATE'
                );
                $student->execute([$studentNumber]);
                $studentRecord = $student->fetch();
                $computer = $pdo->prepare(
                    "SELECT computernummer, fabrikat, model, mus
                    FROM computere
                    WHERE computernummer = ? AND archived_at IS NULL AND status = 'tilgængelig'
                    FOR UPDATE"
                );
                $computer->execute([$computerNumber]);
                $computerRecord = $computer->fetch();
                if (!$studentRecord) {
                    throw new RuntimeException('Elevnummeret findes ikke i elevregisteret.');
                }
                if ((int) $studentRecord['blacklisted'] === 1) {
                    throw new RuntimeException('Låneren er på blacklist og kan ikke få et nyt udlån.');
                }
                if (!$computerRecord) {
                    throw new RuntimeException('Computeren findes ikke eller er ikke ledig.');
                }
                assertCapacityAvailable($pdo, $loanDate, $dueDate, 1);
                $pdo->prepare(
                    'INSERT INTO udlaan (elevnummer, computernummer, udlaansdato, udloebsdato, afleveret)
                     VALUES (?, ?, ?, ?, 0)'
                )->execute([$studentNumber, $computerNumber, $loanDate, $dueDate]);
                $pdo->prepare("UPDATE computere SET status = 'udlånt' WHERE computernummer = ?")
                    ->execute([$computerNumber]);
                $pdo->commit();
                $confirmation = array_merge($studentRecord, $computerRecord, [
                    'udlaansdato' => $loanDate,
                    'udloebsdato' => $dueDate,
                ]);
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->query("SELECT RELEASE_LOCK('skole_udlaan_capacity')");
            }
            try {
                sendLoanConfirmationEmail($confirmation);
                flash('success', 'Udlånet er oprettet, og kvitteringen er sendt til ' . $confirmation['email'] . '.');
            } catch (Throwable $mailException) {
                error_log('Udlån oprettet, men kvitteringsmail fejlede: ' . $mailException->getMessage());
                flash(
                    'error',
                    'Udlånet er gemt, men kvitteringsmailen kunne ikke sendes til ' .
                        $confirmation['email'] . ': ' . $mailException->getMessage()
                );
            }
            redirectTo('udlaan');
        }

        if ($action === 'ny_underviser') {
            $name = postString('navn');
            $email = postString('email');
            $department = postString('afdeling');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Udfyld underviserens navn og en gyldig e-mailadresse.');
            }
            $pdo->prepare('INSERT INTO undervisere (navn, email, afdeling) VALUES (?, ?, ?)')
                ->execute([$name, $email, $department]);
            flash('success', 'Underviseren er registreret.');
            redirectTo('undervisere');
        }

        if ($action === 'ny_reservation') {
            $teacherId = filter_var($_POST['underviser_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['antal_computere'] ?? null, FILTER_VALIDATE_INT);
            $startDate = postString('startdato');
            $endDate = postString('slutdato');
            $purpose = postString('formaal');
            if ($teacherId === false || $teacherId === null || $quantity === false || $quantity === null ||
                $quantity < 1 || !isValidDate($startDate) || !isValidDate($endDate) || $endDate < $startDate) {
                throw new RuntimeException('Kontrollér underviser, antal computere og reservationsperiode.');
            }

            acquireComputerCapacityLock($pdo);
            try {
                $pdo->beginTransaction();
                $teacher = $pdo->prepare('SELECT id FROM undervisere WHERE id = ?');
                $teacher->execute([$teacherId]);
                if (!$teacher->fetch()) {
                    throw new RuntimeException('Underviseren findes ikke.');
                }
                assertCapacityAvailable($pdo, $startDate, $endDate, $quantity);
                $pdo->prepare(
                    "INSERT INTO computer_reservationer
                        (underviser_id, antal_computere, startdato, slutdato, formaal, status)
                     VALUES (?, ?, ?, ?, ?, 'aktiv')"
                )->execute([$teacherId, $quantity, $startDate, $endDate, $purpose]);
                $pdo->commit();
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->query("SELECT RELEASE_LOCK('skole_udlaan_capacity')");
            }
            flash('success', 'Computerne er reserveret for den valgte periode.');
            redirectTo('reservationer');
        }

        if ($action === 'annuller_reservation') {
            $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
            if ($reservationId === false || $reservationId === null) {
                throw new RuntimeException('Reservationen har et ugyldigt ID.');
            }
            $update = $pdo->prepare(
                "UPDATE computer_reservationer SET status = 'annulleret'
                 WHERE id = ? AND status = 'aktiv'"
            );
            $update->execute([$reservationId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Reservationen findes ikke eller er allerede annulleret.');
            }
            flash('success', 'Reservationen er annulleret.');
            redirectTo('reservationer');
        }

        if ($action === 'fjern_blacklist') {
            $studentNumber = postString('elevnummer');
            $update = $pdo->prepare(
                "UPDATE brugere SET blacklisted = 0, blacklist_reason = NULL WHERE elevnummer = ?"
            );
            $update->execute([$studentNumber]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Låneren blev ikke fundet eller var allerede fjernet fra blacklist.');
            }
            flash('success', 'Låneren er fjernet fra blacklist.');
            redirectTo('blacklist');
        }

        if ($action === 'tilfoej_blacklist') {
            $studentNumber = postString('elevnummer');
            $reason = postString('blacklist_reason');
            if ($studentNumber === '' || $reason === '') {
                throw new RuntimeException('Vælg en elev, og skriv en årsag til blacklist.');
            }
            $update = $pdo->prepare(
                'UPDATE brugere SET blacklisted = 1, blacklist_reason = ?
                 WHERE elevnummer = ? AND blacklisted = 0'
            );
            $update->execute([$reason, $studentNumber]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Eleven blev ikke fundet eller er allerede på blacklist.');
            }
            flash('success', 'Eleven er sat på blacklist og kan ikke få nye udlån.');
            redirectTo('blacklist');
        }

        if ($action === 'ny_computer') {
            $computerNumber = generateInventoryAssetNumber($pdo, 'PC');
            $brand = postString('fabrikat');
            $model = postString('model');
            $mouse = postString('mus');
            $serialNumber = postString('serial_number');
            if ($brand === '' || $model === '' || $mouse === '' || strlen($serialNumber) > 480) {
                throw new RuntimeException('Udfyld alle felter for computeren.');
            }
            $pdo->prepare(
                "INSERT INTO computere (computernummer, fabrikat, model, serial_number, mus, status)
                 VALUES (?, ?, ?, ?, ?, 'tilgængelig')"
            )->execute([$computerNumber, $brand, $model, $serialNumber, $mouse]);
            flash('success', 'Computeren er oprettet med udstyrsnummer ' . $computerNumber . '.');
            redirectTo('computere');
        }

        if ($action === 'slet_computer') {
            $computerNumber = postString('computernummer');
            if ($computerNumber === '') {
                throw new RuntimeException('Vælg en computer, der skal slettes.');
            }

            acquireComputerCapacityLock($pdo);
            try {
                $pdo->beginTransaction();
                $computer = $pdo->prepare(
                    'SELECT computernummer FROM computere
                     WHERE computernummer = ? AND archived_at IS NULL
                     FOR UPDATE'
                );
                $computer->execute([$computerNumber]);
                if ($computer->fetch() === false) {
                    throw new RuntimeException('Computeren blev ikke fundet eller er allerede fjernet fra inventaret.');
                }

                $activeLoans = $pdo->prepare(
                    'SELECT COUNT(*) FROM udlaan WHERE computernummer = ? AND afleveret = 0'
                );
                $activeLoans->execute([$computerNumber]);
                if ((int) $activeLoans->fetchColumn() > 0) {
                    throw new RuntimeException('Computeren kan ikke fjernes fra inventaret, mens den stadig er udlånt.');
                }

                $pendingApprovals = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM udlaan_godkendelser g
                     JOIN udlaan u ON u.id = g.loan_id
                     WHERE u.computernummer = ? AND g.status = 'pending'"
                );
                $pendingApprovals->execute([$computerNumber]);
                if ((int) $pendingApprovals->fetchColumn() > 0) {
                    throw new RuntimeException('Computeren kan ikke fjernes, mens en afleveringsgodkendelse venter.');
                }

                $archive = $pdo->prepare(
                    'UPDATE computere SET archived_at = NOW()
                     WHERE computernummer = ? AND archived_at IS NULL'
                );
                $archive->execute([$computerNumber]);
                if ($archive->rowCount() !== 1) {
                    throw new RuntimeException('Computeren blev ikke fjernet. Genindlæs siden, og prøv igen.');
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('skole_udlaan_capacity')");
            }

            flash('success', 'Computeren er fjernet fra inventaret. Lånehistorikken er bevaret.');
            redirectTo('computere');
        }

        if ($action === 'ny_hardware') {
            $category = postString('category');
            $name = postString('name');
            $brand = postString('brand');
            $model = postString('model');
            $serialNumber = postString('serial_number');
            $status = postString('status');
            $notes = postString('notes');
            if (!in_array($category, $hardwareCategoryOptions, true) ||
                !array_key_exists($status, $hardwareStatusLabels) ||
                $name === '' || strlen($name) > 600 ||
                strlen($brand) > 400 || strlen($model) > 400 ||
                strlen($serialNumber) > 480 || strlen($notes) > 12000) {
                throw new RuntimeException('Kontrollér udstyrstype, navn og de øvrige felter.');
            }

            $assetNumber = generateInventoryAssetNumber($pdo, 'HW');
            $pdo->prepare(
                'INSERT INTO hardware_inventory
                    (asset_number, category, name, brand, model, serial_number, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $assetNumber,
                $category,
                $name,
                $brand,
                $model,
                $serialNumber,
                $status,
                $notes === '' ? null : $notes,
            ]);
            flash('success', 'Udstyret er registreret med udstyrsnummer ' . $assetNumber . '.');
            redirectTo('computere');
        }

        if ($action === 'slet_hardware') {
            $assetNumber = postString('asset_number');
            if ($assetNumber === '') {
                throw new RuntimeException('Vælg hardware, der skal slettes.');
            }

            $delete = $pdo->prepare('DELETE FROM hardware_inventory WHERE asset_number = ?');
            $delete->execute([$assetNumber]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Hardwaren blev ikke fundet eller er allerede slettet.');
            }

            flash('success', 'Hardwaren er slettet fra inventaret.');
            redirectTo('computere');
        }

        if ($action === 'ny_bruger') {
            $studentNumber = postString('elevnummer');
            $name = postString('navn');
            $cpr = postString('cpr');
            $address = postString('adresse');
            $city = postString('postnummer_by');
            $email = postString('email');
            $className = postString('stamklasse');
            if ($studentNumber === '' || $name === '' || $className === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Udfyld elevnummer, navn, klasse og en gyldig e-mailadresse.');
            }
            if ($cpr !== '' && !preg_match('/^\d{6}-?\d{4}$/', $cpr)) {
                throw new RuntimeException('CPR-nummeret skal være 10 cifre (fx 010190-1234).');
            }
            $cpr = $cpr === '' ? null : preg_replace('/[^0-9]/', '', $cpr);
            if ($cpr !== null) {
                $cpr = substr($cpr, 0, 6) . '-' . substr($cpr, 6, 4);
            }

            $existingStudent = $pdo->prepare(
                'SELECT elevnummer, email FROM brugere WHERE elevnummer = ? OR email = ? LIMIT 1'
            );
            $existingStudent->execute([$studentNumber, $email]);
            $existingStudentRow = $existingStudent->fetch();
            if ($existingStudentRow !== false) {
                if (strcasecmp((string) $existingStudentRow['elevnummer'], $studentNumber) === 0) {
                    throw new RuntimeException('Der findes allerede en elev med dette elevnummer. Brug et nyt elevnummer eller redigér den eksisterende elev.');
                }
                throw new RuntimeException('Der findes allerede en elev med denne e-mailadresse. Brug en anden e-mail eller redigér den eksisterende elev.');
            }

            $pdo->prepare(
                'INSERT INTO brugere (elevnummer, navn, cpr, adresse, postnummer_by, email, stamklasse)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$studentNumber, $name, $cpr, $address, $city, $email, $className]);
            flash('success', 'Eleven er oprettet.');
            redirectTo('brugere');
        }

        if ($action === 'slet_elev') {
            $studentNumber = postString('elevnummer');
            if ($studentNumber === '') {
                throw new RuntimeException('Vælg en elev, der skal slettes.');
            }

            $pdo->beginTransaction();
            $activeLoanCheck = $pdo->prepare('SELECT COUNT(*) FROM udlaan WHERE elevnummer = ? AND afleveret = 0');
            $activeLoanCheck->execute([$studentNumber]);
            if ((int) $activeLoanCheck->fetchColumn() > 0) {
                throw new RuntimeException('Eleven kan ikke slettes, mens der er aktive lån. Aflever først udstyret eller kontakt skolens IT-administrator.');
            }

            $deleteLoanHistory = $pdo->prepare('DELETE FROM udlaan WHERE elevnummer = ?');
            $deleteLoanHistory->execute([$studentNumber]);

            $delete = $pdo->prepare('DELETE FROM brugere WHERE elevnummer = ?');
            $delete->execute([$studentNumber]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Eleven blev ikke fundet eller kunne ikke slettes.');
            }
            $pdo->commit();

            flash('success', 'Eleven er nu slettet fra elevregisteret.');
            redirectTo('brugere');
        }

        if ($action === 'slet_underviser') {
            $teacherId = postString('underviser_id');
            if ($teacherId === '') {
                throw new RuntimeException('Vælg en underviser, der skal slettes.');
            }

            $teacherIdInt = filter_var($teacherId, FILTER_VALIDATE_INT);
            if ($teacherIdInt === false || $teacherIdInt === null) {
                throw new RuntimeException('Ugyldig underviser-ID.');
            }

            $pdo->beginTransaction();
            $deleteReservations = $pdo->prepare('DELETE FROM computer_reservationer WHERE underviser_id = ?');
            $deleteReservations->execute([$teacherIdInt]);
            $delete = $pdo->prepare('DELETE FROM undervisere WHERE id = ?');
            $delete->execute([$teacherIdInt]);
            if ($delete->rowCount() !== 1) {
                $pdo->rollBack();
                throw new RuntimeException('Underviseren blev ikke fundet eller kunne ikke slettes.');
            }
            $pdo->commit();

            flash('success', 'Underviseren og tilhørende reservationer er slettet.');
            redirectTo('undervisere');
        }

        if ($action === 'slet_admin') {
            if (!$canManageAdminPermissions) {
                throw new RuntimeException('Kun Nikolaj kan slette administratorer.');
            }

            $adminNumber = postString('slet_elevnummer');
            if ($adminNumber === '') {
                throw new RuntimeException('Vælg en administrator, der skal slettes.');
            }

            if (strcasecmp($adminNumber, $currentAdminUsername) === 0) {
                throw new RuntimeException('Du kan ikke slette dig selv.');
            }

            $delete = $pdo->prepare('DELETE FROM brugere WHERE elevnummer = ? AND rolle = \'admin\'');
            $delete->execute([$adminNumber]);

            // Brug strtolower fordi username_key gemmes med lowercase ved oprettelse
            $adminStatement = $pdo->prepare('DELETE FROM admin_registrations WHERE LOWER(username_key) = ?');
            $adminStatement->execute([strtolower($adminNumber)]);

            // Brug strtolower fordi username_key gemmes med lowercase ved oprettelse
            $adminProfileStatement = $pdo->prepare('DELETE FROM admin_profiles WHERE username_key = ?');
            $adminProfileStatement->execute([strtolower($adminNumber)]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Administratoren blev ikke fundet eller kunne ikke slettes.');
            }

            flash('success', 'Administratoren er slettet fra databasen.');
            redirectTo('brugere');
        }

        if ($action === 'slet_admin_bulk') {
            if (!$canManageAdminPermissions) {
                throw new RuntimeException('Kun Nikolaj kan slette administratorer.');
            }

            $adminNumbers = $_POST['slet_admin_nr'] ?? [];
            if (!is_array($adminNumbers) || count($adminNumbers) === 0) {
                throw new RuntimeException('Vælg mindst én administrator, der skal slettes.');
            }

            $slettet = 0;
            $fejlet = 0;
            foreach ($adminNumbers as $adminNumber) {
                if (strcasecmp($adminNumber, $currentAdminUsername) === 0) {
                    $fejlet++;
                    continue;
                }
                $delete = $pdo->prepare('DELETE FROM brugere WHERE elevnummer = ? AND rolle = \'admin\'');
                $delete->execute([$adminNumber]);

            // Brug strtolower fordi username_key gemmes med lowercase ved oprettelse
            $adminStatement = $pdo->prepare('DELETE FROM admin_registrations WHERE LOWER(username_key) = ?');
            $adminStatement->execute([strtolower($adminNumber)]);

            // Brug strtolower fordi username_key gemmes med lowercase ved oprettelse
            $adminProfileStatement = $pdo->prepare('DELETE FROM admin_profiles WHERE username_key = ?');
            $adminProfileStatement->execute([strtolower($adminNumber)]);

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
                        error_log(' kunde ikke læse admin-credentials fil: ' . $credentialsFile);
                    } else {
                        $credentials = json_decode($content, true);
                        if ($credentials === null && json_last_err() !== JSON_ERR_NONE) {
                            error_log('Fejl ved parsing af admin-credentials JSON: ' . json_last_err_msg());
                            $credentials = [];
                        }
                        if (!is_array($credentials)) {
                            $credentials = [];
                        }
                        
                        $key = strtolower(trim($adminNumber));
                        
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
                                error_log('Admin ' . $adminNumber . ' fjernet fra credentials fil');
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

            if ($delete->rowCount() === 1) {
                    $slettet++;
                } else {
                    $fejlet++;
                }
            }

            if ($slettet > 0) {
                flash('success', $slettet . ' administrator(er) er slettet fra databasen.');
            }
            if ($fejlet > 0) {
                flash('error', $fejlet . ' administrator(er) kunne ikke slettes.');
            }
            redirectTo('admins');
        }

        if ($action === 'ny_admin') {
            if (!$canManageAdminPermissions) {
                throw new RuntimeException('Kun Nikolaj kan oprette administratorer.');
            }

            $elevnummer = postString('elevnummer');
            $navn = postString('navn');
            $email = postString('email');
            if ($elevnummer === '' || $navn === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Udfyld brugernavn, navn og en gyldig e-mailadresse.');
            }

            $temporaryPassword = 'Temp' . strtoupper(bin2hex(random_bytes(4))) . '!';
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Adgangskoden kunne ikke gemmes sikkert.');
            }

            $pdo->beginTransaction();
            $existing = $pdo->prepare('SELECT elevnummer FROM brugere WHERE elevnummer = ? OR email = ? LIMIT 1');
            $existing->execute([$elevnummer, $email]);
            if ($existing->fetch()) {
                throw new RuntimeException('Der findes allerede en bruger med dette brugernavn eller denne e-mail.');
            }

            $pdo->prepare(
                'INSERT INTO brugere (elevnummer, navn, email, stamklasse, rolle, adgangskode_hash, blacklisted)
                 VALUES (?, ?, ?, ?, \'admin\', ?, 0)'
            )->execute([$elevnummer, $navn, $email, 'ADMIN', $passwordHash]);
            $pdo->commit();

            flash('success', 'Administratoren ' . h($elevnummer) . ' er oprettet. Midlertidig adgangskode: ' . h($temporaryPassword) . '.');
            redirectTo('admins');
        }

        if ($action === 'set_elev_password') {
            $studentNumber = postString('elevnummer');
            $password = $_POST['password'] ?? '';
            $passwordConfirmation = $_POST['password_confirmation'] ?? '';
            if (!is_string($password) || !is_string($passwordConfirmation) || $studentNumber === '') {
                throw new RuntimeException('Vælg en elev og indtast en ny adgangskode.');
            }
            if (strlen($password) < 6 || $password !== $passwordConfirmation) {
                throw new RuntimeException('Adgangskoden skal være mindst 6 tegn, og begge felter skal være ens.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Adgangskoden kunne ikke gemmes sikkert.');
            }
            $update = $pdo->prepare('UPDATE brugere SET adgangskode_hash = ? WHERE elevnummer = ?');
            $update->execute([$passwordHash, $studentNumber]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Eleven blev ikke fundet.');
            }
            flash('success', 'Elevens adgangskode er blevet opdateret.');
            redirectTo('brugere');
        }

        throw new RuntimeException('Ukendt handling.');
    } catch (Throwable $exception) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Handling fejlede (' . $action . '): ' . $exception->getMessage());

        $message = $exception instanceof PDOException
            ? 'Handlingen kunne ikke gemmes. Kontrollér data og databaseindstillinger.'
            : $exception->getMessage();

        if ($exception instanceof PDOException) {
            $dbMessage = $exception->getMessage();
            if (stripos($dbMessage, 'foreign key') !== false ||
                stripos($dbMessage, 'integrity constraint') !== false ||
                stripos($dbMessage, 'cannot delete or update a parent row') !== false) {
                $message = match ($action) {
                    'slet_elev' => 'Eleven har tilknyttede lån eller andre registreringer. Aflever udstyret eller ryd de relaterede poster, før eleven slettes.',
                    'slet_computer' => 'Computeren har tilknyttede lån eller andre registreringer og kan ikke slettes.',
                    'slet_hardware' => 'Hardwaren har tilknyttede registreringer og kan ikke slettes.',
                    default => 'Posten har tilknyttede registreringer og kan ikke slettes.',
                };
            }
        }

        flash('error', $message);
        redirectTo($view);
    }
}

$flashes = $_SESSION['flashes'] ?? [];
unset($_SESSION['flashes']);
$profileSettings = [
    'display_name' => $currentAdminUsername,
    'animation' => 'none',
    'background' => 'blue',
    'has_avatar' => false,
];
$stats = ['total' => 0, 'available' => 0, 'active' => 0, 'overdue' => 0];
$loans = $previousLoans = $pendingApprovals = $computers = $hardwareAssets = $users = $teachers = $reservations = $blacklistedUsers = $studentDeliveryStats = $adminRequests = $adminAccounts = $admins = [];
$monthlyLabels = $monthlyLoanCounts = [];
$returnTimeliness = ['on_time' => 0, 'late' => 0];
if ($pdo !== null) {
    try {
        $profileQuery = $pdo->prepare(
            'SELECT display_name, animation, background, avatar_gif IS NOT NULL AS has_avatar
             FROM admin_profiles WHERE username_key = ?'
        );
        $profileQuery->execute([strtolower($currentAdminUsername)]);
        $savedProfile = $profileQuery->fetch();
        if ($savedProfile !== false) {
            $profileSettings['display_name'] = (string) $savedProfile['display_name'];
            $profileSettings['animation'] = (string) $savedProfile['animation'];
            $profileSettings['background'] = (string) $savedProfile['background'];
            $profileSettings['has_avatar'] = (bool) $savedProfile['has_avatar'];
        }

        $stats['total'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM computere WHERE archived_at IS NULL'
        )->fetchColumn();
        $stats['available'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM computere WHERE archived_at IS NULL AND status = 'tilgængelig'"
        )->fetchColumn();
        $stats['active'] = (int) $pdo->query('SELECT COUNT(*) FROM udlaan WHERE afleveret = 0')->fetchColumn();
        $stats['overdue'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM udlaan WHERE afleveret = 0 AND udloebsdato < CURDATE()'
        )->fetchColumn();
        $loans = $pdo->query(
            'SELECT u.id, b.navn, b.stamklasse, b.email, b.blacklisted, u.elevnummer,
                    u.computernummer, c.mus, u.udlaansdato, u.udloebsdato,
                    DATEDIFF(CURDATE(), u.udloebsdato) AS dage_over_tid
             FROM udlaan u
             JOIN brugere b ON u.elevnummer = b.elevnummer
             JOIN computere c ON c.computernummer = u.computernummer
             WHERE u.afleveret = 0 ORDER BY u.udloebsdato'
        )->fetchAll();
        $previousLoans = $pdo->query(
            'SELECT u.id, b.navn, b.stamklasse, b.email, u.elevnummer,
                    u.computernummer, u.udlaansdato, u.udloebsdato, u.afleveringsdato,
                    CASE WHEN u.afleveringsdato IS NOT NULL AND DATE(u.afleveringsdato) <= u.udloebsdato
                         THEN "til tiden" ELSE "forsinket" END AS aflevering_status
             FROM udlaan u
             JOIN brugere b ON u.elevnummer = b.elevnummer
             WHERE u.afleveret = 1
             ORDER BY u.afleveringsdato DESC, u.id DESC'
        )->fetchAll();
        $pendingApprovals = $pdo->query(
            'SELECT g.loan_id, g.group_id, g.expires_at, g.status,
                    u.elevnummer, u.computernummer, u.afleveringsdato,
                    b.navn, b.email, b.stamklasse,
                    DATEDIFF(g.expires_at, NOW()) AS dage_tilbage
             FROM udlaan_godkendelser g
             JOIN udlaan u ON u.id = g.loan_id
             JOIN brugere b ON b.elevnummer = u.elevnummer
             WHERE g.status = "pending"
             ORDER BY g.expires_at ASC'
        )->fetchAll();
        $computers = $pdo->query(
            'SELECT c.*
             FROM computere c
             WHERE c.archived_at IS NULL
             ORDER BY c.computernummer'
        )->fetchAll();
        $hardwareAssets = $pdo->query('SELECT * FROM hardware_inventory ORDER BY asset_number')->fetchAll();
        $users = $pdo->query(
            'SELECT elevnummer, navn, cpr, adresse, postnummer_by, email, stamklasse, rolle, blacklisted,
                    (adgangskode_hash IS NOT NULL) AS konto_oprettet
             FROM brugere WHERE rolle != \'admin\' ORDER BY navn'
        )->fetchAll();
        $admins = $pdo->query(
            'SELECT elevnummer, navn, email, stamklasse, rolle, blacklisted
             FROM brugere WHERE rolle = \'admin\' ORDER BY navn'
        )->fetchAll();
        $teachers = $pdo->query('SELECT * FROM undervisere ORDER BY navn')->fetchAll();
        $reservations = $pdo->query(
            'SELECT r.*, t.navn AS underviser_navn, t.email AS underviser_email
             FROM computer_reservationer r
             JOIN undervisere t ON t.id = r.underviser_id
             ORDER BY r.startdato DESC, r.id DESC'
        )->fetchAll();
        $blacklistedUsers = $pdo->query(
            'SELECT b.elevnummer, b.navn, b.email, b.blacklist_reason,
                    COUNT(u.id) AS forsinkede_afleveringer
             FROM brugere b
             LEFT JOIN udlaan u ON u.elevnummer = b.elevnummer
                AND u.afleveret = 1 AND u.afleveringsdato IS NOT NULL
                AND DATE(u.afleveringsdato) > u.udloebsdato
             WHERE b.blacklisted = 1
             GROUP BY b.elevnummer, b.navn, b.email, b.blacklist_reason
             ORDER BY b.navn'
        )->fetchAll();
        $studentDeliveryStats = $pdo->query(
            'SELECT b.elevnummer, b.navn, b.stamklasse, b.email,
                    SUM(CASE WHEN u.afleveret = 1 AND u.afleveringsdato IS NOT NULL
                        AND DATE(u.afleveringsdato) <= u.udloebsdato THEN 1 ELSE 0 END) AS afleveringer_til_tid,
                    SUM(CASE WHEN u.afleveret = 1 AND u.afleveringsdato IS NOT NULL
                        AND DATE(u.afleveringsdato) > u.udloebsdato THEN 1 ELSE 0 END) AS afleveringer_forsinket,
                    COUNT(u.id) AS totale_loan
             FROM brugere b
             LEFT JOIN udlaan u ON u.elevnummer = b.elevnummer
             GROUP BY b.elevnummer, b.navn, b.stamklasse, b.email
             ORDER BY b.navn'
        )->fetchAll();
        if ($view === 'admin_requests' && $canApproveAdminRequests) {
            $adminRequests = $pdo->query(
                'SELECT id, username, email, created_at
                 FROM admin_registrations ORDER BY created_at ASC, id ASC'
            )->fetchAll();
            $adminAccounts = adminCredentialStore();
        }

        $firstMonth = new DateTimeImmutable('first day of this month');
        $firstMonth = $firstMonth->modify('-5 months');
        $monthlyLabels = [];
        for ($offset = 0; $offset < 6; $offset++) {
            $month = $firstMonth->modify("+$offset months");
            $monthlyLabels[] = $month->format('M Y');
            $monthlyLoanCounts[$month->format('Y-m')] = 0;
        }
        $monthlyStatement = $pdo->prepare(
            "SELECT DATE_FORMAT(udlaansdato, '%Y-%m') AS maaned, COUNT(*) AS antal
             FROM udlaan WHERE udlaansdato >= ? GROUP BY DATE_FORMAT(udlaansdato, '%Y-%m')"
        );
        $monthlyStatement->execute([$firstMonth->format('Y-m-01')]);
        foreach ($monthlyStatement->fetchAll() as $monthCount) {
            if (array_key_exists($monthCount['maaned'], $monthlyLoanCounts)) {
                $monthlyLoanCounts[$monthCount['maaned']] = (int) $monthCount['antal'];
            }
        }
        $monthlyLoanCounts = array_values($monthlyLoanCounts);

        $returnTimeliness = $pdo->query(
            'SELECT
                COALESCE(SUM(CASE WHEN DATE(afleveringsdato) <= udloebsdato THEN 1 ELSE 0 END), 0) AS on_time,
                COALESCE(SUM(CASE WHEN DATE(afleveringsdato) > udloebsdato THEN 1 ELSE 0 END), 0) AS late
             FROM udlaan WHERE afleveret = 1 AND afleveringsdato IS NOT NULL'
        )->fetch();
        $returnTimeliness = [
            'on_time' => (int) $returnTimeliness['on_time'],
            'late' => (int) $returnTimeliness['late'],
        ];
    } catch (PDOException $exception) {
        error_log('Kunne ikke hente data: ' . $exception->getMessage());
        $databaseError = 'Kunne ikke hente data fra databasen. Kontrollér at tabellerne er oprettet.';
    }
}

$availableComputers = array_filter(
    $computers,
    static fn (array $computer): bool => $computer['status'] === 'tilgængelig'
);
$today = date('Y-m-d');
$sideNavigation = [
    'dashboard' => ['Oversigt', 'fa-chart-pie'],
    'udlaan' => ['Nyt udlån', 'fa-right-left'],
    'computere' => ['Computere & hardware', 'fa-laptop'],
    'brugere' => ['Elevregister', 'fa-users'],
    'undervisere' => ['Undervisere', 'fa-chalkboard-user'],
    'reservationer' => ['Reservationer', 'fa-calendar-check'],
    'blacklist' => ['Blacklist', 'fa-user-slash'],
    'tidligere_laan' => ['Tidligere udlån', 'fa-clock-rotate-left'],
];
if ($canApproveAdminRequests) {
    $sideNavigation['admin_requests'] = ['Adminansøgninger', 'fa-user-shield'];
}
if ($canManageAdminPermissions) {
    $sideNavigation['admins'] = ['Administratorer', 'fa-user-shield'];
}
$currentViewLabel = $sideNavigation[$view][0] ?? 'Oversigt';
$profileAnimationClassNames = profileAnimationClasses();
$profileBackgroundClassNames = profileBackgroundClasses();
$profileAnimationClass = $profileAnimationClassNames[$profileSettings['animation']] ?? '';
$profileBackgroundClass = $profileBackgroundClassNames[$profileSettings['background']] ?? $profileBackgroundClassNames['blue'];
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IT-Udlån Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <?php if ($view === 'dashboard'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <style>
        [x-cloak] { display: none !important; }
        .glass-shell {
            background: rgba(15, 23, 42, 0.26);
            border: 1px solid rgba(148, 163, 184, 0.18);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.18);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.64);
            border: 1px solid rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 16px 50px rgba(15, 23, 42, 0.08);
        }
        .dark .glass-card {
            background: rgba(15, 23, 42, 0.72);
            border-color: rgba(148, 163, 184, 0.16);
            box-shadow: 0 20px 60px rgba(2, 6, 23, 0.35);
        }
        .stat-card {
            position: relative;
            overflow: hidden;
            transition: transform 180ms ease, box-shadow 180ms ease;
        }
        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(59,130,246,0.18), transparent 50%, rgba(168,85,247,0.14));
            opacity: 0;
            transition: opacity 180ms ease;
            z-index: 0;
        }
        .stat-card::after {
            content: "";
            position: absolute;
            z-index: 0;
            bottom: -55%;
            left: -20%;
            width: 140%;
            height: 75%;
            border: 1px solid rgba(96, 165, 250, 0.14);
            border-radius: 48% 52% 0 0 / 45% 55% 0 0;
            background: radial-gradient(ellipse at 50% 0%, rgba(59, 130, 246, 0.12), transparent 70%);
            pointer-events: none;
            animation: stat-wave 12s ease-in-out infinite alternate;
        }
        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(59, 130, 246, 0.15);
        }
        .stat-card:hover::before {
            opacity: 1;
        }
        .admin-hero {
            position: relative;
            isolation: isolate;
        }
        .admin-hero::before,
        .admin-hero::after {
            content: "";
            position: absolute;
            z-index: -1;
            left: -8%;
            width: 116%;
            height: 230px;
            border-top: 1px solid rgba(191, 219, 254, 0.24);
            border-radius: 48% 52% 0 0 / 24% 32% 0 0;
            pointer-events: none;
        }
        .admin-hero::before {
            bottom: -170px;
            background: radial-gradient(ellipse at 50% 0%, rgba(56, 189, 248, 0.22), transparent 68%);
            animation: hero-wave 14s ease-in-out infinite alternate;
        }
        .admin-hero::after {
            bottom: -190px;
            left: -12%;
            width: 124%;
            border-color: rgba(199, 210, 254, 0.18);
            background: radial-gradient(ellipse at 55% 0%, rgba(129, 140, 248, 0.16), transparent 70%);
            animation: hero-wave 19s ease-in-out -5s infinite alternate-reverse;
        }
        @keyframes hero-wave {
            from { transform: translateX(-2%) rotate(-1deg); }
            to { transform: translateX(2%) rotate(1deg); }
        }
        @keyframes stat-wave {
            from { transform: translateX(-3%) rotate(-2deg); }
            to { transform: translateX(3%) rotate(2deg); }
        }
        .profile-menu > summary {
            list-style: none;
        }
        .profile-menu > summary::-webkit-details-marker {
            display: none;
        }
        .profile-float {
            animation: profile-float 3.8s ease-in-out infinite;
        }
        .profile-pulse {
            animation: profile-pulse 2.8s ease-in-out infinite;
        }
        .profile-glow {
            animation: profile-glow 3.2s ease-in-out infinite;
        }
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
        .glass-panel {
            background: rgba(255, 255, 255, 0.66);
            border: 1px solid rgba(148, 163, 184, 0.18);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
        }
        .dark .glass-panel {
            background: rgba(15, 23, 42, 0.7);
            border-color: rgba(148, 163, 184, 0.14);
            box-shadow: 0 18px 55px rgba(2, 6, 23, 0.4);
        }
        .dark {
            color-scheme: dark;
        }
        .dark .text-slate-950,
        .dark .text-slate-900,
        .dark .text-slate-800 {
            color: #f1f5f9 !important;
        }
        .dark .text-slate-700,
        .dark .text-slate-600 {
            color: #cbd5e1 !important;
        }
        .dark .text-slate-500 {
            color: #a7b4c6 !important;
        }
        .dark .text-slate-400 {
            color: #94a3b8 !important;
        }
        .dark [class~="bg-white"],
        .dark [class~="bg-white/60"],
        .dark [class~="bg-white/80"],
        .dark [class~="bg-white/90"],
        .dark [class~="bg-slate-50"],
        .dark [class~="bg-slate-100"] {
            background-color: #111c2f !important;
        }
        .dark [class~="border-slate-100"],
        .dark [class~="border-slate-200"],
        .dark [class~="border-slate-300"] {
            border-color: #334155 !important;
        }
        .dark input,
        .dark select,
        .dark textarea {
            color: #f1f5f9;
            background-color: #0b1220;
            border-color: #475569;
        }
        .dark input::placeholder,
        .dark textarea::placeholder {
            color: #94a3b8;
        }
        .dark option {
            color: #f1f5f9;
            background-color: #0b1220;
        }
        @media (prefers-reduced-motion: reduce) {
            .admin-hero::before,
            .admin-hero::after,
            .stat-card::after,
            .profile-float,
            .profile-pulse,
            .profile-glow {
                animation: none;
            }
        }

        .dark table {
            background-color: #0f172a !important;
        }
        .dark table th {
            background-color: #0f172a !important;
            color: #f1f5f9 !important;
            border-color: #334155 !important;
        }
        .dark table td {
            background-color: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }
        .dark table tr:hover td {
            background-color: #1e293b !important;
        }
        .dark .bg-white,
        .dark .bg-slate-50,
        .dark .bg-slate-100 {
            background-color: #0f172a !important;
        }
        .dark .bg-white\/5 {
            background-color: rgba(15, 23, 42, 0.5) !important;
        }
        .dark .bg-white\/10 {
            background-color: rgba(15, 23, 42, 0.1) !important;
        }
        .dark .hover\:bg-slate-100:hover {
            background-color: #1e293b !important;
        }
        .dark .hover\:bg-white\/10:hover {
            background-color: rgba(255, 255, 255, 0.05) !important;
        }
    </style>
</head>
<body x-data="{ darkMode: (() => { try { return localStorage.getItem('darkMode') === 'true'; } catch (e) { return false; } })(), mobileNavOpen: false }"
      x-init="$watch('darkMode', value => { try { localStorage.setItem('darkMode', value); } catch (e) {} })"
      :class="{ 'dark': darkMode }"
      class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(59,130,246,0.18),transparent_30%),radial-gradient(circle_at_bottom_right,_rgba(99,102,241,0.16),transparent_28%),linear-gradient(135deg,_#f8fafc_0%,_#eef2ff_42%,_#e2e8f0_100%)] text-slate-800 dark:bg-[radial-gradient(circle_at_top_left,_rgba(96,165,250,0.15),transparent_30%),radial-gradient(circle_at_bottom_right,_rgba(129,140,248,0.18),transparent_28%),linear-gradient(135deg,_#020817_0%,_#0f172a_45%,_#111827_100%)] dark:text-slate-100">
<div class="relative min-h-screen md:flex">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_20%_20%,rgba(59,130,246,0.12),transparent_25%),radial-gradient(circle_at_80%_10%,rgba(168,85,247,0.14),transparent_30%),radial-gradient(circle_at_50%_90%,rgba(14,165,233,0.12),transparent_25%)]"></div>

    <!-- Mobil topbar -->
    <div class="sticky top-0 z-40 flex items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 md:hidden">
        <button type="button" @click="mobileNavOpen = true" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700 transition hover:bg-slate-200 active:scale-95 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700" aria-label="Åbn menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="flex min-w-0 flex-1 flex-col items-center">
            <span class="truncate text-sm font-black tracking-tight text-slate-900 dark:text-white"><?= h($currentViewLabel) ?></span>
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">IT-Udlån Pro</span>
        </div>
        <a href="brugermanual.php" target="_blank" rel="noopener" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 transition hover:bg-indigo-100 active:scale-95 dark:bg-indigo-950/40 dark:text-indigo-300" aria-label="Brugermanual">
            <i class="fa-solid fa-book"></i>
        </a>
    </div>

    <!-- Mobil overlay -->
    <div x-show="mobileNavOpen" x-cloak @click="mobileNavOpen = false"
         class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm md:hidden"></div>

    <aside class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] overflow-y-auto border-b border-slate-200 bg-white/95 p-5 shadow-xl backdrop-blur transition-transform duration-200 ease-in-out dark:border-slate-800 dark:bg-slate-900/95 md:sticky md:top-0 md:z-auto md:h-screen md:min-h-screen md:w-72 md:max-w-none md:shrink-0 md:translate-x-0 md:overflow-y-auto md:border-b-0 md:border-r md:bg-white/80 md:shadow-sm md:dark:bg-slate-900/80"
           :class="{ 'translate-x-0': mobileNavOpen, '-translate-x-full': !mobileNavOpen }">
        <div class="mb-4 flex items-center justify-between md:hidden">
            <span class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">Menu</span>
            <button type="button" @click="mobileNavOpen = false" class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300" aria-label="Luk menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <a href="index.php" class="mb-8 flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-lg text-white shadow-lg shadow-blue-600/25"><i class="fa-solid fa-laptop-code"></i></span>
            <span><span class="block text-lg font-black tracking-tight text-slate-900 dark:text-white">IT-Udlån Pro</span><span class="text-xs text-slate-500">Skolens udstyrsportal</span></span>
        </a>
        <p class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Administration</p>
        <nav class="grid grid-cols-2 gap-2 md:grid-cols-1">
            <?php foreach ($sideNavigation as $key => $label): ?>
                <a class="flex items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold transition active:scale-[0.98] <?= $view === $key ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/20' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' ?>"
                   href="index.php?view=<?= h($key) ?>" @click="mobileNavOpen = false"><i class="fa-solid <?= h($label[1]) ?> w-5 text-center"></i><?= h($label[0]) ?></a>
            <?php endforeach; ?>
        </nav>
        <a href="admin_support.php" class="mt-4 flex items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3.5 text-sm font-bold text-blue-800 transition hover:bg-blue-100 active:scale-[0.98] dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200 dark:hover:bg-blue-950/70" @click="mobileNavOpen = false">
            <span class="flex items-center gap-3"><i class="fa-solid fa-headset w-5 text-center"></i>Elevsupport</span>
            <?php if ($openSupportCount > 0): ?>
                <span class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-black text-white"><?= h((string) $openSupportCount) ?></span>
            <?php endif; ?>
        </a>
        <a href="elev.php" class="mt-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3.5 text-sm font-bold text-emerald-800 transition hover:bg-emerald-100 active:scale-[0.98] dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300" @click="mobileNavOpen = false">
            <i class="fa-solid fa-id-card w-5 text-center"></i> Åbn elevportal
        </a>
        <a href="brugermanual.php" target="_blank" rel="noopener" class="mt-4 flex items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3.5 text-sm font-bold text-indigo-800 transition hover:bg-indigo-100 active:scale-[0.98] dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300" @click="mobileNavOpen = false">
            <i class="fa-solid fa-book w-5 text-center"></i> Brugermanual
        </a>
        <div class="mt-8 rounded-2xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
            <p class="text-xs font-bold text-blue-900 dark:text-blue-200">IT-udstyr</p>
            <p class="mt-1 text-xs leading-5 text-blue-800/80 dark:text-blue-300/80">Hold styr på lån, reservationer og afleveringer ét sted.</p>
        </div>
        <button type="button" @click="darkMode = !darkMode"
                class="mt-4 flex w-full items-center gap-2 rounded-xl bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"><i class="fa-solid fa-circle-half-stroke"></i> Skift tema</button>
    </aside>

    <main class="min-w-0 flex-1 bg-slate-50/70 p-4 dark:bg-slate-950 md:p-8 xl:p-10">
        <div class="mb-5 flex justify-end">
            <details class="profile-menu relative z-50">
                <summary class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-white/90 px-3 py-2 shadow-sm transition hover:border-blue-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-900/90">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br <?= h($profileBackgroundClass) ?> text-lg font-black text-white ring-2 ring-white shadow <?= h($profileAnimationClass) ?>">
                        <?php if ($profileSettings['has_avatar']): ?>
                            <img src="admin_profile_image.php" alt="" class="h-full w-full object-cover">
                        <?php else: ?>
                            <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <?php endif; ?>
                    </span>
                    <span class="min-w-0 text-left">
                        <span class="block max-w-[9rem] truncate text-sm font-bold text-slate-900 dark:text-white"><?= h($profileSettings['display_name']) ?></span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Adminprofil</span>
                    </span>
                    <i class="fa-solid fa-chevron-down ml-1 text-xs text-slate-400" aria-hidden="true"></i>
                </summary>
                <div class="absolute right-0 mt-3 max-h-[80vh] w-80 max-w-[90vw] overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
                    <div class="bg-gradient-to-br <?= h($profileBackgroundClass) ?> p-5 text-white">
                        <div class="flex items-center gap-4">
                            <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-white/80 bg-white/20 text-2xl shadow-lg <?= h($profileAnimationClass) ?>">
                                <?php if ($profileSettings['has_avatar']): ?>
                                    <img src="admin_profile_image.php" alt="" class="h-full w-full object-cover">
                                <?php else: ?>
                                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                            <div class="min-w-0">
                                <p class="break-words text-lg font-black"><?= h($profileSettings['display_name']) ?></p>
                                <p class="mt-1 truncate text-sm text-white/80"><?= h($currentAdminUsername) ?></p>
                            </div>
                        </div>
                    </div>
                    <form method="post" enctype="multipart/form-data" action="index.php?view=<?= h($view) ?>" class="space-y-4 p-5">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="save_admin_profile">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Visningsnavn
                            <input type="text" name="profile_name" maxlength="80" required value="<?= h($profileSettings['display_name']) ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-white">
                        </label>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Profilanimation
                            <select name="profile_animation" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-white">
                                <?php foreach (profileAnimationOptions() as $profileValue => $profileLabel): ?>
                                    <option value="<?= h($profileValue) ?>" <?= $profileSettings['animation'] === $profileValue ? 'selected' : '' ?>><?= h($profileLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Profilbaggrund
                            <select name="profile_background" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-white">
                                <?php foreach (profileBackgroundOptions() as $profileValue => $profileLabel): ?>
                                    <option value="<?= h($profileValue) ?>" <?= $profileSettings['background'] === $profileValue ? 'selected' : '' ?>><?= h($profileLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            GIF-profilbillede
                            <input type="file" name="profile_gif" accept="image/gif,.gif" class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:font-semibold file:text-blue-700 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-200 dark:file:bg-slate-800 dark:file:text-blue-200">
                            <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Kun GIF · højst 512 KB og 512 × 512 pixels</span>
                        </label>
                        <?php if ($profileSettings['has_avatar']): ?>
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="remove_profile_gif" value="1" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                Fjern nuværende GIF
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700">
                            Gem profil
                        </button>
                    </form>
                    <div class="border-t border-slate-200 px-5 pb-5 dark:border-slate-700">
                        <a href="admin_skift_adgangskode.php" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-200">
                            <i class="fa-solid fa-key" aria-hidden="true"></i>Skift min adgangskode
                        </a>
                    </div>
                </div>
            </details>
        </div>
        <header class="admin-hero mb-8 overflow-hidden rounded-[28px] border border-blue-200/70 bg-gradient-to-br from-slate-950 via-blue-950 to-indigo-950 p-5 shadow-[0_20px_60px_rgba(37,99,235,0.25)] text-white md:p-8">
            <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <p class="mb-2 text-xs font-bold uppercase tracking-[0.22em] text-blue-200/90">Skole · IT-afdeling</p>
                    <h2 class="text-3xl font-black tracking-tight text-white md:text-4xl xl:text-5xl"><?= h([
                        'dashboard' => 'Dashboard & oversigt',
                        'udlaan' => 'Registrer nyt udlån',
                        'computere' => 'Computere & hardware',
                        'brugere' => 'Elevregister',
                        'undervisere' => 'Undervisere',
                        'reservationer' => 'Computerreservationer',
                        'blacklist' => 'Blacklisted lånere',
                        'admin_requests' => 'Adminansøgninger',
                        'admins' => 'Administratorer',
                    ][$view]) ?></h2>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-blue-100/85 md:text-base">Hold styr på udstyr, lån, afleveringer og reservationer i ét moderne kontrolcenter. Administrer alle daglige opgaver hurtigt og sikkert.</p>
                    <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                        <div class="text-sm text-blue-900/80 dark:text-blue-200">
                            <p class="font-semibold">Guide: Dashboard</p>
                            <p class="text-xs mt-1">Se statistik over aktive udlån, ledige computere og elevstatus. Rykkere sendes automatisk på afleveringsdatoen.</p>
                        </div>
                    </div>
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <a href="index.php?view=udlaan" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-bold text-slate-900 shadow-lg shadow-blue-950/20 transition hover:bg-blue-50"><i class="fa-solid fa-plus"></i> Nyt udlån</a>
                        <a href="elev.php" class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-sm font-bold text-white transition hover:bg-white/10"><i class="fa-solid fa-id-card"></i> Åbn elevportal</a>
                        <a href="admin_skift_adgangskode.php" class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-sm font-bold text-white transition hover:bg-white/10"><i class="fa-solid fa-key"></i> Skift adgangskode</a>
                        <form method="post" action="admin.php" class="inline-block">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-sm font-bold text-white transition hover:bg-white/10"><i class="fa-solid fa-right-from-bracket"></i> Log ud</button>
                        </form>
                    </div>
                </div>

                <div class="relative w-full max-w-sm rounded-3xl border border-white/15 bg-white/10 p-4 shadow-xl backdrop-blur-sm">
                    <div class="absolute -right-4 -top-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-400 text-lg text-slate-950 shadow-lg shadow-emerald-500/30">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-100/80">Status</p>
                            <p class="mt-1 text-2xl font-black">Drift klar</p>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-500/25 text-xl text-blue-100"><i class="fa-solid fa-shield-heart"></i></span>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-slate-950/25 p-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-100/60">Udlån</p>
                            <p class="mt-2 text-xl font-black text-white"><?= h($stats['active']) ?></p>
                        </div>
                        <div class="rounded-2xl bg-slate-950/25 p-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-100/60">Ledige</p>
                            <p class="mt-2 text-xl font-black text-white"><?= h($stats['available']) ?></p>
                        </div>
                        <div class="col-span-2 min-w-0 rounded-2xl bg-slate-950/25 p-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-100/70">Afsenderadresse</p>
                            <p class="mt-1 break-all text-xs font-semibold leading-5 text-white" title="<?= h(MAIL_SENDER) ?>"><?= h(MAIL_SENDER) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($databaseError !== null): ?>
            <div class="mb-5 rounded-xl border border-rose-300 bg-rose-50 p-4 text-rose-800"><?= h($databaseError) ?></div>
        <?php endif; ?>
        <?php foreach ($flashes as $item): ?>
            <div role="alert" class="mb-3 rounded-xl border p-4 <?= $item['category'] === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-rose-300 bg-rose-50 text-rose-800' ?>">
                <?= h($item['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php if (in_array($view, ['dashboard', 'udlaan', 'brugere', 'blacklist'], true)): ?>
            <section class="mb-6 rounded-2xl border border-slate-200 bg-white/90 p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900/90">
                <label for="studentSearch" class="block text-sm font-bold text-slate-800 dark:text-slate-100">Søg efter elev</label>
                <div class="relative mt-2">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input id="studentSearch" type="search" autocomplete="off" placeholder="Navn, elevnummer, klasse eller e-mail"
                           class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm text-slate-900 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Søgningen filtrerer elevstatus, elevlister, aktive lån og elevvalg på denne side.</p>
            </section>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <div class="glass-card mb-5 rounded-2xl border border-blue-200/80 p-4 text-sm text-blue-900 shadow-[0_18px_40px_rgba(59,130,246,0.08)] dark:border-blue-900/70 dark:text-blue-100">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600/10 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200"><i class="fa-solid fa-bell"></i></span>
                    <p class="leading-6">Rykkere sendes automatisk én gang på afleveringsdatoen. Computeren skal være aktiv, og Windows skal køre den planlagte opgave.</p>
                </div>
            </div>
            <section class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <?php foreach ([
                    'Totale PC’er' => ['value' => $stats['total'], 'icon' => 'fa-desktop', 'tone' => 'from-blue-600 to-indigo-600'],
                    'Ledige PC’er' => ['value' => $stats['available'], 'icon' => 'fa-check-circle', 'tone' => 'from-emerald-500 to-teal-500'],
                    'Aktive udlån' => ['value' => $stats['active'], 'icon' => 'fa-right-left', 'tone' => 'from-violet-600 to-fuchsia-500'],
                    'Overskredne frister' => ['value' => $stats['overdue'], 'icon' => 'fa-triangle-exclamation', 'tone' => 'from-rose-500 to-orange-500'],
                ] as $label => $meta): ?>
                    <div class="stat-card glass-panel rounded-3xl p-4 sm:p-5">
                        <div class="relative z-10 flex items-start justify-between gap-2">
                            <div>
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 sm:text-sm"><?= h($label) ?></p>
                                <p class="mt-2 text-2xl font-black tracking-tight text-slate-900 dark:text-white sm:mt-3 sm:text-3xl"><?= h($meta['value']) ?></p>
                            </div>
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br text-base text-white shadow-lg shadow-slate-900/10 sm:h-12 sm:w-12 sm:rounded-2xl sm:text-lg <?= h($meta['tone']) ?>">
                                <i class="fa-solid <?= h($meta['icon']) ?>"></i>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
            <section class="mb-6 grid gap-6 xl:grid-cols-2">
                <div class="glass-panel rounded-3xl p-5">
                    <h3 class="mb-4 font-bold text-slate-900 dark:text-white">Udlån pr. måned</h3>
                    <canvas id="monthlyLoansChart" aria-label="Graf over udlån pr. måned"></canvas>
                </div>
                <div class="glass-panel rounded-3xl p-5">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="font-bold text-slate-900 dark:text-white">Elevstatus</h3>
                        <span class="rounded-full bg-blue-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.18em] text-blue-800 dark:bg-blue-900/60 dark:text-blue-200">Per elev</span>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($studentDeliveryStats as $statusIndex => $student): ?>
                            <?php $lateCount = (int) ($student['afleveringer_forsinket'] ?? 0); ?>
                            <?php $onTimeCount = (int) ($student['afleveringer_til_tid'] ?? 0); ?>
                            <?php $totalLoans = max(1, (int) ($student['totale_loan'] ?? 0)); ?>
                            <?php $score = round(($onTimeCount / $totalLoans) * 100); ?>
                            <div data-student-search="<?= h($student['navn'] . ' ' . $student['elevnummer'] . ' ' . $student['stamklasse'] . ' ' . $student['email']) ?>"<?= $statusIndex >= 4 ? ' data-student-search-overflow="true" hidden' : '' ?> class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3 dark:border-slate-700 dark:bg-slate-950/60">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-bold text-slate-900 dark:text-white"><?= h($student['navn']) ?></p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400"><?= h($student['stamklasse']) ?></p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-full bg-slate-900 px-2 py-1 text-[9px] font-bold uppercase tracking-[0.18em] text-white dark:bg-slate-700">
                                            <?= h($totalLoans) ?> lån
                                        </span>
                                        <span class="rounded-full <?= $lateCount > 0 ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' ?> px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.18em]">
                                            <?= $lateCount > 0 ? 'Advarsel' : 'God status' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="mb-2 flex items-center justify-between text-[10px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                    <span>Levering</span>
                                    <span><?= h($score) ?>%</span>
                                </div>
                                <div class="mb-3 h-2.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                    <div class="h-full rounded-full bg-gradient-to-r <?= $lateCount > 0 ? 'from-rose-500 to-orange-400' : 'from-emerald-500 to-teal-500' ?>" style="width: <?= h((string) min(100, max(8, $score))) ?>%"></div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 text-xs text-slate-600 dark:text-slate-300">
                                    <div class="rounded-xl bg-white/80 p-2 dark:bg-slate-900/60">
                                        <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Til tiden</div>
                                        <div class="mt-1 text-lg font-black text-emerald-600 dark:text-emerald-300"><?= h($onTimeCount) ?></div>
                                    </div>
                                    <div class="rounded-xl bg-white/80 p-2 dark:bg-slate-900/60">
                                        <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Forsinket</div>
                                        <div class="mt-1 text-lg font-black text-rose-600 dark:text-rose-300"><?= h($lateCount) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <section class="glass-panel overflow-x-auto rounded-3xl">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200/80 p-5 dark:border-slate-800/80">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Aktive udlån</h3>
                        <p class="mt-1 text-sm text-slate-500">Vælg flere lån fra samme elev for at registrere dem samlet.</p>
                    </div>
                    <form id="bulk-return-form" method="post" class="flex flex-wrap items-center gap-3">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="aflever_flere">
                        <span id="bulk-return-status" class="text-sm text-slate-500" aria-live="polite">Ingen lån valgt</span>
                        <button id="bulk-return-submit" type="submit" disabled class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                            Registrer valgte afleveringer
                        </button>
                    </form>
                </div>
                <table class="w-full min-w-[950px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                    <tr><th class="p-4">Vælg</th><th class="p-4">Elev</th><th class="p-4">Klasse</th><th class="p-4">PC</th><th class="p-4">Mus</th><th class="p-4">Udlånt</th><th class="p-4">Afleveringsfrist</th><th class="p-4">Handling</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php if (!$loans): ?>
                        <tr><td colspan="8" class="p-8 text-center text-slate-500">Ingen aktive udlån.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($loans as $loan): ?>
                        <tr data-student-search="<?= h($loan['navn'] . ' ' . $loan['elevnummer'] . ' ' . $loan['stamklasse'] . ' ' . $loan['email']) ?>">
                            <td class="p-4">
                                <input type="checkbox" form="bulk-return-form" name="loan_ids[]" value="<?= h($loan['id']) ?>" data-return-student="<?= h($loan['elevnummer']) ?>" aria-label="Vælg lån for <?= h($loan['navn']) ?>" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            </td>
                            <td class="p-4 font-semibold">
                                <?= h($loan['navn']) ?><br><span class="text-xs text-slate-500"><?= h($loan['email']) ?></span>
                                <?php if ((int) $loan['blacklisted'] === 1): ?><br><span class="text-xs font-bold text-rose-600">BLACKLIST</span><?php endif; ?>
                            </td>
                            <td class="p-4"><?= h($loan['stamklasse']) ?></td>
                            <td class="p-4"><?= h($loan['computernummer']) ?></td>
                            <td class="p-4"><?= h($loan['mus']) ?></td>
                            <td class="p-4"><?= h($loan['udlaansdato']) ?></td>
                            <td class="p-4 <?= (int) $loan['dage_over_tid'] > 0 ? 'font-bold text-rose-600' : '' ?>">
                                <?= h($loan['udloebsdato']) ?>
                                <?php if ((int) $loan['dage_over_tid'] > 0): ?> (<?= h($loan['dage_over_tid']) ?> dage overskredet)<?php endif; ?>
                            </td>
                            <td class="p-4 space-y-2">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="aflever">
                                    <input type="hidden" name="laan_id" value="<?= h($loan['id']) ?>">
                                    <button class="w-full rounded-lg bg-emerald-600 px-3 py-2 font-semibold text-white" type="submit">Registrer aflevering</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="slet_udlaan">
                                    <input type="hidden" name="laan_id" value="<?= h($loan['id']) ?>">
                                    <button class="w-full rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-semibold text-rose-700" type="submit" onclick="return confirm('Er du sikker på, at du vil slette dette udlån? Computeren bliver straks tilgængelig igen.')">Slet lån</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'udlaan'): ?>
            <div class="grid items-start gap-6 xl:grid-cols-3">
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:p-8 xl:col-span-2">
                    <div class="mb-6 flex items-start gap-4 border-b border-slate-100 pb-5 dark:border-slate-800">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-600/10 text-xl text-blue-600 dark:text-blue-400"><i class="fa-solid fa-laptop"></i></span>
                        <div><h3 class="text-lg font-bold">Opret nyt udlån</h3><p class="mt-1 text-sm text-slate-500">Vælg en låner, udstyr og afleveringsfrist. Der sendes en e-mailkvittering automatisk.</p></div>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Nyt udlån</p>
                                <p class="text-xs mt-1">Vælg elev, computer og datoer. Eleven må ikke være blacklistet.</p>
                            </div>
                        </div>
                    </div>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="ny_udlaan">
                        <label class="block text-sm font-semibold">Vælg låner
                            <select class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="elevnummer" required>
                                <option value="">Vælg elev</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ((int) $user['blacklisted'] === 0): ?>
                                        <option value="<?= h($user['elevnummer']) ?>" data-student-search="<?= h($user['navn'] . ' ' . $user['elevnummer'] . ' ' . $user['stamklasse'] . ' ' . $user['email']) ?>"><?= h($user['navn'] . ' (' . $user['elevnummer'] . ')') ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold">Ledig computer
                            <select class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="computernummer" required>
                                <option value="">Vælg computer</option>
                                <?php foreach ($availableComputers as $computer): ?>
                                    <option value="<?= h($computer['computernummer']) ?>"><?= h($computer['computernummer'] . ' - ' . $computer['fabrikat'] . ' ' . $computer['model'] . ' · Mus: ' . $computer['mus']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="text-sm font-semibold">Udlånsdato<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="date" name="udlaansdato" value="<?= h($today) ?>" required></label>
                            <label class="text-sm font-semibold">Afleveringsfrist<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="date" name="udloebsdato" required></label>
                        </div>
                        <button class="rounded-lg bg-blue-600 px-5 py-3 font-bold text-white" type="submit">Opret udlån</button>
                    </form>
                </section>

                <aside class="overflow-hidden rounded-3xl border border-blue-200 bg-gradient-to-br from-blue-50 via-white to-indigo-50 shadow-sm dark:border-slate-700 dark:from-slate-900 dark:via-slate-900 dark:to-indigo-950/60">
                    <div class="border-b border-blue-100 p-6 dark:border-slate-700">
                        <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-600 text-lg text-white shadow-lg shadow-blue-600/20"><i class="fa-solid fa-list-check"></i></span>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Sådan foregår det</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">Et hurtigt overblik, før du opretter lånet.</p>
                    </div>
                    <div class="space-y-5 p-6">
                        <div class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-black text-blue-800 dark:bg-blue-900/70 dark:text-blue-100">1</span>
                            <div><p class="font-semibold text-slate-900 dark:text-white">Vælg låner</p><p class="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300">Eleven skal være oprettet i elevregisteret og må ikke være blacklistet.</p></div>
                        </div>
                        <div class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-black text-blue-800 dark:bg-blue-900/70 dark:text-blue-100">2</span>
                            <div><p class="font-semibold text-slate-900 dark:text-white">Vælg udstyr og datoer</p><p class="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300">Listen viser kun computere, der er ledige lige nu.</p></div>
                        </div>
                        <div class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-black text-blue-800 dark:bg-blue-900/70 dark:text-blue-100">3</span>
                            <div><p class="font-semibold text-slate-900 dark:text-white">Kvittering sendes</p><p class="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300">Eleven får en e-mail på adressen i elevregisteret, og lånet vises under aktive udlån.</p></div>
                        </div>
                    </div>
                    <div class="border-t border-blue-100 bg-white/70 p-6 dark:border-slate-700 dark:bg-slate-950/40">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">Ledige computere</span>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-black text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200"><?= count($availableComputers) ?></span>
                        </div>
                    </div>
                </aside>
            </div>
        <?php elseif ($view === 'computere'): ?>
            <section class="mb-6 rounded-2xl border border-blue-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h3 class="font-bold">Søg eller scan udstyr</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Søg på udstyrsnummer, serienummer, type, navn, fabrikat, model eller status. En USB-scanner kan skrive direkte i søgefeltet.</p>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">Søg eller scan udstyrsnummer</span>
                        <input id="inventory-search" type="search" autofocus autocomplete="off" placeholder="Søg eller scan fx PC-… / HW-…" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-blue-900/40">
                    </label>
                    <button id="inventory-camera-toggle" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 font-bold text-white transition hover:bg-indigo-700">
                        <i class="fa-solid fa-camera" aria-hidden="true"></i>Scan med kamera
                    </button>
                </div>
                <p id="inventory-result-count" class="mt-3 text-xs text-slate-500 dark:text-slate-400" aria-live="polite"></p>
                <div id="inventory-camera-panel" class="mt-4 hidden rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p id="inventory-camera-status" class="text-sm text-slate-600 dark:text-slate-300" role="status" aria-live="polite">Ret kameraet mod udstyrets stregkode.</p>
                        <button id="inventory-camera-close" type="button" class="rounded-lg px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800">Luk kamera</button>
                    </div>
                    <div id="inventory-camera-reader" class="w-full overflow-hidden rounded-xl"></div>
                    <video id="inventory-camera-video" class="max-h-72 w-full rounded-xl bg-black object-contain" playsinline muted></video>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="space-y-6">
                    <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                        <h3 class="mb-2 text-lg font-bold">Opret computer</h3>
                        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Computernummeret genereres automatisk, så det kan sættes på en stregkode.</p>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Computere</p>
                                <p class="text-xs mt-1">Opret nye computere med fabrikat, model og serienummer. Computernummer genereres automatisk.</p>
                            </div>
                        </div>
                        <form method="post" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="ny_computer">
                            <label class="block text-sm font-semibold">Udstyrsnummer
                                <input value="Genereres automatisk ved oprettelse" readonly class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-100 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            </label>
                            <label class="block text-sm font-semibold">Fabrikat<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="fabrikat" maxlength="100" required></label>
                            <label class="block text-sm font-semibold">Model<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="model" maxlength="100" required></label>
                            <label class="block text-sm font-semibold">Serienummer fra producenten (valgfrit)<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="serial_number" maxlength="120"></label>
                            <label class="block text-sm font-semibold">Mus
                                <select class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="mus" required>
                                    <option value="Ja">Ja</option><option value="Almindelig">Almindelig</option><option value="Nej">Nej</option>
                                </select>
                            </label>
                            <button class="rounded-lg bg-indigo-600 px-5 py-3 font-bold text-white" type="submit">Opret computer</button>
                        </form>
                    </section>

                    <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                        <h3 class="mb-2 text-lg font-bold">Registrer andet hardware</h3>
                        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Andet udstyr får automatisk et HW-nummer og kan søges eller scannes. Det registreres i inventaret, men ændrer ikke computerudlån.</p>
                        <form method="post" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="ny_hardware">
                            <label class="block text-sm font-semibold">Udstyrstype
                                <select name="category" required class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                                    <option value="">Vælg type</option>
                                    <?php foreach ($hardwareCategoryOptions as $category): ?>
                                        <option value="<?= h($category) ?>"><?= h($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="block text-sm font-semibold">Navn<input name="name" maxlength="150" placeholder="Fx Interaktiv skærm" required class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-sm font-semibold">Fabrikat<input name="brand" maxlength="100" class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></label>
                                <label class="block text-sm font-semibold">Model<input name="model" maxlength="100" class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></label>
                            </div>
                            <label class="block text-sm font-semibold">Serienummer<input name="serial_number" maxlength="120" class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></label>
                            <label class="block text-sm font-semibold">Status
                                <select name="status" class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                                    <?php foreach ($hardwareStatusLabels as $statusValue => $statusLabel): ?>
                                        <option value="<?= h($statusValue) ?>"><?= h($statusLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="block text-sm font-semibold">Noter<textarea name="notes" maxlength="3000" rows="2" class="mt-1 w-full rounded-lg border p-3 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></textarea></label>
                            <button class="rounded-lg bg-indigo-600 px-5 py-3 font-bold text-white" type="submit">Registrer hardware</button>
                        </form>
                    </section>
                </div>

                <div class="space-y-6 xl:col-span-2">
                    <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900">
                        <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                            <h3 class="font-bold">Computere</h3>
                            <p class="mt-1 text-xs text-slate-500"><?= count($computers) ?> registreret · computere kan vælges til udlån</p>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950"><tr><th class="p-4">PC-nr.</th><th class="p-4">Fabrikat/model</th><th class="p-4">Serienummer</th><th class="p-4">Mus</th><th class="p-4">Status</th><th class="p-4">Stregkode</th><th class="p-4">Handling</th></tr></thead>
                            <tbody id="computer-inventory-body" class="divide-y divide-slate-200 dark:divide-slate-800">
                            <?php foreach ($computers as $computer): ?>
                                <?php $computerSearch = implode(' ', [$computer['computernummer'], $computer['serial_number'], $computer['fabrikat'], $computer['model'], $computer['mus'], $computer['status']]); ?>
                                <tr data-inventory-row data-inventory-kind="computer" data-inventory-number="<?= h($computer['computernummer']) ?>" data-inventory-search="<?= h($computerSearch) ?>">
                                    <td class="whitespace-nowrap p-4 font-bold"><?= h($computer['computernummer']) ?></td>
                                    <td class="p-4"><?= h($computer['fabrikat'] . ' ' . $computer['model']) ?></td>
                                    <td class="p-4"><?= h($computer['serial_number']) ?></td>
                                    <td class="p-4"><?= h($computer['mus']) ?></td>
                                    <td class="p-4"><?= h($computer['status']) ?></td>
                                    <td class="p-4"><button type="button" data-print-asset-label data-asset-number="<?= h($computer['computernummer']) ?>" class="whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"><i class="fa-solid fa-barcode mr-1"></i>Print label</button></td>
                                    <td class="p-4">
                                        <form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette denne computer fra inventaret? Dens lånehistorik bevares.')">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="slet_computer">
                                            <input type="hidden" name="computernummer" value="<?= h($computer['computernummer']) ?>">
                                            <button class="whitespace-nowrap rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100" type="submit">Slet computer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="computer-inventory-empty" class="<?= $computers ? 'hidden' : '' ?>"><td colspan="7" class="p-6 text-center text-slate-500">Ingen computere oprettet.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900">
                        <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                            <h3 class="font-bold">Andet hardware</h3>
                            <p class="mt-1 text-xs text-slate-500"><?= count($hardwareAssets) ?> registreret</p>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950"><tr><th class="p-4">Udstyrsnummer</th><th class="p-4">Type/navn</th><th class="p-4">Fabrikat/model</th><th class="p-4">Serienummer</th><th class="p-4">Status</th><th class="p-4">Stregkode</th><th class="p-4">Handling</th></tr></thead>
                            <tbody id="hardware-inventory-body" class="divide-y divide-slate-200 dark:divide-slate-800">
                            <?php foreach ($hardwareAssets as $hardware): ?>
                                <?php $hardwareSearch = implode(' ', [$hardware['asset_number'], $hardware['category'], $hardware['name'], $hardware['brand'], $hardware['model'], $hardware['serial_number'], $hardwareStatusLabels[$hardware['status']] ?? $hardware['status'], $hardware['notes'] ?? '']); ?>
                                <tr data-inventory-row data-inventory-kind="hardware" data-inventory-number="<?= h($hardware['asset_number']) ?>" data-inventory-search="<?= h($hardwareSearch) ?>">
                                    <td class="whitespace-nowrap p-4 font-bold"><?= h($hardware['asset_number']) ?></td>
                                    <td class="p-4"><?= h($hardware['category'] . ' · ' . $hardware['name']) ?></td>
                                    <td class="p-4"><?= h(trim($hardware['brand'] . ' ' . $hardware['model'])) ?></td>
                                    <td class="p-4"><?= h($hardware['serial_number']) ?></td>
                                    <td class="p-4"><?= h($hardwareStatusLabels[$hardware['status']] ?? $hardware['status']) ?></td>
                                    <td class="p-4"><button type="button" data-print-asset-label data-asset-number="<?= h($hardware['asset_number']) ?>" class="whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"><i class="fa-solid fa-barcode mr-1"></i>Print label</button></td>
                                    <td class="p-4">
                                        <form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette dette hardware fra inventaret?')">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="slet_hardware">
                                            <input type="hidden" name="asset_number" value="<?= h($hardware['asset_number']) ?>">
                                            <button class="whitespace-nowrap rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100" type="submit">Slet hardware</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="hardware-inventory-empty" class="<?= $hardwareAssets ? 'hidden' : '' ?>"><td colspan="7" class="p-6 text-center text-slate-500">Intet andet hardware registreret.</td></tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
            <script>
                (() => {
                    const search = document.getElementById('inventory-search');
                    const rows = [...document.querySelectorAll('[data-inventory-row]')];
                    const resultCount = document.getElementById('inventory-result-count');
                    const cameraToggle = document.getElementById('inventory-camera-toggle');
                    const cameraClose = document.getElementById('inventory-camera-close');
                    const cameraPanel = document.getElementById('inventory-camera-panel');
                    const cameraStatus = document.getElementById('inventory-camera-status');
                    const video = document.getElementById('inventory-camera-video');
                    const cameraReader = document.getElementById('inventory-camera-reader');
                    const emptyStates = {
                        computer: document.getElementById('computer-inventory-empty'),
                        hardware: document.getElementById('hardware-inventory-empty'),
                    };
                    const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();

                    const filterInventory = () => {
                        const query = normalize(search.value);
                        const visibleByKind = { computer: 0, hardware: 0 };
                        let visible = 0;
                        for (const row of rows) {
                            const matches = normalize(row.dataset.inventorySearch).includes(query);
                            row.hidden = !matches;
                            if (matches) {
                                visible++;
                                visibleByKind[row.dataset.inventoryKind]++;
                            }
                            row.classList.remove('bg-emerald-50', 'dark:bg-emerald-950/30');
                        }
                        for (const [kind, emptyState] of Object.entries(emptyStates)) {
                            if (emptyState) {
                                emptyState.classList.toggle('hidden', visibleByKind[kind] > 0 || (query !== '' && rows.some((row) => row.dataset.inventoryKind === kind)));
                            }
                        }
                        resultCount.textContent = query === ''
                            ? `${rows.length} udstyr registreret.`
                            : `${visible} af ${rows.length} udstyr matcher søgningen.`;
                    };

                    const selectScannedNumber = (value) => {
                        search.value = String(value).trim();
                        filterInventory();
                        const normalizedValue = normalize(search.value);
                        const exactMatch = rows.find((row) => normalize(row.dataset.inventoryNumber) === normalizedValue);
                        if (exactMatch) {
                            for (const row of rows) {
                                row.hidden = row !== exactMatch;
                            }
                            for (const emptyState of Object.values(emptyStates)) {
                                if (emptyState) {
                                    emptyState.classList.add('hidden');
                                }
                            }
                            resultCount.textContent = `Præcist udstyrsnummer fundet: ${exactMatch.dataset.inventoryNumber}.`;
                            exactMatch.classList.add('bg-emerald-50', 'dark:bg-emerald-950/30');
                            exactMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            search.focus();
                            cameraStatus.textContent = `Fundet: ${exactMatch.dataset.inventoryNumber}`;
                        } else {
                            search.focus();
                            if (cameraStatus) {
                                const visibleMatches = rows.filter((row) => !row.hidden).length;
                                cameraStatus.textContent = visibleMatches > 0
                                    ? `${visibleMatches} match fundet for "${search.value}".`
                                    : `Ingen registrering fundet for "${search.value}".`;
                            }
                        }
                    };

                    search.addEventListener('input', filterInventory);
                    search.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            selectScannedNumber(search.value);
                        }
                    });
                    filterInventory();

                    const code39 = {
                        '0': 'nnnwwnwnn', '1': 'wnnwnnnnw', '2': 'nnwwnnnnw', '3': 'wnwwnnnnn',
                        '4': 'nnnwwnnnw', '5': 'wnnwwnnnn', '6': 'nnwwwnnnn', '7': 'nnnwnnwnw',
                        '8': 'wnnwnnwnn', '9': 'nnwwnnwnn', 'A': 'wnnnnwnnw', 'B': 'nnwnnwnnw',
                        'C': 'wnwnnwnnn', 'D': 'nnnnwwnnw', 'E': 'wnnnwwnnn', 'F': 'nnwnwwnnn',
                        'G': 'nnnnnwwnw', 'H': 'wnnnnwwnn', 'I': 'nnwnnwwnn', 'J': 'nnnnwwwnn',
                        'K': 'wnnnnnnww', 'L': 'nnwnnnnww', 'M': 'wnwnnnnwn', 'N': 'nnnnwnnww',
                        'O': 'wnnnwnnwn', 'P': 'nnwnwnnwn', 'Q': 'nnnnnnwww', 'R': 'wnnnnnwwn',
                        'S': 'nnwnnnwwn', 'T': 'nnnnwnwwn', 'U': 'wwnnnnnnw', 'V': 'nwwnnnnnw',
                        'W': 'wwwnnnnnn', 'X': 'nwnnwnnnw', 'Y': 'wwnnwnnnn', 'Z': 'nwwnwnnnn',
                        '-': 'nwnnnnwnw', '.': 'wwnnnnwnn', ' ': 'nwwnnnwnn', '$': 'nwnwnwnnn',
                        '/': 'nwnwnnnwn', '+': 'nwnnnwnwn', '%': 'nnnwnwnwn', '*': 'nwnnwnwnn',
                    };

                    const buildBarcode = (value, documentRef) => {
                        const encoded = `*${value.toUpperCase()}*`;
                        if ([...encoded].some((character) => !code39[character])) {
                            throw new Error('Nummeret indeholder tegn, som ikke kan udskrives som Code 39-stregkode.');
                        }
                        const svgNs = 'http://www.w3.org/2000/svg';
                        const svg = documentRef.createElementNS(svgNs, 'svg');
                        const narrow = 2;
                        const wide = 5;
                        const height = 60;
                        let x = 12;
                        for (const character of encoded) {
                            const pattern = code39[character];
                            for (let index = 0; index < pattern.length; index++) {
                                const width = pattern[index] === 'w' ? wide : narrow;
                                if (index % 2 === 0) {
                                    const bar = documentRef.createElementNS(svgNs, 'rect');
                                    bar.setAttribute('x', String(x));
                                    bar.setAttribute('y', '0');
                                    bar.setAttribute('width', String(width));
                                    bar.setAttribute('height', String(height));
                                    bar.setAttribute('fill', '#000');
                                    svg.append(bar);
                                }
                                x += width;
                            }
                            x += narrow;
                        }
                        svg.setAttribute('viewBox', `0 0 ${x + 10} ${height}`);
                        svg.setAttribute('role', 'img');
                        svg.setAttribute('aria-label', `Stregkode ${value}`);
                        svg.setAttribute('width', String(Math.min(x + 10, 620)));
                        svg.setAttribute('height', '90');
                        svg.setAttribute('preserveAspectRatio', 'xMinYMid meet');
                        return svg;
                    };

                    document.addEventListener('click', (event) => {
                        const button = event.target.closest('[data-print-asset-label]');
                        if (!button) {
                            return;
                        }
                        const number = button.dataset.assetNumber || '';
                        const printWindow = window.open('', '_blank', 'width=520,height=320');
                        if (!printWindow) {
                            alert('Tillad pop op-vinduer for at udskrive udstyrsmærket.');
                            return;
                        }
                        try {
                            const style = printWindow.document.createElement('style');
                            style.textContent = 'body{font-family:Arial,sans-serif;text-align:center;padding:30px;color:#111}svg{max-width:100%;height:auto}.number{font-size:20px;font-weight:bold;letter-spacing:2px;margin-top:8px}@media print{body{padding:10px}}';
                            printWindow.document.head.append(style);
                            const label = printWindow.document.createElement('main');
                            label.append(buildBarcode(number, printWindow.document));
                            const text = printWindow.document.createElement('div');
                            text.className = 'number';
                            text.textContent = number;
                            label.append(text);
                            printWindow.document.body.replaceChildren(label);
                            printWindow.document.title = `Udstyrsmærke ${number}`;
                            printWindow.setTimeout(() => {
                                printWindow.focus();
                                printWindow.print();
                            }, 250);
                        } catch (error) {
                            printWindow.close();
                            alert(error.message);
                        }
                    });

                    let cameraStream = null;
                    let cameraFrame = 0;
                    let detector = null;
                    let html5Scanner = null;
                    let html5ScannerStarted = false;
                    let detecting = false;
                    const loadCameraScanner = () => {
                        if (window.Html5Qrcode) {
                            return Promise.resolve();
                        }
                        return new Promise((resolve, reject) => {
                            const script = document.createElement('script');
                            script.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
                            script.onload = resolve;
                            script.onerror = () => reject(new Error('Kamerascan kunne ikke indlæses. Brug USB-scanner eller søgefeltet.'));
                            document.head.append(script);
                        });
                    };
                    const stopCamera = async () => {
                        if (cameraFrame) {
                            cancelAnimationFrame(cameraFrame);
                            cameraFrame = 0;
                        }
                        if (cameraStream) {
                            cameraStream.getTracks().forEach((track) => track.stop());
                            cameraStream = null;
                        }
                        if (html5Scanner) {
                            const scanner = html5Scanner;
                            html5Scanner = null;
                            try {
                                if (html5ScannerStarted) {
                                    await scanner.stop();
                                }
                            } catch (error) {
                                console.error(error);
                            } finally {
                                html5ScannerStarted = false;
                                try {
                                    scanner.clear();
                                } catch (error) {
                                    console.error(error);
                                }
                            }
                        }
                        video.srcObject = null;
                        cameraToggle.setAttribute('aria-expanded', 'false');
                    };
                    const scanFrame = async () => {
                        if (!cameraStream) {
                            return;
                        }
                        if (!detecting) {
                            detecting = true;
                            try {
                                const results = await detector.detect(video);
                                if (results.length > 0 && results[0].rawValue) {
                                    selectScannedNumber(results[0].rawValue);
                                    await stopCamera();
                                    return;
                                }
                            } catch (error) {
                                cameraStatus.textContent = 'Kunne ikke læse stregkoden. Prøv at holde kameraet roligt tættere på mærket.';
                            } finally {
                                detecting = false;
                            }
                        }
                        cameraFrame = requestAnimationFrame(scanFrame);
                    };
                    cameraToggle.addEventListener('click', async () => {
                        if (cameraStream || html5Scanner) {
                            cameraToggle.disabled = true;
                            await stopCamera();
                            cameraPanel.classList.add('hidden');
                            cameraToggle.textContent = 'Scan med kamera';
                            cameraToggle.disabled = false;
                            return;
                        }
                        cameraPanel.classList.remove('hidden');
                        cameraToggle.disabled = true;
                        try {
                            if ('BarcodeDetector' in window &&
                                navigator.mediaDevices && navigator.mediaDevices.getUserMedia &&
                                typeof window.BarcodeDetector.getSupportedFormats === 'function') {
                                const supported = await window.BarcodeDetector.getSupportedFormats();
                                const formats = ['code_39', 'code_128', 'qr_code'].filter((format) => supported.includes(format));
                                if (formats.length > 0) {
                                    detector = new window.BarcodeDetector({ formats });
                                    cameraStatus.textContent = 'Giver adgang til kameraet…';
                                    cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                                    video.hidden = false;
                                    cameraReader.hidden = true;
                                    video.srcObject = cameraStream;
                                    await video.play();
                                    cameraStatus.textContent = 'Ret kameraet mod stregkoden på udstyrets mærkat.';
                                    cameraToggle.setAttribute('aria-expanded', 'true');
                                    scanFrame();
                                    return;
                                }
                            }
                            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                                throw new Error('Kameraadgang understøttes ikke i denne browser. Brug en USB-scanner eller søgefeltet.');
                            }
                            cameraStatus.textContent = 'Indlæser kamera-scanner…';
                            await loadCameraScanner();
                            const formats = window.Html5QrcodeSupportedFormats;
                            if (!window.Html5Qrcode || !formats) {
                                throw new Error('Kamera-scanneren kunne ikke startes. Brug en USB-scanner eller søgefeltet.');
                            }
                            video.hidden = true;
                            cameraReader.hidden = false;
                            html5Scanner = new window.Html5Qrcode('inventory-camera-reader', {
                                formatsToSupport: [formats.CODE_39, formats.CODE_128, formats.QR_CODE],
                                verbose: false,
                            });
                            await html5Scanner.start(
                                { facingMode: 'environment' },
                                { fps: 10, qrbox: { width: 300, height: 140 }, aspectRatio: 1.777 },
                                (decodedText) => {
                                    selectScannedNumber(decodedText);
                                    stopCamera();
                                },
                                () => {}
                            );
                            html5ScannerStarted = true;
                            cameraStatus.textContent = 'Ret kameraet mod stregkoden på udstyrets mærkat.';
                            cameraToggle.setAttribute('aria-expanded', 'true');
                        } catch (error) {
                            await stopCamera();
                            cameraStatus.textContent = error.message || 'Kameraet kunne ikke startes. Kontrollér kameratilladelsen.';
                        } finally {
                            cameraToggle.disabled = false;
                        }
                    });
                    cameraClose.addEventListener('click', async () => {
                        cameraClose.disabled = true;
                        await stopCamera();
                        cameraPanel.classList.add('hidden');
                        cameraToggle.textContent = 'Scan med kamera';
                        cameraClose.disabled = false;
                    });
                    window.addEventListener('pagehide', stopCamera);
                })();
            </script>
        <?php elseif ($view === 'brugere'): ?>
            <section class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/40">
                <div>
                    <h3 class="font-bold text-blue-950 dark:text-blue-100">Hvor finder eleverne login?</h3>
                    <p class="mt-1 text-sm leading-6 text-blue-900/80 dark:text-blue-200/80">Opret først eleven her. Eleven går derefter til elevportalen, opretter selv en adgangskode med sit elevnummer og registrerede e-mail og logger fremover ind samme sted.</p>
                </div>
                <a href="elev.php" class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-blue-700 px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-800"><i class="fa-solid fa-arrow-up-right-from-square"></i>Åbn elevportal</a>
            </section>
            <div class="grid gap-6 xl:grid-cols-3">
                <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                    <h3 class="mb-4 text-lg font-bold">Opret elev</h3>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Elevregister</p>
                                <p class="text-xs mt-1">Opret elever med elevnummer, navn, email og klasse. Eleven opretter selv adgangskode via elevportalen.</p>
                            </div>
                        </div>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="ny_bruger">
                        <label class="block text-sm font-semibold">Elevnummer<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="elevnummer" required></label>
                        <label class="block text-sm font-semibold">Fulde navn<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="navn" required></label>
                        <label class="block text-sm font-semibold">CPR-nummer (valgfrit)<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="cpr" inputmode="numeric" pattern="[0-9]{6}-?[0-9]{4}" maxlength="11" placeholder="010190-1234"></label>
                        <label class="block text-sm font-semibold">Adresse<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="adresse"></label>
                        <label class="block text-sm font-semibold">Postnummer og by<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="postnummer_by"></label>
                        <label class="block text-sm font-semibold">E-mail<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="email" name="email" required></label>
                        <label class="block text-sm font-semibold">Stamklasse<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="stamklasse" required></label>
                        <button class="rounded-lg bg-slate-800 px-5 py-3 font-bold text-white" type="submit">Opret elev</button>
                    </form>
                </section>
                <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                    <h3 class="mb-4 text-lg font-bold">Skift elevens adgangskode</h3>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="set_elev_password">
                        <label class="block text-sm font-semibold">Elev
                            <select class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="elevnummer" required>
                                <option value="">Vælg elev</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= h($user['elevnummer']) ?>" data-student-search="<?= h($user['navn'] . ' ' . $user['elevnummer'] . ' ' . $user['stamklasse'] . ' ' . $user['email']) ?>"><?= h($user['navn'] . ' (' . $user['elevnummer'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold">Ny adgangskode<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="password" name="password" minlength="6" required></label>
                        <label class="block text-sm font-semibold">Gentag adgangskode<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="password" name="password_confirmation" minlength="6" required></label>
                        <button class="rounded-lg bg-blue-600 px-5 py-3 font-bold text-white" type="submit">Gem ny adgangskode</button>
                    </form>
                </section>
                <section class="rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-sky-600 p-6 text-white shadow-lg shadow-blue-600/20 dark:from-blue-700 dark:via-indigo-700 dark:to-sky-700">
                    <div class="mb-4 flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/15 text-lg"><i class="fa-solid fa-user-check"></i></span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-100/80">Elevstatus</p>
                            <h3 class="text-xl font-black">Oversigt</h3>
                        </div>
                    </div>
                    <div class="space-y-3 text-sm">
                        <div class="rounded-2xl bg-white/10 p-3">
                            <p class="text-blue-100">Registrerede elever</p>
                            <p class="mt-1 text-2xl font-black"><?= count($users) ?></p>
                        </div>
                        <div class="rounded-2xl bg-white/10 p-3">
                            <p class="text-blue-100">Kontoer klar</p>
                            <p class="mt-1 text-2xl font-black"><?= count(array_filter($users, static fn (array $user): bool => (int) $user['konto_oprettet'] === 1)) ?></p>
                        </div>
                        <div class="rounded-2xl bg-white/10 p-3">
                            <p class="text-blue-100">Afventer oprettelse</p>
                            <p class="mt-1 text-2xl font-black"><?= count(array_filter($users, static fn (array $user): bool => (int) $user['konto_oprettet'] === 0)) ?></p>
                        </div>
                    </div>
                    <p class="mt-4 text-xs leading-5 text-blue-100/90">Brug sektionen her til at få et hurtigt overblik, før du arbejder i elevtabellen eller sender nye adgangskoder.</p>
                </section>
                <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900 xl:col-span-3">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950"><tr><th class="p-4">Elevnummer og navn</th><th class="p-4">CPR</th><th class="p-4">Klasse</th><th class="p-4">E-mail</th><th class="p-4">Elevkonto</th><th class="p-4">Adresse/by</th><th class="p-4">Handling</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php foreach ($users as $user): ?>
                            <tr data-student-search="<?= h($user['navn'] . ' ' . $user['elevnummer'] . ' ' . $user['stamklasse'] . ' ' . $user['email']) ?>">
                                <td class="p-4"><?= h($user['elevnummer']) ?><br><?= h($user['navn']) ?></td>
                                <td class="p-4 text-slate-600"><?= h($user['cpr'] ?? '') ?></td>
                                <td class="p-4"><?= h($user['stamklasse']) ?></td>
                                <td class="p-4"><?= h($user['email']) ?></td>
                                <td class="p-4"><?php if ((int) $user['konto_oprettet'] === 1): ?><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">Konto klar</span><?php else: ?><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">Afventer oprettelse</span><?php endif; ?></td>
                                <td class="p-4"><?= h($user['adresse'] . ', ' . $user['postnummer_by']) ?></td>
                                <td class="p-4">
                                    <?php if ((string) ($user['rolle'] ?? '') === 'admin'): ?>
                                        <?php if (!$canManageAdminPermissions): ?>
                                            <span class="text-xs text-slate-400">Kun Nikolaj</span>
                                        <?php else: ?>
                                            <form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette denne administrator permanent?')">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="slet_admin">
                                                <input type="hidden" name="slet_elevnummer" value="<?= h($user['elevnummer']) ?>">
                                                <button class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-semibold text-rose-700" type="submit">Slet admin</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette denne elev?')">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="slet_elev">
                                            <input type="hidden" name="elevnummer" value="<?= h($user['elevnummer']) ?>">
                                            <button class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-semibold text-rose-700" type="submit">Slet</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$users): ?><tr><td colspan="7" class="p-6 text-center text-slate-500">Ingen elever oprettet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        <?php elseif ($view === 'undervisere'): ?>
            <div class="grid gap-6 xl:grid-cols-3">
                <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                    <h3 class="mb-4 text-lg font-bold">Registrer underviser</h3>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Undervisere</p>
                                <p class="text-xs mt-1">Registrer undervisere med navn, email og afdeling. De kan lave computerreservationer.</p>
                            </div>
                        </div>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="ny_underviser">
                        <label class="block text-sm font-semibold">Navn<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="navn" required></label>
                        <label class="block text-sm font-semibold">E-mail<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="email" name="email" required></label>
                        <label class="block text-sm font-semibold">Afdeling<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="afdeling"></label>
                        <button class="rounded-lg bg-blue-600 px-5 py-3 font-bold text-white" type="submit">Opret underviser</button>
                    </form>
                </section>
                <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900 xl:col-span-2">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                        <tr><th class="p-4">Navn</th><th class="p-4">E-mail</th><th class="p-4">Afdeling</th><th class="p-4">Handling</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php foreach ($teachers as $teacher): ?>
                            <tr>
                                <td class="p-4"><?= h($teacher['navn']) ?></td>
                                <td class="p-4"><?= h($teacher['email']) ?></td>
                                <td class="p-4"><?= h($teacher['afdeling']) ?></td>
                                <td class="p-4">
                                    <form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette denne underviser?')">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="slet_underviser">
                                        <input type="hidden" name="underviser_id" value="<?= h($teacher['id']) ?>">
                                        <button class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-semibold text-rose-700 hover:bg-rose-100" type="submit">Slet</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$teachers): ?><tr><td colspan="4" class="p-6 text-center text-slate-500">Ingen undervisere registreret.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        <?php elseif ($view === 'reservationer'): ?>
            <div class="grid gap-6 xl:grid-cols-3">
                <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                    <h3 class="mb-2 text-lg font-bold">Reserver computere</h3>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Reservationer</p>
                                <p class="text-xs mt-1">Reserver computere til undervisere i en bestemt periode. Antallet tæller med i kapaciteten.</p>
                            </div>
                        </div>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="ny_reservation">
                        <label class="block text-sm font-semibold">Underviser
                            <select class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="underviser_id" required>
                                <option value="">Vælg underviser</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?= h($teacher['id']) ?>"><?= h($teacher['navn'] . ' (' . $teacher['email'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold">Antal computere<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="number" min="1" name="antal_computere" required></label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-sm font-semibold">Fra dato<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="date" name="startdato" value="<?= h($today) ?>" required></label>
                            <label class="text-sm font-semibold">Til dato<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="date" name="slutdato" required></label>
                        </div>
                        <label class="block text-sm font-semibold">Formål<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="formaal" maxlength="255"></label>
                        <button class="rounded-lg bg-blue-600 px-5 py-3 font-bold text-white" type="submit">Opret reservation</button>
                    </form>
                </section>
                <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900 xl:col-span-2">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                        <tr><th class="p-4">Underviser</th><th class="p-4">Antal</th><th class="p-4">Periode</th><th class="p-4">Formål</th><th class="p-4">Status</th><th class="p-4">Handling</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php foreach ($reservations as $reservation): ?>
                            <tr>
                                <td class="p-4"><?= h($reservation['underviser_navn']) ?></td>
                                <td class="p-4"><?= h($reservation['antal_computere']) ?></td>
                                <td class="p-4"><?= h($reservation['startdato']) ?> – <?= h($reservation['slutdato']) ?></td>
                                <td class="p-4"><?= h($reservation['formaal']) ?></td>
                                <td class="p-4"><?= h($reservation['status']) ?></td>
                                <td class="p-4">
                                    <?php if ($reservation['status'] === 'aktiv'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="annuller_reservation">
                                        <input type="hidden" name="reservation_id" value="<?= h($reservation['id']) ?>">
                                        <button class="rounded-lg bg-rose-600 px-3 py-2 font-semibold text-white" type="submit">Annuller</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$reservations): ?><tr><td colspan="6" class="p-6 text-center text-slate-500">Ingen reservationer.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        <?php elseif ($view === 'blacklist'): ?>
            <section class="mb-6 rounded-2xl bg-white p-5 shadow dark:bg-slate-900">
                <h3 class="text-lg font-bold">Sæt en elev på blacklist</h3>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Blacklist</p>
                                <p class="text-xs mt-1">Elever på blacklist kan ikke få nye udlån. De kan stadig aflevere eksisterende lån.</p>
                            </div>
                        </div>
                <form method="post" class="grid gap-4 md:grid-cols-[1fr_2fr_auto]">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="tilfoej_blacklist">
                    <label class="block text-sm font-semibold">Elev
                        <select class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="elevnummer" required>
                            <option value="">Vælg elev</option>
                            <?php foreach ($users as $user): ?>
                                <?php if ((int) $user['blacklisted'] === 0): ?>
                                    <option value="<?= h($user['elevnummer']) ?>" data-student-search="<?= h($user['navn'] . ' ' . $user['elevnummer'] . ' ' . $user['stamklasse'] . ' ' . $user['email']) ?>"><?= h($user['navn'] . ' (' . $user['elevnummer'] . ')') ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold">Årsag
                        <input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="blacklist_reason" maxlength="255" required>
                    </label>
                    <button class="self-end rounded-lg bg-rose-600 px-5 py-3 font-bold text-white hover:bg-rose-700" type="submit">Sæt på blacklist</button>
                </form>
            </section>
            <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900">
                <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                    <h3 class="text-lg font-bold">Blacklist</h3>
                    <p class="text-sm text-slate-500">Lånere sættes automatisk på listen efter <?= h(LATE_RETURN_BLACKLIST_THRESHOLD) ?> forsinkede afleveringer. De kan ikke få nye udlån, før de fjernes her.</p>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                    <tr><th class="p-4">Låner</th><th class="p-4">E-mail</th><th class="p-4">Forsinkede afleveringer</th><th class="p-4">Årsag</th><th class="p-4">Handling</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php foreach ($blacklistedUsers as $blacklistedUser): ?>
                        <tr data-student-search="<?= h($blacklistedUser['navn'] . ' ' . $blacklistedUser['elevnummer'] . ' ' . $blacklistedUser['email']) ?>">
                            <td class="p-4"><?= h($blacklistedUser['navn']) ?> (<?= h($blacklistedUser['elevnummer']) ?>)</td>
                            <td class="p-4"><?= h($blacklistedUser['email']) ?></td>
                            <td class="p-4"><?= h($blacklistedUser['forsinkede_afleveringer']) ?></td>
                            <td class="p-4"><?= h($blacklistedUser['blacklist_reason']) ?></td>
                            <td class="p-4">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="fjern_blacklist">
                                    <input type="hidden" name="elevnummer" value="<?= h($blacklistedUser['elevnummer']) ?>">
                                    <button class="rounded-lg bg-emerald-600 px-3 py-2 font-semibold text-white" type="submit">Fjern blacklist</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$blacklistedUsers): ?><tr><td colspan="5" class="p-6 text-center text-slate-500">Ingen lånere på blacklist.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'tidligere_laan'): ?>
            <section class="mb-6 rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/40">
                <div class="flex items-start gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-600/10 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <div>
                        <h3 class="font-bold text-blue-950 dark:text-blue-100">Tidligere udlån & scan</h3>
                        <p class="mt-1 text-sm leading-6 text-blue-900/80 dark:text-blue-200/80">Scan QR/stregkoden på udstyret for at se hvem der har lånt det. Aktive lån kan afleveres — eleven skal godkende afleveringen på mail.</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">Scan eller indtast computernummer</span>
                        <input id="loan-scan-search" type="search" autofocus autocomplete="off" placeholder="Scan QR/stregkode eller indtast computernummer (fx PC-…)" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-blue-900/40">
                    </label>
                    <button id="loan-camera-toggle" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 font-bold text-white transition hover:bg-indigo-700">
                        <i class="fa-solid fa-camera" aria-hidden="true"></i>Scan med kamera
                    </button>
                </div>
                <div id="loan-camera-panel" class="mt-4 hidden rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p id="loan-camera-status" class="text-sm text-slate-600 dark:text-slate-300" role="status" aria-live="polite">Ret kameraet mod udstyrets stregkode.</p>
                        <button id="loan-camera-close" type="button" class="rounded-lg px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800">Luk kamera</button>
                    </div>
                    <video id="loan-camera-video" class="max-h-72 w-full rounded-xl bg-black object-contain" playsinline muted></video>
                </div>
                <p id="loan-scan-count" class="mt-3 text-xs text-slate-500 dark:text-slate-400" aria-live="polite"></p>
            </section>
            <?php if ($pendingApprovals): ?>
            <section class="mb-6 overflow-hidden rounded-2xl border border-amber-200 bg-amber-50 shadow-sm dark:border-amber-900 dark:bg-amber-950/30">
                <div class="border-b border-amber-200 p-5 dark:border-amber-900">
                    <h3 class="text-lg font-bold text-amber-950 dark:text-amber-100">Afventer elevens godkendelse</h3>
                    <p class="mt-1 text-sm text-amber-900/80 dark:text-amber-200/80">Eleven har ikke godkendt afleveringen endnu. Hvis eleven ikke svarer inden fristen, kan du godkende afleveringen manuelt her.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-amber-100/60 text-xs uppercase text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                        <tr>
                            <th class="p-4">Elev</th>
                            <th class="p-4">Klasse</th>
                            <th class="p-4">Computer</th>
                            <th class="p-4">Afleveret</th>
                            <th class="p-4">Frist</th>
                            <th class="p-4">Handling</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-amber-200 dark:divide-amber-900">
                        <?php foreach ($pendingApprovals as $approval): ?>
                            <tr>
                                <td class="p-4"><?= h($approval['navn']) ?><br><span class="text-xs text-slate-500 dark:text-slate-400"><?= h($approval['email']) ?></span></td>
                                <td class="p-4"><?= h($approval['stamklasse']) ?></td>
                                <td class="p-4 font-semibold"><?= h($approval['computernummer']) ?></td>
                                <td class="p-4"><?= h((string) $approval['afleveringsdato']) ?></td>
                                <td class="p-4">
                                    <?php if ((int) $approval['dage_tilbage'] < 0): ?>
                                        <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-bold text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">Frist udløbet</span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-900/50 dark:text-amber-200"><?= (int) $approval['dage_tilbage'] ?> dage tilbage</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <form method="post" onsubmit="return confirm('Godkend afleveringen manuelt på vegne af eleven?')">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="godkend_aflevering_admin">
                                        <input type="hidden" name="laan_id" value="<?= h((string) $approval['loan_id']) ?>">
                                        <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-emerald-700">Godkend aflevering</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>
            <section class="overflow-x-auto rounded-2xl bg-white shadow dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Udlån for udstyr</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Aktive og afsluttede lån · <?= count($loans) + count($previousLoans) ?> i alt</p>
                    </div>
                    <a href="elev.php" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <i class="fa-solid fa-id-card"></i> Åbn elevportal
                    </a>
                </div>
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                    <tr>
                        <th class="p-4">Elev</th>
                        <th class="p-4">Klasse</th>
                        <th class="p-4">Computer</th>
                        <th class="p-4">Udlånt</th>
                        <th class="p-4">Afleveringsfrist</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Handling</th>
                    </tr>
                    </thead>
                    <tbody id="all-loans-body" class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php foreach ($loans as $loan): ?>
                        <?php $searchText = implode(' ', [$loan['computernummer'], $loan['navn'], $loan['elevnummer'], $loan['email']]); ?>
                        <tr data-loan-search="<?= h($searchText) ?>">
                            <td class="p-4"><?= h($loan['navn']) ?><br><span class="text-xs text-slate-500 dark:text-slate-400"><?= h($loan['email']) ?></span></td>
                            <td class="p-4"><?= h($loan['stamklasse']) ?></td>
                            <td class="p-4 font-semibold"><?= h($loan['computernummer']) ?></td>
                            <td class="p-4"><?= h($loan['udlaansdato']) ?></td>
                            <td class="p-4"><?= h($loan['udloebsdato']) ?></td>
                            <td class="p-4"><span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800 dark:bg-blue-900/50 dark:text-blue-200">Aktiv</span></td>
                            <td class="p-4">
                                <form method="post" onsubmit="return confirm('Registrer aflevering? Eleven modtager en mail og skal godkende afleveringen.')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="aflever">
                                    <input type="hidden" name="loan_id" value="<?= h((string) $loan['id']) ?>">
                                    <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-emerald-700">Registrer aflevering</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($previousLoans as $loan): ?>
                        <?php $searchText = implode(' ', [$loan['computernummer'], $loan['navn'], $loan['elevnummer'], $loan['email']]); ?>
                        <tr data-loan-search="<?= h($searchText) ?>">
                            <td class="p-4"><?= h($loan['navn']) ?><br><span class="text-xs text-slate-500 dark:text-slate-400"><?= h($loan['email']) ?></span></td>
                            <td class="p-4"><?= h($loan['stamklasse']) ?></td>
                            <td class="p-4 font-semibold"><?= h($loan['computernummer']) ?></td>
                            <td class="p-4"><?= h($loan['udlaansdato']) ?></td>
                            <td class="p-4"><?= h((string) $loan['afleveringsdato']) ?></td>
                            <td class="p-4">
                                <?php if ($loan['aflevering_status'] === 'til tiden'): ?>
                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">Afsluttet · til tiden</span>
                                <?php else: ?>
                                    <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-bold text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">Afsluttet · forsinket</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-xs text-slate-400">—</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$loans && !$previousLoans): ?>
                        <tr><td colspan="7" class="p-8 text-center text-slate-500">Ingen udlån endnu.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
            <script>
                (() => {
                    const search = document.getElementById('loan-scan-search');
                    const rows = [...document.querySelectorAll('[data-loan-search]')];
                    const countEl = document.getElementById('loan-scan-count');
                    const cameraToggle = document.getElementById('loan-camera-toggle');
                    const cameraClose = document.getElementById('loan-camera-close');
                    const cameraPanel = document.getElementById('loan-camera-panel');
                    const cameraStatus = document.getElementById('loan-camera-status');
                    const video = document.getElementById('loan-camera-video');

                    const normalize = (v) => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();

                    const filter = () => {
                        const q = normalize(search.value);
                        let visible = 0;
                        for (const row of rows) {
                            const match = q === '' || normalize(row.dataset.loanSearch).includes(q);
                            row.hidden = !match;
                            if (match) visible++;
                        }
                        if (countEl) {
                            countEl.textContent = q === ''
                                ? rows.length + ' udlån vist'
                                : visible + ' af ' + rows.length + ' udlån matcher';
                        }
                    };

                    search.addEventListener('input', filter);
                    filter();

                    let stream = null;
                    let detector = null;
                    let frameId = 0;
                    let detecting = false;

                    const stopCamera = async () => {
                        if (frameId) { cancelAnimationFrame(frameId); frameId = 0; }
                        if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
                        video.srcObject = null;
                        cameraPanel.classList.add('hidden');
                        cameraToggle.setAttribute('aria-expanded', 'false');
                    };

                    const onScan = (value) => {
                        search.value = value;
                        filter();
                        search.focus();
                        cameraStatus.textContent = 'Fundet: ' + value;
                        stopCamera();
                    };

                    const scanFrame = async () => {
                        if (!stream) return;
                        if (!detecting) {
                            detecting = true;
                            try {
                                const results = await detector.detect(video);
                                if (results.length > 0 && results[0].rawValue) {
                                    onScan(results[0].rawValue);
                                    return;
                                }
                            } catch (e) {
                                cameraStatus.textContent = 'Kunne ikke læse stregkoden. Prøv at holde kameraet roligt tættere på mærket.';
                            } finally {
                                detecting = false;
                            }
                        }
                        frameId = requestAnimationFrame(scanFrame);
                    };

                    cameraToggle.addEventListener('click', async () => {
                        if (stream) { await stopCamera(); return; }
                        cameraPanel.classList.remove('hidden');
                        cameraToggle.disabled = true;
                        try {
                            if ('BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && typeof window.BarcodeDetector.getSupportedFormats === 'function') {
                                const supported = await window.BarcodeDetector.getSupportedFormats();
                                const formats = ['code_39', 'code_128', 'qr_code'].filter((f) => supported.includes(f));
                                if (formats.length > 0) {
                                    detector = new window.BarcodeDetector({ formats });
                                    cameraStatus.textContent = 'Giver adgang til kameraet…';
                                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                                    video.srcObject = stream;
                                    await video.play();
                                    cameraStatus.textContent = 'Ret kameraet mod stregkoden på udstyrets mærkat.';
                                    cameraToggle.setAttribute('aria-expanded', 'true');
                                    scanFrame();
                                    return;
                                }
                            }
                            throw new Error('Kamera-scan understøttes ikke i denne browser. Brug søgefeltet eller en USB-scanner.');
                        } catch (e) {
                            await stopCamera();
                            cameraStatus.textContent = e.message || 'Kameraet kunne ikke startes. Kontrollér kameratilladelsen.';
                        } finally {
                            cameraToggle.disabled = false;
                        }
                    });

                    cameraClose.addEventListener('click', stopCamera);
                    window.addEventListener('pagehide', stopCamera);
                })();
            </script>
        <?php elseif ($view === 'admin_requests'): ?>
            <?php if ($canManageAdminPermissions): ?>
                <section class="mb-6 overflow-x-auto rounded-3xl border border-indigo-200 bg-white shadow-sm dark:border-indigo-900 dark:bg-slate-900">
                    <div class="border-b border-indigo-100 p-5 dark:border-indigo-900">
                        <h3 class="text-lg font-bold">Rettigheder til adminansøgninger</h3>
                        <p class="mt-1 text-sm text-slate-500">Kun Nikolaj kan give eller fjerne denne adgang. Admins uden adgang kan hverken se eller behandle ansøgninger.</p>
                    </div>
                    <table class="w-full min-w-[620px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                        <tr><th class="p-4">Admin</th><th class="p-4">E-mail</th><th class="p-4">Rettighed</th><th class="p-4">Handling</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php foreach ($adminAccounts as $account): ?>
                            <?php if (!is_array($account) || !isset($account['username']) || !is_string($account['username'])) { continue; } ?>
                            <?php
                            // Fjern Niko fra adminrettigheder
                            $accountUsername = $account['username'];
                            $username = strtolower($accountUsername);
                            if ($username === 'niko') { continue; }
                            if (empty($account['email'] ?? '')) { continue; }
                            $hasApplicationPermission = ($account['can_manage_admin_applications'] ?? false) === true;
                            ?>
                            <tr>
                                <td class="p-4 font-semibold"><?= h($accountUsername) ?></td>
                                <td class="p-4"><?= h($account['email'] ?? 'Ingen e-mail registreret') ?></td>
                                <td class="p-4"><?= $hasApplicationPermission ? 'Kan se ansøgninger' : 'Ingen adgang' ?></td>
                                <td class="p-4">
                                    <?php if (strcasecmp($accountUsername, NIKOLAJ_ADMIN_USERNAME) === 0): ?>
                                        <span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-bold text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200">Ejeradgang</span>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_admin_application_permission">
                                            <input type="hidden" name="admin_username" value="<?= h($accountUsername) ?>">
                                            <input type="hidden" name="allow" value="<?= $hasApplicationPermission ? '0' : '1' ?>">
                                            <button class="rounded-lg <?= $hasApplicationPermission ? 'bg-slate-700 hover:bg-slate-800' : 'bg-indigo-600 hover:bg-indigo-700' ?> px-3 py-2 font-semibold text-white" type="submit">
                                                <?= $hasApplicationPermission ? 'Fjern adgang' : 'Giv adgang' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
            <section class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                    <h3 class="text-lg font-bold">Adminansøgninger</h3>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Adminansøgninger</p>
                                <p class="text-xs mt-1">Godkend eller afvis ventende adminansøgninger. Kun Nikolaj kan se og behandle dem.</p>
                            </div>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">Ventende ansøgninger kan behandles af admins med denne rettighed. Godkendte brugere skal også bekræfte login med en kode sendt til deres e-mail.</p>
                </div>
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                    <tr><th class="p-4">Brugernavn</th><th class="p-4">E-mail</th><th class="p-4">Ansøgt</th><th class="p-4">Handling</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php foreach ($adminRequests as $adminRequest): ?>
                        <tr>
                            <td class="p-4 font-semibold"><?= h($adminRequest['username']) ?></td>
                            <td class="p-4"><?= h($adminRequest['email']) ?></td>
                            <td class="p-4"><?= h($adminRequest['created_at']) ?></td>
                            <td class="p-4">
                                <div class="flex flex-wrap gap-2">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="approve_admin_request">
                                        <input type="hidden" name="request_id" value="<?= h($adminRequest['id']) ?>">
                                        <button class="rounded-lg bg-emerald-600 px-3 py-2 font-semibold text-white hover:bg-emerald-700" type="submit">Godkend</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Afvis og slet denne adminansøgning?')">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="reject_admin_request">
                                        <input type="hidden" name="request_id" value="<?= h($adminRequest['id']) ?>">
                                        <button class="rounded-lg bg-rose-600 px-3 py-2 font-semibold text-white hover:bg-rose-700" type="submit">Afvis</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$adminRequests): ?><tr><td colspan="4" class="p-8 text-center text-slate-500">Der er ingen ventende adminansøgninger.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'admins'): ?>
            <?php $admins = isset($admins) && is_array($admins) ? $admins : []; ?>
            <?php if (!$canManageAdminPermissions): ?>
                <section class="rounded-2xl border border-rose-200 bg-rose-50 p-6 dark:border-rose-900 dark:bg-rose-950/40">
                    <h3 class="text-lg font-bold text-rose-900 dark:text-rose-200">Ingen adgang</h3>
                    <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">Kun Nikolaj kan administrere administratorer.</p>
                </section>
            <?php else: ?>
                <div class="grid gap-6">
                    <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                        <h3 class="mb-4 text-lg font-bold">Administratorer i systemet</h3>
                        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Her kan du se og slette administratorer. Elever vises ikke her.</p>
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-blue-50"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="text-sm text-blue-900/80 dark:text-blue-200">
                                <p class="font-semibold">Guide: Administratorer</p>
                                <p class="text-xs mt-1">Se alle administratorer, slet dem eller opret nye admin-konti direkte i databasen.</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950">
                                <tr>
                                    <th class="p-4">Vælg</th>
                                    <th class="p-4">Brugernavn</th>
                                    <th class="p-4">Navn</th>
                                    <th class="p-4">E-mail</th>
                                    <th class="p-4">Handling</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($admins as $admin): ?>
                                    <?php if (empty($admin['email'] ?? '')) { continue; } ?>
                                    <?php $isSelf = strcasecmp((string) $admin['elevnummer'], $currentAdminUsername) === 0; ?>
                                    <tr>
                                        <td class="p-4">
                                            <input type="checkbox" name="slet_admin_nr[]" value="<?= h($admin['elevnummer']) ?>" class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                        </td>
                                        <td class="p-4 font-semibold"><?= h($admin['elevnummer']) ?></td>
                                        <td class="p-4"><?= h($admin['navn']) ?></td>
                                        <td class="p-4"><?= h($admin['email']) ?></td>
                                        <td class="p-4">
                                            <?php if ($isSelf): ?>
                                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300">Dig selv</span>
                                            <?php else: ?>
                                                <form method="post" class="inline" onsubmit="return confirm('Er du sikker på, at du vil slette denne administrator permanent?')">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                    <input type="hidden" name="action" value="slet_admin">
                                                    <input type="hidden" name="slet_elevnummer" value="<?= h($admin['elevnummer']) ?>">
                                                    <button class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-semibold text-rose-700 hover:bg-rose-100" type="submit">Slet admin</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$admins): ?>
                                    <tr><td colspan="5" class="p-6 text-center text-slate-500">Ingen administratorer fundet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <form method="post" class="mt-4 flex items-center gap-3">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="slet_admin_bulk">
                            <button type="submit" onclick="return confirm('Er du sikker på, at du vil slette alle valgte administratorer?')" class="rounded-lg bg-rose-600 px-4 py-2 font-bold text-white hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed" id="slet-bulk-btn" disabled>Slet valgte administratorer</button>
                        </form>
                        <script>
                            const checkboxes = document.querySelectorAll('input[name="slet_admin_nr[]"]');
                            const bulkBtn = document.getElementById('slet-bulk-btn');
                            const bulkForm = document.querySelector('form[action*="slet_admin_bulk"]') || document.querySelector('form input[name="action"][value="slet_admin_bulk"]').closest('form');
                            
                            checkboxes.forEach(cb => cb.addEventListener('change', () => {
                                const anyChecked = document.querySelectorAll('input[name="slet_admin_nr[]"]:checked').length > 0;
                                bulkBtn.disabled = !anyChecked;
                            }));

                            bulkForm.addEventListener('submit', function(e) {
                                const checked = document.querySelectorAll('input[name="slet_admin_nr[]"]:checked');
                                if (checked.length === 0) {
                                    e.preventDefault();
                                    alert('Vælg mindst én administrator, der skal slettes.');
                                    return;
                                }
                                // Fjern alle tidligere skjulte inputfelter
                                document.querySelectorAll('input[name="slet_admin_nr[]"]').forEach(el => {
                                    if (!el.closest('td')) el.remove();
                                });
                                // Tilføj de valgte som skjulte inputfelter i formularen
                                checked.forEach(cb => {
                                    const hidden = document.createElement('input');
                                    hidden.type = 'hidden';
                                    hidden.name = 'slet_admin_nr[]';
                                    hidden.value = cb.value;
                                    bulkForm.appendChild(hidden);
                                });
                            });
                        </script>
                    </section>

                    <section class="rounded-2xl bg-white p-6 shadow dark:bg-slate-900">
                        <h3 class="mb-4 text-lg font-bold">Tilføj ny administrator</h3>
                        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Opret en ny admin-konto direkte i databasen. Brugeren skal også oprettes via hjemmesiden for at kunne logge ind.</p>
                        <form method="post" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="ny_admin">
                            <label class="block text-sm font-semibold">Brugernavn<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="elevnummer" required></label>
                            <label class="block text-sm font-semibold">Fulde navn<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" name="navn" required></label>
                            <label class="block text-sm font-semibold">E-mail<input class="mt-1 w-full rounded-lg border p-3 text-slate-900" type="email" name="email" required></label>
                            <button class="rounded-lg bg-blue-600 px-5 py-3 font-bold text-white" type="submit">Opret administrator</button>
                        </form>
                    </section>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
    <?php if (in_array($view, ['dashboard', 'udlaan', 'brugere', 'blacklist'], true)): ?>
    <script>
        const studentSearch = document.getElementById('studentSearch');
        const studentSearchItems = document.querySelectorAll('[data-student-search]');
        if (studentSearch) {
            const updateStudentSearch = () => {
                const query = studentSearch.value.trim().toLocaleLowerCase('da-DK');
                studentSearchItems.forEach((item) => {
                    const matches = query === '' || item.dataset.studentSearch.toLocaleLowerCase('da-DK').includes(query);
                    if (item instanceof HTMLOptionElement) {
                        item.disabled = !matches && !item.selected;
                        item.hidden = !matches && !item.selected;
                    } else {
                        item.hidden = query === ''
                            ? item.dataset.studentSearchOverflow === 'true'
                            : !matches;
                    }
                });
            };
            studentSearch.addEventListener('input', updateStudentSearch);
            updateStudentSearch();
        }

        const bulkReturnForm = document.getElementById('bulk-return-form');
        if (bulkReturnForm) {
            const loanCheckboxes = Array.from(document.querySelectorAll('[data-return-student]'));
            const bulkReturnButton = document.getElementById('bulk-return-submit');
            const bulkReturnStatus = document.getElementById('bulk-return-status');
            const refreshBulkReturn = (selectionMessage = '') => {
                const selected = loanCheckboxes.filter((checkbox) => checkbox.checked);
                bulkReturnButton.disabled = selected.length === 0;
                bulkReturnStatus.textContent = selectionMessage || (
                    selected.length === 0
                        ? 'Ingen lån valgt'
                        : `${selected.length} lån valgt`
                );
            };
            loanCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked && loanCheckboxes.some(
                        (other) => other.checked && other !== checkbox
                            && other.dataset.returnStudent !== checkbox.dataset.returnStudent
                    )) {
                        checkbox.checked = false;
                        refreshBulkReturn('Vælg kun lån fra den samme elev');
                        return;
                    }
                    refreshBulkReturn();
                });
            });
            bulkReturnForm.addEventListener('submit', (event) => {
                const selectedCount = loanCheckboxes.filter((checkbox) => checkbox.checked).length;
                if (selectedCount === 0 || !window.confirm(`Registrer ${selectedCount} valgte lån som afleveret?`)) {
                    event.preventDefault();
                }
            });
        }
    </script>
    <?php endif; ?>
<?php if ($view === 'dashboard'): ?>
<script>
    const chartTextColor = document.body.classList.contains('dark') ? '#cbd5e1' : '#475569';
    const chartGridColor = document.body.classList.contains('dark') ? 'rgba(148, 163, 184, 0.18)' : 'rgba(71, 85, 105, 0.12)';
    const monthlyLoansChart = new Chart(document.getElementById('monthlyLoansChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthlyLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            datasets: [{ label: 'Udlån', data: <?= json_encode($monthlyLoanCounts) ?>, backgroundColor: '#3b82f6' }]
        },
        options: {
            responsive: true,
            plugins: { legend: { labels: { color: chartTextColor } } },
            scales: {
                x: { ticks: { color: chartTextColor }, grid: { color: chartGridColor } },
                y: { beginAtZero: true, ticks: { precision: 0, color: chartTextColor }, grid: { color: chartGridColor } }
            }
        }
    });
    new MutationObserver(() => {
        const darkModeEnabled = document.body.classList.contains('dark');
        const textColor = darkModeEnabled ? '#cbd5e1' : '#475569';
        const gridColor = darkModeEnabled ? 'rgba(148, 163, 184, 0.18)' : 'rgba(71, 85, 105, 0.12)';
        monthlyLoansChart.options.plugins.legend.labels.color = textColor;
        monthlyLoansChart.options.scales.x.ticks.color = textColor;
        monthlyLoansChart.options.scales.x.grid.color = gridColor;
        monthlyLoansChart.options.scales.y.ticks.color = textColor;
        monthlyLoansChart.options.scales.y.grid.color = gridColor;
        monthlyLoansChart.update('none');
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
</script>
<?php endif; ?>
</body>
</html>
