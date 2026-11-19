<?php
declare(strict_types=1);

require_once __DIR__ . '/php_config.php';

function mailEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smtpRead($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('Ingen gyldig respons fra mailserveren.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        $detail = trim((string) preg_replace('/[\r\n]+/', ' ', $response));
        if ($code === 535) {
            throw new RuntimeException(
                'Gmail afviste login (535). Kontrollér app-adgangskoden til ' .
                MAIL_SENDER . '. Gmail svar: ' . $detail
            );
        }
        throw new RuntimeException('Mailserveren afviste en SMTP-kommando: ' . $detail);
    }
    return $response;
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Kunne ikke sende kommando til mailserveren.');
    }
    return smtpRead($socket, $expectedCodes);
}

function sendBrandedEmail(string $recipient, string $subject, string $plainText, string $html): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Modtagerens e-mailadresse er ugyldig.');
    }
    $passwordFileDirectory = dirname(MAIL_PASSWORD_FILE);
    if (!is_dir($passwordFileDirectory) && !mkdir($passwordFileDirectory, 0700, true) && !is_dir($passwordFileDirectory)) {
        throw new RuntimeException('Kunne ikke oprette AppData-mappen til Gmail-appadgangskoden.');
    }
    if (!is_file(MAIL_PASSWORD_FILE) || !is_readable(MAIL_PASSWORD_FILE)) {
        throw new RuntimeException('Gmail-appadgangskoden mangler i den lokale adgangskodefil: ' . MAIL_PASSWORD_FILE . '. Opret filen, og indsæt den korrekte app-adgangskode.');
    }

    $password = preg_replace('/\s+/', '', (string) file_get_contents(MAIL_PASSWORD_FILE));
    if ($password === '') {
        throw new RuntimeException('Gmail-appadgangskodefilen er tom.');
    }

    $socket = stream_socket_client(
        'tcp://smtp.gmail.com:587',
        $errorNumber,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        throw new RuntimeException('Kunne ikke oprette forbindelse til Gmail SMTP.');
    }

    $boundary = '=_IT_Udlaan_' . bin2hex(random_bytes(16));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $plain = quoted_printable_encode($plainText);
    $plain = preg_replace("/\r?\n/", "\r\n", $plain);
    $html = quoted_printable_encode($html);
    $html = preg_replace("/\r?\n/", "\r\n", $html);
    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . $plain . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . $html . "\r\n"
        . "--$boundary--";

    stream_set_timeout($socket, 15);
    try {
        smtpRead($socket, [220]);
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'STARTTLS', [220]);
        if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new RuntimeException('Kunne ikke starte en sikker TLS-forbindelse til Gmail.');
        }
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode(MAIL_SENDER), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . MAIL_SENDER . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $message = "From: IT-Udlån <" . MAIL_SENDER . ">\r\n"
            . "To: " . $recipient . "\r\n"
            . "Subject: " . $encodedSubject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n"
            . $body;
        $message = preg_replace('/^\./m', '..', $message);
        if (fwrite($socket, $message . "\r\n.\r\n") === false) {
            throw new RuntimeException('Kunne ikke sende e-mailens indhold.');
        }
        smtpRead($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function emailLayout(string $title, string $greeting, string $content, string $footer, string $accentStart = '#0f172a', string $accentEnd = '#1d4ed8', string $softBackground = '#eff6ff'): string
{
    return '<!doctype html><html lang="da"><body style="margin:0;padding:32px;background:radial-gradient(circle at top left, rgba(59,130,246,0.22), transparent 20%),radial-gradient(circle at top right, rgba(168,85,247,0.18), transparent 25%),radial-gradient(circle at bottom left, rgba(14,165,233,0.16), transparent 26%),linear-gradient(135deg,' . $softBackground . ',#f8fafc 42%,#eef2ff);font-family:Arial,sans-serif;color:#0f172a">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-radius:26px;overflow:hidden;box-shadow:0 24px 60px rgba(15,23,42,0.10)">'
        . '<tr><td style="background:linear-gradient(135deg,' . $accentStart . ' 0%,' . $accentEnd . ' 100%);padding:28px 30px 22px;color:#ffffff">'
        . '<div style="font-size:11px;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,255,255,0.82);font-weight:bold;margin-bottom:12px">School IT administration</div>'
        . '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">'
        . '<div style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,0.12);font-size:18px;font-weight:bold;border:1px solid rgba(255,255,255,0.18)">IT</div>'
        . '<div style="font-size:23px;font-weight:bold;letter-spacing:0.4px">IT-Udlån</div>'
        . '</div>'
        . '<div style="font-size:20px;font-weight:bold;line-height:1.4">' . mailEscape($title) . '</div>'
        . '</td></tr><tr><td style="padding:28px 28px 16px;line-height:1.7">'
        . '<p style="margin:0 0 16px;font-size:16px;color:#0f172a">Hej ' . mailEscape($greeting) . ',</p>'
        . '<div style="background:linear-gradient(180deg,#f8fafc,#f1f5f9);border:1px solid #e2e8f0;border-radius:16px;padding:18px 18px 10px;margin:0 0 18px">'
        . $content
        . '</div>'
        . '<p style="margin:16px 0 0;color:#334155;font-size:14px;line-height:1.7">' . mailEscape($footer) . '<br><strong>IT-Udlån</strong></p>'
        . '</td></tr><tr><td style="padding:16px 28px 22px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;line-height:1.6">'
        . 'Automatisk besked fra skolens IT-udlån · ' . mailEscape(MAIL_SENDER)
        . '</td></tr></table></td></tr></table></body></html>';
}

function sendReturnApprovalRequestEmail(array $loan, string $token, string $expiresAt): void
{
    $approvalUrl = APP_BASE_URL . '/godkendelse.php?token=' . rawurlencode($token);
    $plainText = sprintf(
        'Hej %s,\n\n' .
        'Vi har registreret, at du har afleveret computer %s.\n' .
        'For at bekræfte aflevering skal du godkende dette via linket nedenfor.\n' .
        'Hvis du ikke svarer inden %s, vil denne aflevering automatisk blive godkendt som accepteret.\n\n' .
        'Godkend aflevering: %s\n\n' .
        'Hvis du ikke har afleveret computeren, eller hvis du vil forlænge lånet, skal du vælge "afvis / forny" på siden.\n\n' .
        'Venlig hilsen\nIT-Udlån',
        $loan['navn'],
        $loan['computernummer'],
        date('d-m-Y H:i', strtotime($expiresAt)),
        $approvalUrl
    );

    $content = '<p>Vi har registreret, at du har afleveret computer <strong>' . mailEscape((string) $loan['computernummer']) . '</strong>.</p>'
        . '<p>For at bekræfte din aflevering skal du godkende denne registrering.</p>'
        . '<p><strong>Frist:</strong> ' . mailEscape(date('d-m-Y H:i', strtotime($expiresAt))) . '</p>'
        . '<p>Hvis du ikke svarer inden fristen, bliver aflevering automatisk godkendt.</p>'
        . '<p><a href="' . mailEscape($approvalUrl) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold">Godkend eller afvis aflevering</a></p>'
        . '<p>Hvis du ikke har afleveret computeren, eller vil forlænge lånet, kan du afvise og anmode om nyt lån via samme side.</p>';

    $html = emailLayout(
        'Bekræft aflevering af computer',
        (string) $loan['navn'],
        $content,
        'Venlig hilsen',
        '#0f172a',
        '#2563eb',
        '#eff6ff'
    );
    sendBrandedEmail((string) $loan['email'], 'Bekræft aflevering af computer ' . $loan['computernummer'], $plainText, $html);
}

function sendReturnApprovalGroupEmail(array $loans, string $token, string $expiresAt): void
{
    if ($loans === []) {
        throw new RuntimeException('Der er ingen udlån i afleveringsgodkendelsen.');
    }

    $firstLoan = $loans[0];
    $plainItems = [];
    $htmlItems = '';
    foreach ($loans as $loan) {
        if (strcasecmp((string) $loan['email'], (string) $firstLoan['email']) !== 0) {
            throw new RuntimeException('Udlånene i afleveringsgodkendelsen tilhører forskellige e-mailadresser.');
        }
        $computerLabel = 'Computer ' . $loan['computernummer'] . ' (' . $loan['fabrikat'] . ' ' . $loan['model'] . ')';
        $plainItems[] = '- ' . $computerLabel . ', frist ' . $loan['udloebsdato'];
        $htmlItems .= '<li><strong>' . mailEscape($computerLabel) . '</strong> — afleveringsfrist '
            . mailEscape((string) $loan['udloebsdato']) . ', mus: ' . mailEscape((string) $loan['mus']) . '</li>';
    }

    $approvalUrl = APP_BASE_URL . '/godkendelse.php?token=' . rawurlencode($token);
    $expiryLabel = date('d-m-Y H:i', strtotime($expiresAt));
    $plainText = "Vi har registreret aflevering af følgende udstyr:\n"
        . implode("\n", $plainItems)
        . "\n\nGodkend listen samlet eller afvis, hvis noget ikke passer. Ved afvisning forlænges alle lån på listen.\n"
        . "Svar senest " . $expiryLabel . "; derefter godkendes afleveringen automatisk.\n\n"
        . "Godkend eller afvis: " . $approvalUrl . "\n\nVenlig hilsen\nIT-Udlån";
    $content = '<p>Vi har registreret aflevering af følgende udstyr:</p><ul>' . $htmlItems . '</ul>'
        . '<p>Godkend listen samlet, eller afvis, hvis noget ikke passer. Ved afvisning forlænges alle lån på listen.</p>'
        . '<p><strong>Svarfrist:</strong> ' . mailEscape($expiryLabel)
        . '. Hvis du ikke svarer, godkendes afleveringen automatisk.</p>'
        . '<p><a href="' . mailEscape($approvalUrl) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold">Gennemgå afleveringer</a></p>';
    $html = emailLayout(
        'Bekræft flere afleveringer',
        (string) $firstLoan['navn'],
        $content,
        'Venlig hilsen',
        '#0f172a',
        '#2563eb',
        '#eff6ff'
    );
    sendBrandedEmail(
        (string) $firstLoan['email'],
        'Bekræft aflevering af ' . count($loans) . ' lån',
        $plainText,
        $html
    );
}

function sendReminderEmail(array $loan): void
{
    $plainText = sprintf(
        "Hej %s,\n\nDette er en venlig påmindelse om, at du har lånt computer %s.\n" .
        "Computeren skal afleveres senest %s.\n\n" .
        "Hvis du allerede har afleveret computeren, kan du se bort fra denne mail.\n\n" .
        "Venlig hilsen\nIT-Udlån",
        $loan['navn'],
        $loan['computernummer'],
        $loan['udloebsdato']
    );
    $html = emailLayout(
        'Påmindelse om aflevering',
        (string) $loan['navn'],
        '<p>Du har lånt computer <strong>' . mailEscape((string) $loan['computernummer']) . '</strong>.</p>'
            . '<p>Afleveringsfrist: <strong>' . mailEscape((string) $loan['udloebsdato']) . '</strong>.</p>'
            . '<p>Hvis du allerede har afleveret computeren, kan du se bort fra denne mail.</p>',
        'Venlig hilsen',
        '#0f172a',
        '#2563eb',
        '#ecfeff'
    );
    sendBrandedEmail((string) $loan['email'], 'Påmindelse om aflevering af computer', $plainText, $html);
}

function sendLateReturnWarningEmail(array $loan): void
{
    $plainText = sprintf(
        "Hej %s,\n\nDin computer %s er nu forfalden.\n" .
        "Afleveringsfristen var %s, og computeren er stadig ikke registreret som afleveret.\n\n" .
        "Hvis du ikke afleverer udstyret hurtigst muligt, kan din konto blive sat på blacklist, så du ikke kan låne nyt udstyr igen.\n\n" .
        "Venlig hilsen\nIT-Udlån",
        $loan['navn'],
        $loan['computernummer'],
        $loan['udloebsdato']
    );
    $html = emailLayout(
        'Advarsel: aflevering forfalden',
        (string) $loan['navn'],
        '<p>Din computer <strong>' . mailEscape((string) $loan['computernummer']) . '</strong> er nu forfalden.</p>'
            . '<p>Afleveringsfristen var <strong>' . mailEscape((string) $loan['udloebsdato']) . '</strong>.</p>'
            . '<p>Hvis udstyret ikke afleveres hurtigt, kan din konto blive sat på blacklist, så du ikke kan låne nyt udstyr.</p>',
        'Venlig hilsen',
        '#7c2d12',
        '#f59e0b',
        '#fff7ed'
    );
    sendBrandedEmail((string) $loan['email'], 'Advarsel: aflevering forfalden', $plainText, $html);
}

function sendReputationWarningEmail(array $student, int $score): void
{
    $plainText = sprintf(
        "Hej %s,\n\nDu har modtaget en skriftlig advarsel fordi din reputation er nu på %d ud af 100.\n" .
        "Din score er under grænsen på 50, og derfor er der sendt en officiel advarsel til dig.\n\n" .
        "For at forbedre din reputation skal du sikre, at alle dine lånte computere afleveres til tiden.\n" .
        "Hvis du fortsætter med forsenede afleveringer, kan det medføre yderligere konsekvenser, herunder begrænsninger i dine lånerettigheder.\n\n" .
        "Kontakt skolens IT-administrator, hvis du har spørgsmål om, hvordan du kan rette op på det.\n\n" .
        "Venlig hilsen\nIT-Udlån",
        $student['navn'],
        $score
    );

    $content = '<p>Du har modtaget en <strong>skriftlig advarsel</strong>, fordi din reputation nu er <strong>' . $score . ' / 100</strong>.</p>'
        . '<p>Din score er under grænsen på 50, hvilket betyder, at du ikke opfylder skolens krav for rettidig aflevering.</p>'
        . '<p>For at forbedre din score skal du sikre, at alle dine lånte computere afleveres til tiden og i god stand.</p>'
        . '<p>Hvis du fortsætter med forsinkede afleveringer, kan der blive truffet yderligere foranstaltninger, herunder begrænsninger i dine lånerettigheder.</p>'
        . '<div style="background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:12px 14px;margin:14px 0;color:#9a4d00"><strong>Vigtigt:</strong> hvis din reputation kommer over 50 igen, vil advarslen ikke længere være aktiv.</div>'
        . '<p>Kontakt skolens IT-administrator, hvis du vil have hjælp eller rådgivning om, hvordan du gør det bedre fremover.</p>';

    $html = emailLayout(
        'Skriftlig advarsel – lav reputation',
        (string) $student['navn'],
        $content,
        'Vi forventer, at du tager dette seriøst og forbedrer din afleveringstilhørighed.',
        '#7f1d1d',
        '#f97316',
        '#fff1f2'
    );

    sendBrandedEmail((string) $student['email'], 'Skriftlig advarsel: din reputation er for lav', $plainText, $html);
}

function sendBlacklistNotificationEmail(array $loan, string $reason): void
{
    $plainText = sprintf(
        "Hej %s,\n\nDin konto er nu sat på blacklist.\n" .
        "Du kan ikke låne nyt udstyr i skolens IT-udlån, så længe denne begrænsning er aktiv.\n\n" .
        "Årsag: %s\n\nKontakt skolens IT-administrator for at få yderligere information.\n\n" .
        "Venlig hilsen\nIT-Udlån",
        $loan['navn'],
        $reason
    );
    $html = emailLayout(
        'Blacklist – nyt lån er ikke muligt',
        (string) $loan['navn'],
        '<p>Din konto er nu sat på blacklist.</p>'
            . '<p>Du kan ikke låne nyt udstyr i skolens IT-udlån, så længe denne begrænsning er aktiv.</p>'
            . '<p><strong>Årsag:</strong> ' . mailEscape($reason) . '</p>'
            . '<p>Kontakt skolens IT-administrator for at få yderligere information.</p>',
        'Venlig hilsen',
        '#4c1d95',
        '#a855f7',
        '#f5f3ff'
    );
    sendBrandedEmail((string) $loan['email'], 'Blacklist: du kan ikke låne nyt udstyr', $plainText, $html);
}

function buildLoanConfirmationEmail(array $loan): array
{
    $mouse = (string) ($loan['mus'] ?? 'Ikke registreret');
    $plainText = sprintf(
        "Hej %s,\n\nDu har nu lånt følgende udstyr:\nComputer: %s (%s %s)\nMus: %s\n" .
        "Udlånsdato: %s\nAfleveringsfrist: %s\n\nGem denne mail som kvittering.\n\nVenlig hilsen\nIT-Udlån",
        $loan['navn'],
        $loan['computernummer'],
        $loan['fabrikat'],
        $loan['model'],
        $mouse,
        $loan['udlaansdato'],
        $loan['udloebsdato']
    );
    $content = '<p>Dit lån er registreret. Her er din kvittering:</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">'
        . '<tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;color:#64748b">Computer</td><td style="padding:10px;border-bottom:1px solid #e2e8f0;font-weight:bold">'
        . mailEscape((string) $loan['computernummer']) . ' — ' . mailEscape((string) $loan['fabrikat']) . ' ' . mailEscape((string) $loan['model'])
        . '</td></tr><tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;color:#64748b">Mus</td><td style="padding:10px;border-bottom:1px solid #e2e8f0">'
        . mailEscape($mouse)
        . '</td></tr><tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;color:#64748b">Udlånsdato</td><td style="padding:10px;border-bottom:1px solid #e2e8f0">'
        . mailEscape((string) $loan['udlaansdato'])
        . '</td></tr><tr><td style="padding:10px;color:#64748b">Afleveringsfrist</td><td style="padding:10px;font-weight:bold">'
        . mailEscape((string) $loan['udloebsdato']) . '</td></tr></table>'
        . '<p>Gem gerne denne mail som kvittering. Aflever udstyret senest på fristdatoen.</p>';
    $html = emailLayout('Kvittering for udlån', (string) $loan['navn'], $content, 'Tak fordi du passer på skolens udstyr.', '#14532d', '#22c55e', '#f0fdf4');
    return [
        'recipient' => (string) $loan['email'],
        'subject' => 'Kvittering: Du har lånt computer ' . $loan['computernummer'],
        'plain_text' => $plainText,
        'html' => $html,
    ];
}

function sendLoanConfirmationEmail(array $loan): void
{
    $email = buildLoanConfirmationEmail($loan);
    sendBrandedEmail($email['recipient'], $email['subject'], $email['plain_text'], $email['html']);
}

function sendReturnConfirmationEmail(array $loan): void
{
    $item = 'computer ' . $loan['computernummer'] . ' (' . $loan['fabrikat'] . ' ' . $loan['model'] . ')';
    $plainText = sprintf(
        "Hej %s,\n\nVi bekræfter, at vi har registreret din aflevering:\nUdstyr: %s\nMus: %s\nAfleveret: %s\nOprindelig afleveringsfrist: %s\n\nTak fordi du afleverede udstyret.\n\nVenlig hilsen\nIT-Udlån",
        $loan['navn'],
        $item,
        $loan['mus'],
        $loan['afleveringsdato'],
        $loan['udloebsdato']
    );
    $content = '<p>Vi bekræfter, at din aflevering er registreret.</p>'
        . '<p>Udstyr: <strong>' . mailEscape($item) . '</strong><br>'
        . 'Mus: ' . mailEscape((string) $loan['mus']) . '<br>'
        . 'Afleveret: <strong>' . mailEscape((string) $loan['afleveringsdato']) . '</strong><br>'
        . 'Oprindelig afleveringsfrist: ' . mailEscape((string) $loan['udloebsdato']) . '</p>'
        . '<p>Tak fordi du afleverede udstyret.</p>';
    $html = emailLayout('Kvittering for aflevering', (string) $loan['navn'], $content, 'Venlig hilsen', '#0f766e', '#14b8a6', '#ecfeff');
    sendBrandedEmail(
        (string) $loan['email'],
        'Kvittering: Udstyr afleveret – computer ' . $loan['computernummer'],
        $plainText,
        $html
    );
}

function sendLoginOtpEmail(string $recipient, string $fullName, string $otp, string $portalName): void
{
    $plainText = sprintf(
        "Hej %s,\n\nFor at fuldføre dit login til %s skal du bruge følgende 6-cifrede kode:\n%s\n\nKoden er gyldig i 5 minutter. Del den ikke med andre.\n\nVenlig hilsen\nIT-Udlån",
        $fullName,
        $portalName,
        $otp
    );
    $content = '<p>For at fuldføre dit login til <strong>' . mailEscape($portalName) . '</strong> skal du bruge nedenstående kode.</p>'
        . '<p><strong>Bekræftelseskode:</strong> <span style="font-size:28px;font-weight:bold;letter-spacing:3px">' . mailEscape($otp) . '</span></p>'
        . '<p>Koden er gyldig i 5 minutter. Del den ikke med andre.</p>';
    $html = emailLayout(
        'Bekræft dit login',
        $fullName,
        $content,
        'Skolens IT-sikkerhed',
        '#111827',
        '#2563eb',
        '#eff6ff'
    );
    sendBrandedEmail(
        $recipient,
        '2-faktor bekæftelse – ' . $portalName,
        $plainText,
        $html
    );
}

function sendAdminApplicationStatusEmail(string $recipient, string $username, string $status): void
{
    $messages = [
        'received' => [
            'subject' => 'Vi har modtaget din adminansøgning',
            'title' => 'Ansøgning modtaget',
            'plain' => "Vi har modtaget ansøgningen om admin-kontoen %s. Du får besked, når den er behandlet. Kontoen kan først bruges efter godkendelse.",
            'html' => '<p>Vi har modtaget ansøgningen om admin-kontoen <strong>%s</strong>.</p><p>Du får besked, når ansøgningen er behandlet. Kontoen kan først bruges efter godkendelse.</p>',
            'footer' => 'Din ansøgning afventer godkendelse.',
        ],
        'approved' => [
            'subject' => 'Din adminansøgning er godkendt',
            'title' => 'Admin-konto godkendt',
            'plain' => "Din ansøgning om admin-kontoen %s er godkendt. Du kan nu logge ind på %s. En 2-faktor-kode sendes til denne e-mailadresse.",
            'html' => '<p>Din ansøgning om admin-kontoen <strong>%s</strong> er godkendt.</p><p>Du kan nu logge ind på <a href="%s">%s</a>. En 2-faktor-kode sendes til denne e-mailadresse.</p>',
            'footer' => 'Velkommen til IT-Udlån.',
        ],
        'rejected' => [
            'subject' => 'Din adminansøgning er afvist',
            'title' => 'Adminansøgning afvist',
            'plain' => 'Din ansøgning om admin-kontoen %s er desværre ikke blevet godkendt. Kontakt Nikolaj, hvis du mener, at der er sket en fejl.',
            'html' => '<p>Din ansøgning om admin-kontoen <strong>%s</strong> er desværre ikke blevet godkendt.</p><p>Kontakt Nikolaj, hvis du mener, at der er sket en fejl.</p>',
            'footer' => 'Du kan kontakte administratoren, hvis du har spørgsmål.',
        ],
    ];
    if (!isset($messages[$status])) {
        throw new RuntimeException('Ukendt status for adminansøgningens e-mail.');
    }

    $message = $messages[$status];
    $adminLoginUrl = APP_BASE_URL . '/admin_login.php';
    if ($status === 'approved') {
        $plainText = sprintf($message['plain'], $username, $adminLoginUrl);
        $content = sprintf($message['html'], mailEscape($username), mailEscape($adminLoginUrl), mailEscape($adminLoginUrl));
    } else {
        $plainText = sprintf($message['plain'], $username);
        $content = sprintf($message['html'], mailEscape($username));
    }

    $html = emailLayout(
        $message['title'],
        $username,
        $content,
        $message['footer'],
        '#111827',
        '#2563eb',
        '#eff6ff'
    );
    sendBrandedEmail($recipient, $message['subject'], $plainText, $html);
}

function sendStudentPasswordResetEmail(array $student, string $token, string $otp): void
{
    $resetUrl = APP_BASE_URL . '/skift_adgangskode.php?token=' . rawurlencode($token);
    $plainText = sprintf(
        "Hej %s,\n\nDu har anmodet om at ændre adgangskoden til din elevkonto.\nVerifikationskode: %s\n\nKlik på linket nedenfor og indtast koden for at vælge en ny adgangskode:\n%s\n\nLinket og koden er gyldige i 1 time. Hvis du ikke selv har anmodet om dette, kan du ignorere mailen.\n\nVenlig hilsen\nIT-Udlån",
        $student['navn'],
        $otp,
        $resetUrl
    );
    $content = '<p>Du har anmodet om at ændre adgangskoden til din elevkonto.</p>'
        . '<p><strong>Verifikationskode:</strong> <span style="font-size:24px;font-weight:bold;letter-spacing:2px">' . mailEscape($otp) . '</span></p>'
        . '<p>Klik på knappen nedenfor og indtast koden for at vælge en ny adgangskode.</p>'
        . '<p><a href="' . mailEscape($resetUrl) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:12px;padding:12px 20px;font-weight:bold">Skift adgangskode</a></p>'
        . '<p>Linket og koden er gyldige i 1 time. Hvis du ikke selv har anmodet om dette, kan du ignorere mailen.</p>';
    $html = emailLayout(
        'Nulstil din adgangskode',
        (string) $student['navn'],
        $content,
        'Skolens IT-afdeling',
        '#0f172a',
        '#2563eb',
        '#eff6ff'
    );
    sendBrandedEmail(
        (string) $student['email'],
        'Skift din adgangskode – elevportal',
        $plainText,
        $html
    );
}
