<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appData = getenv('APPDATA');
if ($appData === false || $appData === '') {
    fwrite(STDERR, "Windows AppData-mappen kunne ikke findes.\n");
    exit(1);
}

$stateDirectory = $appData . DIRECTORY_SEPARATOR . 'SkoleUdlaan';
if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0700, true) && !is_dir($stateDirectory)) {
    fwrite(STDERR, "Kunne ikke oprette mappen til opgavens statusfiler.\n");
    exit(1);
}

$logPath = $stateDirectory . DIRECTORY_SEPARATOR . 'automatic_reminders.log';
$lock = fopen($stateDirectory . DIRECTORY_SEPARATOR . 'automatic_reminders.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "En automatisk rykkerkørsel er allerede i gang.\n");
    exit(1);
}

$log = static function (string $message) use ($logPath): void {
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    if (file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log(trim($entry));
    }
};

$exitCode = 0;
try {
    $pdo = database();
    ensureLoanApprovalTable($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS automatiske_rykkere (
            laan_id INT NOT NULL,
            status VARCHAR(16) NOT NULL,
            sidste_forsog DATETIME NOT NULL,
            sendt_dato DATETIME NULL,
            fejl TEXT NULL,
            PRIMARY KEY (laan_id),
            CONSTRAINT fk_automatiske_rykkere_udlaan
                FOREIGN KEY (laan_id) REFERENCES udlaan (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $expiredApprovals = $pdo->query(
        "SELECT g.loan_id, b.navn, b.email
         FROM udlaan_godkendelser g
         JOIN udlaan u ON u.id = g.loan_id
         JOIN brugere b ON b.elevnummer = u.elevnummer
         WHERE g.status = 'pending' AND g.expires_at <= NOW()"
    )->fetchAll();
    foreach ($expiredApprovals as $approval) {
        $pdo->prepare(
            'UPDATE udlaan_godkendelser SET status = "accepted", response_at = NOW(), response_note = "Automatisk godkendt efter frist" WHERE loan_id = ?'
        )->execute([$approval['loan_id']]);
        $log('Godkendelse blev automatisk godkendt for lån ' . $approval['loan_id'] . ' til ' . $approval['email'] . '.');
    }

    $loans = $pdo->query(
        "SELECT u.id, u.elevnummer, b.navn, b.email, b.blacklisted, u.computernummer, u.udloebsdato,
                DATEDIFF(CURDATE(), u.udloebsdato) AS dage_over_tid
         FROM udlaan u
         JOIN brugere b ON b.elevnummer = u.elevnummer
         LEFT JOIN automatiske_rykkere r ON r.laan_id = u.id
         WHERE u.afleveret = 0
           AND u.udloebsdato <= CURDATE()
           AND (r.laan_id IS NULL OR r.status <> 'sent')
         ORDER BY u.udloebsdato ASC, u.id ASC"
    )->fetchAll();

    $reserve = $pdo->prepare(
        "INSERT INTO automatiske_rykkere (laan_id, status, sidste_forsog, fejl)
         VALUES (?, 'sending', NOW(), NULL)
         ON DUPLICATE KEY UPDATE
             status = 'sending',
             sidste_forsog = NOW(),
             fejl = NULL"
    );
    $readStatus = $pdo->prepare('SELECT status FROM automatiske_rykkere WHERE laan_id = ?');
    $checkStatus = $pdo->prepare('SELECT status FROM automatiske_rykkere WHERE laan_id = ?');
    $markSent = $pdo->prepare(
        "UPDATE automatiske_rykkere SET status = 'sent', sendt_dato = NOW(), fejl = NULL WHERE laan_id = ?"
    );
    $markFailed = $pdo->prepare(
        "UPDATE automatiske_rykkere SET status = 'failed', fejl = ? WHERE laan_id = ?"
    );

    $dryRun = in_array('--dry-run', $argv, true);
    if ($dryRun) {
        fwrite(STDOUT, 'Testkørsel: ' . count($loans) . " lån har afleveringsfrist i dag. Ingen mails blev sendt.\n");
        $log('Testkørsel udført. Fundne lån: ' . count($loans) . '.');
    }

    foreach ($loans as $loan) {
        if ($dryRun) {
            continue;
        }
        $readStatus->execute([$loan['id']]);
        if ($readStatus->fetchColumn() === 'sent') {
            continue;
        }
        $reserve->execute([$loan['id']]);
        $checkStatus->execute([$loan['id']]);
        if ($checkStatus->fetchColumn() === 'sent') {
            continue;
        }

        try {
            $daysOverdue = (int) ($loan['dage_over_tid'] ?? 0);
            if ((int) ($loan['blacklisted'] ?? 0) === 1) {
                $markSent->execute([$loan['id']]);
                continue;
            }
            if ($daysOverdue >= LATE_RETURN_BLACKLIST_THRESHOLD) {
                $blacklistReason = 'Automatisk: computeren er ikke afleveret i ' . $daysOverdue . ' dage.';
                $update = $pdo->prepare(
                    'UPDATE brugere SET blacklisted = 1, blacklist_reason = ? WHERE elevnummer = ? AND blacklisted = 0'
                );
                $update->execute([$blacklistReason, $loan['elevnummer']]);
                if ($update->rowCount() === 1) {
                    sendBlacklistNotificationEmail($loan, $blacklistReason);
                    $log('Låner sat på blacklist efter forfalden aflevering for udlaan ID ' . $loan['id'] . '.');
                }
                $markSent->execute([$loan['id']]);
                continue;
            }
            if ($daysOverdue > 0) {
                sendLateReturnWarningEmail($loan);
            } else {
                sendReminderEmail($loan);
            }
            $markSent->execute([$loan['id']]);
            $log('Rykker sendt for udlaan ID ' . $loan['id'] . ' til ' . $loan['email'] . '.');
        } catch (Throwable $exception) {
            $markFailed->execute([substr($exception->getMessage(), 0, 4000), $loan['id']]);
            $log('Rykker fejlede for udlaan ID ' . $loan['id'] . ': ' . $exception->getMessage());
            $exitCode = 1;
        }
    }

    $log('Automatisk rykkerkørsel afsluttet. Fundne lån: ' . count($loans) . '.');
    fwrite(STDOUT, 'Automatisk rykkerkørsel afsluttet. Fundne lån: ' . count($loans) . ".\n");
} catch (Throwable $exception) {
    $log('Automatisk rykkerkørsel fejlede: ' . $exception->getMessage());
    fwrite(STDERR, 'Automatisk rykkerkørsel fejlede: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($exitCode);
