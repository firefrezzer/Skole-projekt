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
require_once __DIR__ . '/support_service.php';

function supportH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function supportCsrfToken(): string
{
    if (!isset($_SESSION['support_csrf_token'])) {
        $_SESSION['support_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['support_csrf_token'];
}

function supportFlash(string $category, string $message): void
{
    $_SESSION['support_flashes'][] = ['category' => $category, 'message' => $message];
}

function supportRedirect(?string $token = null, ?int $ticketId = null): never
{
    $embedQuery = isset($_GET['embed']) && $_GET['embed'] === '1' ? '&embed=1' : '';
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        header('Location: support.php?token=' . rawurlencode($token) . $embedQuery);
    } elseif ($ticketId !== null && $ticketId > 0) {
        header('Location: support.php?ticket_id=' . $ticketId . $embedQuery);
    } else {
        header('Location: support.php' . ($embedQuery !== '' ? '?embed=1' : ''));
    }
    exit;
}

function supportMessageLength(string $message): int|false
{
    return preg_match_all('/./us', $message);
}

function supportJsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$pdo = null;
$pageError = null;
try {
    $pdo = database();
    ensureSupportTables($pdo);
    expireIdleSupportTickets($pdo);
} catch (PDOException $exception) {
    error_log('Supportportalen kunne ikke forbinde til databasen: ' . $exception->getMessage());
    $pageError = 'Support er ikke tilgængelig lige nu. Prøv igen senere.';
}

$studentNumber = $_SESSION['elev_elevnummer'] ?? null;
$isLoggedInStudent = is_string($studentNumber) && $studentNumber !== '';
$studentRecord = null;
if ($isLoggedInStudent && $pdo !== null) {
    $studentQuery = $pdo->prepare('SELECT elevnummer, navn, email FROM brugere WHERE elevnummer = ? LIMIT 1');
    $studentQuery->execute([$studentNumber]);
    $studentRecord = $studentQuery->fetch();
    if ($studentRecord === false) {
        unset($_SESSION['elev_elevnummer']);
        $studentNumber = null;
        $isLoggedInStudent = false;
        $studentRecord = null;
    }
}

if (isset($_GET['poll']) && $_GET['poll'] === '1') {
    $pollTicketId = filter_var($_GET['ticket_id'] ?? null, FILTER_VALIDATE_INT);
    $afterMessageId = filter_var($_GET['after'] ?? '0', FILTER_VALIDATE_INT);
    $pollToken = $_GET['token'] ?? '';
    $pollToken = is_string($pollToken) && preg_match('/^[a-f0-9]{64}$/', $pollToken) ? $pollToken : '';

    if ($pollTicketId === false || $pollTicketId === null || $pollTicketId < 1 ||
        $afterMessageId === false || $afterMessageId === null || $afterMessageId < 0) {
        supportJsonResponse(['error' => 'Ugyldig supportsag.'], 400);
    }
    if ($pdo === null) {
        supportJsonResponse(['error' => $pageError ?? 'Support er ikke tilgængelig lige nu.'], 503);
    }

    try {
        if ($isLoggedInStudent) {
            $pollTicketQuery = $pdo->prepare(
                'SELECT id, status FROM support_tickets WHERE id = ? AND student_number = ? LIMIT 1'
            );
            $pollTicketQuery->execute([$pollTicketId, $studentNumber]);
        } elseif ($pollToken !== '') {
            $pollTicketQuery = $pdo->prepare(
                'SELECT id, status FROM support_tickets WHERE id = ? AND public_token_hash = ? LIMIT 1'
            );
            $pollTicketQuery->execute([$pollTicketId, hash('sha256', $pollToken)]);
        } else {
            supportJsonResponse(['error' => 'Supportsagen blev ikke fundet.'], 404);
        }

        $pollTicket = $pollTicketQuery->fetch();
        if ($pollTicket === false) {
            supportJsonResponse(['error' => 'Supportsagen blev ikke fundet.'], 404);
        }

        $pollMessagesQuery = $pdo->prepare(
            'SELECT id, sender_type, message_text, created_at
             FROM support_messages WHERE ticket_id = ? AND id > ? ORDER BY id ASC'
        );
        $pollMessagesQuery->execute([$pollTicketId, $afterMessageId]);
        $pollMessages = array_map(
            static fn (array $message): array => [
                'id' => (int) $message['id'],
                'sender_type' => (string) $message['sender_type'],
                'message_text' => (string) $message['message_text'],
                'created_at' => (string) $message['created_at'],
            ],
            $pollMessagesQuery->fetchAll()
        );
        supportJsonResponse([
            'status' => (string) $pollTicket['status'],
            'messages' => $pollMessages,
        ]);
    } catch (PDOException $exception) {
        error_log('Supportchat kunne ikke hente nye beskeder: ' . $exception->getMessage());
        supportJsonResponse(['error' => 'Supportchatten kan ikke opdateres lige nu.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $token = is_string($token) ? trim($token) : '';
    $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($_POST['csrf_token'] ?? null) ||
        !hash_equals(supportCsrfToken(), (string) $_POST['csrf_token'])) {
        http_response_code(400);
        exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
    }

    $action = $_POST['action'] ?? '';
    if (!is_string($action)) {
        http_response_code(400);
        exit('Ugyldig handling.');
    }

    try {
        if ($pdo === null) {
            throw new RuntimeException($pageError ?? 'Support er ikke tilgængelig lige nu.');
        }

        if ($action === 'create_ticket') {
            if ($isLoggedInStudent && is_array($studentRecord)) {
                $contactStudent = $studentRecord;
            } else {
                $submittedNumber = trim((string) ($_POST['student_number'] ?? ''));
                $submittedEmail = trim((string) ($_POST['student_email'] ?? ''));
                if ($submittedNumber === '' || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Udfyld elevnummer og den e-mailadresse, der er registreret på skolen.');
                }
                $studentQuery = $pdo->prepare(
                    'SELECT elevnummer, navn, email FROM brugere
                     WHERE elevnummer = ? AND LOWER(email) = LOWER(?) LIMIT 1'
                );
                $studentQuery->execute([$submittedNumber, $submittedEmail]);
                $contactStudent = $studentQuery->fetch();
                if ($contactStudent === false) {
                    throw new RuntimeException('Elevnummer og e-mail kunne ikke genkendes. Kontrollér oplysningerne, eller kontakt skolens IT-administrator.');
                }
            }

            $subjectOptions = [
                'adgangskode' => 'Hjælp til adgangskode',
                'konto' => 'Hjælp til elevkonto',
                'laan' => 'Spørgsmål om lån eller aflevering',
                'andet' => 'Andet',
            ];
            $subjectKey = $_POST['subject'] ?? '';
            $message = trim((string) ($_POST['message'] ?? ''));
            $messageLength = supportMessageLength($message);
            if (!is_string($subjectKey) || !array_key_exists($subjectKey, $subjectOptions) ||
                $messageLength === false || $messageLength < 5 || $messageLength > 3000) {
                throw new RuntimeException('Vælg en emnetype, og skriv en besked på 5-3000 tegn.');
            }

            $recentCount = $pdo->prepare(
                'SELECT COUNT(*) FROM support_tickets
                 WHERE student_number = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
            );
            $recentCount->execute([$contactStudent['elevnummer']]);
            if ((int) $recentCount->fetchColumn() >= 3) {
                throw new RuntimeException('Der er oprettet flere supportsager for nylig. Vent lidt, eller fortsæt i en eksisterende sag.');
            }

            $token = bin2hex(random_bytes(32));
            $reference = 'SUP-' . strtoupper(bin2hex(random_bytes(8)));
            $pdo->beginTransaction();
            $insertTicket = $pdo->prepare(
                'INSERT INTO support_tickets
                    (ticket_reference, student_number, student_name, student_email, subject, public_token_hash)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertTicket->execute([
                $reference,
                $contactStudent['elevnummer'],
                $contactStudent['navn'],
                $contactStudent['email'],
                $subjectOptions[$subjectKey],
                hash('sha256', $token),
            ]);
            $ticketId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO support_messages (ticket_id, sender_type, message_text)
                 VALUES (?, 'student', ?)"
            )->execute([$ticketId, $message]);
            $pdo->commit();

            $ticket = [
                'id' => $ticketId,
                'ticket_reference' => $reference,
                'student_number' => (string) $contactStudent['elevnummer'],
                'student_name' => (string) $contactStudent['navn'],
                'student_email' => (string) $contactStudent['email'],
            ];
            $adminNotifications = sendSupportAdminNotification($ticket, 'Ny supportsag');
            $studentNotified = sendSupportStudentNotification($ticket, 'Vi har modtaget din henvendelse.', $token);
            if ($adminNotifications['sent'] === 0 || !$studentNotified) {
                supportFlash('error', 'Sagen blev oprettet, men en e-mailnotifikation kunne ikke sendes. Gem sagslinket, og kontakt skolen, hvis du ikke får svar.');
            } else {
                supportFlash('success', 'Din supportsag er sendt. Supporten får besked på e-mail, og du kan følge sagen her.');
            }
            supportRedirect($token);
        }

        if ($ticketId === false || $ticketId === null || $ticketId < 1) {
            throw new RuntimeException('Supportsagen blev ikke fundet.');
        }
        if ($isLoggedInStudent) {
            $ticketQuery = $pdo->prepare(
                'SELECT * FROM support_tickets WHERE id = ? AND student_number = ? LIMIT 1'
            );
            $ticketQuery->execute([$ticketId, $studentNumber]);
        } elseif (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $ticketQuery = $pdo->prepare(
                'SELECT * FROM support_tickets WHERE id = ? AND public_token_hash = ? LIMIT 1'
            );
            $ticketQuery->execute([$ticketId, hash('sha256', $token)]);
        } else {
            throw new RuntimeException('Du skal åbne sagen via dit sikre sagslink.');
        }
        $ticket = $ticketQuery->fetch();
        if ($ticket === false) {
            throw new RuntimeException('Supportsagen blev ikke fundet på din konto.');
        }
        if ($action === 'delete_ticket') {
            if ($ticket['status'] === 'open') {
                throw new RuntimeException('Åbne sager kan ikke slettes. Markér sagen som løst først.');
            }
            $deleteTicket = $pdo->prepare(
                "DELETE FROM support_tickets WHERE id = ? AND status <> 'open'"
            );
            $deleteTicket->execute([$ticketId]);
            if ($deleteTicket->rowCount() !== 1) {
                throw new RuntimeException('Den afsluttede supportsag kunne ikke slettes.');
            }
            supportFlash('success', 'Den afsluttede supportsag og dens beskeder er slettet.');
            supportRedirect();
        }
        if ($ticket['status'] !== 'open') {
            throw new RuntimeException('Sagen er ikke længere åben. Opret en ny sag, hvis du stadig har brug for hjælp.');
        }

        if ($action === 'reply_ticket') {
            $message = trim((string) ($_POST['message'] ?? ''));
            $messageLength = supportMessageLength($message);
            if ($messageLength === false || $messageLength < 1 || $messageLength > 3000) {
                throw new RuntimeException('Beskeden skal være mellem 1 og 3000 tegn.');
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO support_messages (ticket_id, sender_type, message_text)
                 VALUES (?, 'student', ?)"
            )->execute([$ticketId, $message]);
            $pdo->prepare(
                "UPDATE support_tickets SET last_activity_at = NOW()
                 WHERE id = ? AND status = 'open'"
            )->execute([$ticketId]);
            $pdo->commit();
            $notification = sendSupportAdminNotification($ticket, 'Eleven har svaret');
            supportFlash(
                $notification['sent'] > 0 ? 'success' : 'error',
                $notification['sent'] > 0
                    ? 'Dit svar er sendt til supporten.'
                    : 'Dit svar er gemt, men supporten kunne ikke få e-mail. Sagen kan stadig ses i adminpanelet.'
            );
            supportRedirect($token, (int) $ticket['id']);
        }

        if ($action === 'resolve_ticket') {
            $pdo->prepare(
                "UPDATE support_tickets
                 SET status = 'resolved', closed_at = NOW(), closed_by = 'student'
                 WHERE id = ? AND status = 'open'"
            )->execute([$ticketId]);
            $notification = sendSupportAdminNotification($ticket, 'Eleven har markeret sagen som løst');
            supportFlash(
                $notification['sent'] > 0 ? 'success' : 'error',
                $notification['sent'] > 0
                    ? 'Sagen er markeret som løst.'
                    : 'Sagen er løst, men supporten kunne ikke få e-mail. Status kan ses i adminpanelet.'
            );
            supportRedirect($token, (int) $ticket['id']);
        }

        throw new RuntimeException('Ukendt handling.');
    } catch (Throwable $exception) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Supporthandling fejlede: ' . $exception->getMessage());
        supportFlash('error', $exception instanceof PDOException
            ? 'Beskeden kunne ikke gemmes lige nu. Prøv igen senere.'
            : $exception->getMessage());
        supportRedirect($token, is_int($ticketId) && $ticketId > 0 ? $ticketId : null);
    }
}

$flashes = $_SESSION['support_flashes'] ?? [];
unset($_SESSION['support_flashes']);
$publicToken = $_GET['token'] ?? '';
$publicToken = is_string($publicToken) && preg_match('/^[a-f0-9]{64}$/', $publicToken) ? $publicToken : '';
$selectedId = filter_var($_GET['ticket_id'] ?? null, FILTER_VALIDATE_INT);
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';
$selectedTicket = null;
$tickets = [];
$ticketMessages = [];

if ($pdo !== null) {
    if ($isLoggedInStudent) {
        $ticketList = $pdo->prepare(
            'SELECT id, ticket_reference, subject, status, created_at, last_activity_at
             FROM support_tickets WHERE student_number = ? ORDER BY last_activity_at DESC, id DESC LIMIT 50'
        );
        $ticketList->execute([$studentNumber]);
        $tickets = $ticketList->fetchAll();
    }

    if ($publicToken !== '') {
        $ticketQuery = $pdo->prepare(
            'SELECT * FROM support_tickets WHERE public_token_hash = ? LIMIT 1'
        );
        $ticketQuery->execute([hash('sha256', $publicToken)]);
        $selectedTicket = $ticketQuery->fetch() ?: null;
    } elseif ($isLoggedInStudent && $selectedId !== false && $selectedId !== null) {
        $ticketQuery = $pdo->prepare(
            'SELECT * FROM support_tickets WHERE id = ? AND student_number = ? LIMIT 1'
        );
        $ticketQuery->execute([$selectedId, $studentNumber]);
        $selectedTicket = $ticketQuery->fetch() ?: null;
    } elseif ($isLoggedInStudent && $isEmbedded) {
        $ticketQuery = $pdo->prepare(
            "SELECT * FROM support_tickets WHERE student_number = ?
             ORDER BY CASE status WHEN 'open' THEN 0 ELSE 1 END, last_activity_at DESC, id DESC
             LIMIT 1"
        );
        $ticketQuery->execute([$studentNumber]);
        $selectedTicket = $ticketQuery->fetch() ?: null;
    }

    if (is_array($selectedTicket)) {
        $messageQuery = $pdo->prepare(
            'SELECT id, sender_type, sender_username, message_text, created_at
             FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC'
        );
        $messageQuery->execute([$selectedTicket['id']]);
        $ticketMessages = $messageQuery->fetchAll();
    }
}

$subjectOptions = [
    'adgangskode' => 'Hjælp til adgangskode',
    'konto' => 'Hjælp til elevkonto',
    'laan' => 'Spørgsmål om lån eller aflevering',
    'andet' => 'Andet',
];
$statusLabels = [
    'open' => ['Åben', 'bg-emerald-100 text-emerald-800'],
    'resolved' => ['Løst', 'bg-blue-100 text-blue-800'],
    'inactive' => ['Inaktiv efter stilhed', 'bg-slate-200 text-slate-700'],
];
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support · IT-Udlån</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <?php if (!$isEmbedded): ?>
    <header class="border-b border-white/10 bg-slate-950/90">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-5">
            <a href="<?= $isLoggedInStudent ? 'elev.php' : 'elev_login.php' ?>" class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-lg"><i class="fa-solid fa-headset"></i></span>
                <span><span class="block font-black">IT-Udlån</span><span class="text-xs text-slate-400">Elevsupport</span></span>
            </a>
            <a href="<?= $isLoggedInStudent ? 'elev.php' : 'elev_login.php' ?>" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-bold text-slate-300 transition hover:bg-white/10 hover:text-white">Tilbage til portalen</a>
        </div>
    </header>
    <?php endif; ?>
    <main class="<?= $isEmbedded ? 'mx-auto max-w-5xl px-4 py-5' : 'mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12' ?>">
        <div class="mb-7">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-blue-300">Vi hjælper dig videre</p>
            <h1 class="mt-2 text-3xl font-black text-white sm:text-4xl">Kontakt IT-support</h1>
            <p class="mt-3 max-w-2xl leading-7 text-slate-400">Skriv til skolens support om din konto, adgangskode eller udstyr. Del aldrig din adgangskode eller en 2-faktor-kode.</p>
        </div>

        <?php if ($pageError !== null): ?>
            <div role="alert" class="mb-5 rounded-2xl border border-rose-300/20 bg-rose-400/10 p-4 text-rose-100"><?= supportH($pageError) ?></div>
        <?php endif; ?>
        <?php foreach ($flashes as $flash): ?>
            <div role="alert" class="mb-5 rounded-2xl border p-4 <?= $flash['category'] === 'success' ? 'border-emerald-300/20 bg-emerald-400/10 text-emerald-100' : 'border-rose-300/20 bg-rose-400/10 text-rose-100' ?>"><?= supportH($flash['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($isLoggedInStudent && $tickets !== []): ?>
            <section class="mb-6 rounded-3xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold text-white">Dine supportsager</h2>
                    <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold text-slate-300"><?= count($tickets) ?> seneste</span>
                </div>
                <div class="mt-3 grid gap-2">
                    <?php foreach ($tickets as $ticketRow): ?>
                        <?php $rowStatus = $statusLabels[$ticketRow['status']] ?? ['Ukendt', 'bg-slate-200 text-slate-700']; ?>
                        <a href="support.php?ticket_id=<?= (int) $ticketRow['id'] ?><?= $isEmbedded ? '&embed=1' : '' ?>" class="rounded-2xl border px-3 py-3 text-sm transition hover:bg-white/10 <?= is_array($selectedTicket) && (int) $selectedTicket['id'] === (int) $ticketRow['id'] ? 'border-blue-400/50 bg-blue-500/10 text-white' : 'border-white/10 text-slate-300' ?>">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-bold"><?= supportH($ticketRow['ticket_reference']) ?></span>
                                <span class="rounded-full px-2 py-1 text-[10px] font-bold <?= supportH($rowStatus[1]) ?>"><?= supportH($rowStatus[0]) ?></span>
                            </span>
                            <span class="mt-1 block truncate text-xs text-slate-400"><?= supportH($ticketRow['subject']) ?></span>
                            <time class="mt-1 block text-[10px] text-slate-500"><?= supportH($ticketRow['last_activity_at']) ?></time>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (is_array($selectedTicket)): ?>
            <?php $ticketStatus = $statusLabels[$selectedTicket['status']] ?? ['Ukendt', 'bg-slate-200 text-slate-700']; ?>
            <section id="support-ticket" data-ticket-id="<?= (int) $selectedTicket['id'] ?>" class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900 shadow-2xl">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 p-5 sm:p-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-blue-300"><?= supportH($selectedTicket['ticket_reference']) ?></p>
                        <h2 class="mt-1 text-xl font-black text-white"><?= supportH($selectedTicket['subject']) ?></h2>
                    </div>
                    <span id="support-ticket-status" class="rounded-full px-3 py-1.5 text-xs font-bold <?= supportH($ticketStatus[1]) ?>" data-status="<?= supportH($selectedTicket['status']) ?>"><?= supportH($ticketStatus[0]) ?></span>
                </div>
                <div id="support-ticket-messages" data-last-message-id="<?= $ticketMessages === [] ? 0 : (int) end($ticketMessages)['id'] ?>" class="max-h-[55vh] space-y-3 overflow-y-auto p-5 sm:p-6">
                    <?php foreach ($ticketMessages as $message): ?>
                        <?php $isStudentMessage = $message['sender_type'] === 'student'; ?>
                        <article data-message-id="<?= (int) $message['id'] ?>" class="max-w-[90%] rounded-2xl p-4 <?= $isStudentMessage ? 'ml-auto bg-blue-600 text-white' : 'bg-slate-800 text-slate-100' ?>">
                            <div class="mb-2 flex items-center justify-between gap-4 text-xs <?= $isStudentMessage ? 'text-blue-100' : 'text-slate-400' ?>">
                                <span class="font-bold"><?= $isStudentMessage ? 'Dig' : 'IT-support' ?></span>
                                <time><?= supportH($message['created_at']) ?></time>
                            </div>
                            <p class="whitespace-pre-wrap break-words text-sm leading-6"><?= supportH($message['message_text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p id="support-chat-update-status" class="hidden px-5 pb-3 text-xs text-rose-300" role="status" aria-live="polite"></p>
                <?php if ($selectedTicket['status'] === 'open'): ?>
                    <div class="grid gap-4 border-t border-white/10 p-5 sm:grid-cols-[1fr_auto] sm:p-6">
                    <form id="support-ticket-reply-form" method="post" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= supportH(supportCsrfToken()) ?>">
                            <input type="hidden" name="action" value="reply_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                            <input type="hidden" name="token" value="<?= supportH($publicToken) ?>">
                            <label class="sr-only" for="support-reply">Skriv et svar</label>
                            <textarea id="support-reply" name="message" rows="3" maxlength="3000" required placeholder="Skriv dit svar til supporten…" class="w-full rounded-xl border border-slate-700 bg-slate-950 p-3 text-sm text-white placeholder:text-slate-500 focus:border-blue-400 focus:outline-none"></textarea>
                            <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-blue-700"><i class="fa-solid fa-paper-plane mr-2"></i>Send svar</button>
                        </form>
                        <form id="support-ticket-resolve-form" method="post" class="self-end" onsubmit="return confirm('Markér supportsagen som løst?')">
                            <input type="hidden" name="csrf_token" value="<?= supportH(supportCsrfToken()) ?>">
                            <input type="hidden" name="action" value="resolve_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                            <input type="hidden" name="token" value="<?= supportH($publicToken) ?>">
                            <button type="submit" class="rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-2.5 text-sm font-bold text-emerald-100 transition hover:bg-emerald-400/20">Markér som løst</button>
                        </form>
                    </div>
                <?php endif; ?>
                <p id="support-ticket-closed-message" class="<?= $selectedTicket['status'] === 'open' ? 'hidden ' : '' ?>border-t border-white/10 p-5 text-sm text-slate-400">Sagen er lukket. Opret en ny supportsag, hvis du stadig har brug for hjælp.</p>
            </section>
        <?php endif; ?>
        <?php if (is_array($selectedTicket)): ?>
            <form id="support-ticket-delete-form" method="post" class="<?= $selectedTicket['status'] === 'open' ? 'hidden ' : '' ?>mt-4" onsubmit="return confirm('Slet denne afsluttede supportsag og hele dens beskedhistorik permanent?')">
                <input type="hidden" name="csrf_token" value="<?= supportH(supportCsrfToken()) ?>">
                <input type="hidden" name="action" value="delete_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                <input type="hidden" name="token" value="<?= supportH($publicToken) ?>">
                <button type="submit" class="rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-2.5 text-sm font-bold text-rose-100 transition hover:bg-rose-400/20">
                    <i class="fa-solid fa-trash-can mr-2" aria-hidden="true"></i>Slet afsluttet sag
                </button>
            </form>
        <?php endif; ?>

        <section class="mt-6 rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-xl sm:p-8">
            <div class="mb-5 flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-500/10 text-xl text-blue-300"><i class="fa-solid fa-envelope"></i></span>
                <div>
                    <h2 class="text-xl font-black text-white">Opret en supportsag</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-400">Alle adminer kan se sagen. Vi sender dig et sikkert link, så du kan følge med uden login.</p>
                </div>
            </div>
            <form method="post" class="grid gap-4 md:grid-cols-2">
                <input type="hidden" name="csrf_token" value="<?= supportH(supportCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_ticket">
                <?php if (!$isLoggedInStudent || !is_array($studentRecord)): ?>
                    <label class="block text-sm font-semibold text-slate-200">Elevnummer
                        <input name="student_number" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white">
                    </label>
                    <label class="block text-sm font-semibold text-slate-200">Registreret e-mail
                        <input type="email" name="student_email" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white">
                    </label>
                <?php else: ?>
                    <div class="rounded-xl border border-white/10 bg-slate-950 p-4 text-sm text-slate-300">
                        <p class="font-bold text-white"><?= supportH($studentRecord['navn']) ?></p>
                        <p class="mt-1">Elev <?= supportH($studentRecord['elevnummer']) ?> · <?= supportH($studentRecord['email']) ?></p>
                    </div>
                <?php endif; ?>
                <label class="block text-sm font-semibold text-slate-200 <?= (!$isLoggedInStudent || !is_array($studentRecord)) ? '' : 'md:col-span-2' ?>">Emne
                    <select name="subject" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white">
                        <?php foreach ($subjectOptions as $key => $label): ?>
                            <option value="<?= supportH($key) ?>"><?= supportH($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-200 md:col-span-2">Besked
                    <textarea name="message" rows="5" minlength="5" maxlength="3000" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500" placeholder="Beskriv, hvad du har brug for hjælp til. Skriv aldrig en adgangskode eller 2-faktor-kode."></textarea>
                </label>
                <div class="md:col-span-2">
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 px-5 py-3 font-bold text-white shadow-lg transition hover:brightness-110"><i class="fa-solid fa-paper-plane mr-2"></i>Send til support</button>
                </div>
            </form>
        </section>
        <p class="mt-5 text-center text-xs leading-5 text-slate-500">Åbne sager bliver automatisk inaktive efter 10 minutter uden beskeder. Du eller supporten kan også markere en sag som løst.</p>
    </main>
    <?php if (is_array($selectedTicket)): ?>
        <script>
            (() => {
                const ticket = document.getElementById('support-ticket');
                const messages = document.getElementById('support-ticket-messages');
                const status = document.getElementById('support-ticket-status');
                const updateStatus = document.getElementById('support-chat-update-status');
                const closedMessage = document.getElementById('support-ticket-closed-message');
                const replyForm = document.getElementById('support-ticket-reply-form');
                const resolveForm = document.getElementById('support-ticket-resolve-form');
                const deleteForm = document.getElementById('support-ticket-delete-form');

                if (!ticket || !messages || !status || !updateStatus || !closedMessage) {
                    return;
                }

                let lastMessageId = Number(messages.dataset.lastMessageId || 0);
                const pollUrl = new URL(window.location.href);
                pollUrl.searchParams.set('poll', '1');
                pollUrl.searchParams.set('ticket_id', ticket.dataset.ticketId);
                pollUrl.searchParams.set('after', String(lastMessageId));

                const appendMessage = (message) => {
                    const isStudent = message.sender_type === 'student';
                    const article = document.createElement('article');
                    article.dataset.messageId = String(message.id);
                    article.className = `max-w-[90%] rounded-2xl p-4 ${isStudent ? 'ml-auto bg-blue-600 text-white' : 'bg-slate-800 text-slate-100'}`;

                    const metadata = document.createElement('div');
                    metadata.className = `mb-2 flex items-center justify-between gap-4 text-xs ${isStudent ? 'text-blue-100' : 'text-slate-400'}`;
                    const sender = document.createElement('span');
                    sender.className = 'font-bold';
                    sender.textContent = isStudent ? 'Dig' : 'IT-support';
                    const time = document.createElement('time');
                    time.textContent = message.created_at;
                    metadata.append(sender, time);

                    const text = document.createElement('p');
                    text.className = 'whitespace-pre-wrap break-words text-sm leading-6';
                    text.textContent = message.message_text;
                    article.append(metadata, text);
                    messages.append(article);
                    lastMessageId = Number(message.id);
                };

                const updateTicketStatus = (nextStatus) => {
                    const labels = {
                        open: ['Åben', 'bg-emerald-100 text-emerald-800'],
                        resolved: ['Løst', 'bg-blue-100 text-blue-800'],
                        inactive: ['Inaktiv efter stilhed', 'bg-slate-200 text-slate-700'],
                    };
                    const label = labels[nextStatus];
                    if (!label) {
                        return;
                    }

                    status.textContent = label[0];
                    status.className = `rounded-full px-3 py-1.5 text-xs font-bold ${label[1]}`;
                    status.dataset.status = nextStatus;
                    const isOpen = nextStatus === 'open';
                    if (replyForm) {
                        replyForm.classList.toggle('hidden', !isOpen);
                    }
                    if (resolveForm) {
                        resolveForm.classList.toggle('hidden', !isOpen);
                    }
                    if (deleteForm) {
                        deleteForm.classList.toggle('hidden', isOpen);
                    }
                    closedMessage.classList.toggle('hidden', isOpen);
                };

                const poll = async () => {
                    if (document.hidden) {
                        return;
                    }
                    pollUrl.searchParams.set('after', String(lastMessageId));
                    try {
                        const response = await fetch(pollUrl, {
                            headers: { Accept: 'application/json' },
                            cache: 'no-store',
                        });
                        if (!response.ok) {
                            throw new Error('Supportchatten kunne ikke opdateres.');
                        }
                        const result = await response.json();
                        const wasNearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
                        for (const message of result.messages) {
                            if (Number(message.id) > lastMessageId) {
                                appendMessage(message);
                            }
                        }
                        if (wasNearBottom && result.messages.length > 0) {
                            messages.scrollTop = messages.scrollHeight;
                        }
                        updateTicketStatus(result.status);
                        updateStatus.classList.add('hidden');
                        updateStatus.textContent = '';
                    } catch (error) {
                        updateStatus.textContent = 'Forbindelsen blev afbrudt. Vi prøver igen om lidt.';
                        updateStatus.classList.remove('hidden');
                        console.error(error);
                    }
                };

                messages.scrollTop = messages.scrollHeight;
                window.setInterval(poll, 5000);
            })();
        </script>
    <?php endif; ?>
</body>
</html>
