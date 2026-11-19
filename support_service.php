<?php
declare(strict_types=1);

require_once __DIR__ . '/php_config.php';
require_once __DIR__ . '/mailer.php';

function expireIdleSupportTickets(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE support_tickets
         SET status = 'inactive', closed_at = DATE_ADD(last_activity_at, INTERVAL 10 MINUTE),
             closed_by = 'inactivity'
         WHERE status = 'open'
           AND last_activity_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
}

function supportAdminEmails(): array
{
    $emails = [];
    foreach (adminCredentialStore() as $account) {
        if (!is_array($account) || !isset($account['username']) || !is_string($account['username'])) {
            continue;
        }
        $email = adminEmailFor($account['username']);
        if ($email !== null) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function sendSupportAdminNotification(array $ticket, string $event): array
{
    $recipients = supportAdminEmails();
    $sent = 0;
    $failed = 0;
    $link = APP_BASE_URL . '/admin_support.php?id=' . rawurlencode((string) $ticket['id']);
    $subject = 'Support ' . $ticket['ticket_reference'] . ': ' . $event;
    $plain = "Der er nyt i en supportsag.\n\n"
        . 'Sag: ' . $ticket['ticket_reference'] . "\n"
        . 'Elev: ' . $ticket['student_name'] . ' (' . $ticket['student_number'] . ")\n"
        . 'Hændelse: ' . $event . "\n\n"
        . 'Åbn sagen i adminpanelet: ' . $link . "\n\n"
        . "Del aldrig adgangskoder eller 2-faktor-koder med eleven.";
    $content = '<p>Der er nyt i en supportsag.</p>'
        . '<p><strong>Sag:</strong> ' . mailEscape((string) $ticket['ticket_reference']) . '<br>'
        . '<strong>Elev:</strong> ' . mailEscape((string) $ticket['student_name']) . ' ('
        . mailEscape((string) $ticket['student_number']) . ')<br>'
        . '<strong>Hændelse:</strong> ' . mailEscape($event) . '</p>'
        . '<p><a href="' . mailEscape($link) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold">Åbn supportsag</a></p>';
    $html = emailLayout('Ny aktivitet i supportsag', 'Supportteam', $content, 'Åbn sagen i adminpanelet.');

    foreach ($recipients as $recipient) {
        try {
            sendBrandedEmail($recipient, $subject, $plain, $html);
            $sent++;
        } catch (Throwable $exception) {
            error_log('Supportnotifikation kunne ikke sendes til en admin: ' . $exception->getMessage());
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

function sendSupportStudentNotification(array $ticket, string $event, ?string $token = null): bool
{
    $link = $token === null
        ? APP_BASE_URL . '/support.php'
        : APP_BASE_URL . '/support.php?token=' . rawurlencode($token);
    $accessNote = $token === null
        ? 'Åbn sagen via elevportalen, eller brug det sikre sagslink fra den første e-mail.'
        : 'Gem dette sikre link, så du kan åbne sagen igen uden at logge ind.';
    $subject = 'IT-Udlån support ' . $ticket['ticket_reference'] . ': ' . $event;
    $plain = "Hej {$ticket['student_name']},\n\n"
        . "Der er nyt i din supportsag {$ticket['ticket_reference']}.\n"
        . $event . "\n\n"
        . $accessNote . "\n"
        . "Elevportal/support: {$link}\n\n"
        . "Del aldrig din adgangskode eller 2-faktor-kode med support.";
    $content = '<p>Der er nyt i din supportsag <strong>' . mailEscape((string) $ticket['ticket_reference']) . '</strong>.</p>'
        . '<p>' . mailEscape($event) . '</p>'
        . '<p>' . mailEscape($accessNote) . '</p>'
        . '<p><a href="' . mailEscape($link) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold">Åbn support</a></p>'
        . '<p>Del aldrig din adgangskode eller 2-faktor-kode med support.</p>';
    $html = emailLayout('Opdatering fra IT-support', (string) $ticket['student_name'], $content, 'Se eller svar på din supportsag.');

    try {
        sendBrandedEmail((string) $ticket['student_email'], $subject, $plain, $html);
        return true;
    } catch (Throwable $exception) {
        error_log('Supportnotifikation kunne ikke sendes til eleven: ' . $exception->getMessage());
        return false;
    }
}
