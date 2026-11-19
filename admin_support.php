<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/php_config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/support_service.php';

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php?redirect=' . rawurlencode('admin_support.php'));
    exit;
}

function adminSupportH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminSupportCsrfToken(): string
{
    if (!isset($_SESSION['admin_support_csrf_token'])) {
        $_SESSION['admin_support_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_support_csrf_token'];
}

function adminSupportFlash(string $category, string $message): void
{
    $_SESSION['admin_support_flashes'][] = ['category' => $category, 'message' => $message];
}

function adminSupportRedirect(int $ticketId = 0): never
{
    header('Location: admin_support.php' . ($ticketId > 0 ? '?id=' . $ticketId : ''));
    exit;
}

function adminSupportMessageLength(string $message): int|false
{
    return preg_match_all('/./us', $message);
}

$pdo = null;
try {
    $pdo = database();
    ensureSupportTables($pdo);
    expireIdleSupportTickets($pdo);
} catch (PDOException $exception) {
    error_log('Admin-support kunne ikke forbinde til databasen: ' . $exception->getMessage());
    http_response_code(500);
    exit('Supportoversigten er ikke tilgængelig lige nu.');
}

$selectedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$selectedId = $selectedId === false || $selectedId === null ? 0 : (int) $selectedId;
$adminUsername = (string) ($_SESSION['admin_username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) ||
        !hash_equals(adminSupportCsrfToken(), $csrfToken)) {
        http_response_code(400);
        exit('Formularen er udløbet. Genindlæs siden, og prøv igen.');
    }

    $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
    $ticketId = $ticketId === false || $ticketId === null ? 0 : (int) $ticketId;
    $action = $_POST['action'] ?? '';
    if ($ticketId < 1 || !is_string($action)) {
        adminSupportFlash('error', 'Supportsagen blev ikke fundet.');
        adminSupportRedirect();
    }

    try {
        expireIdleSupportTickets($pdo);
        $pdo->beginTransaction();
        $ticketQuery = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? FOR UPDATE');
        $ticketQuery->execute([$ticketId]);
        $ticket = $ticketQuery->fetch();
        if ($ticket === false) {
            throw new RuntimeException('Supportsagen blev ikke fundet.');
        }
        if ($action === 'delete') {
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
            $pdo->commit();
            adminSupportFlash('success', 'Den afsluttede supportsag og dens beskeder er slettet.');
            adminSupportRedirect();
        }
        if ($ticket['status'] !== 'open') {
            throw new RuntimeException('Sagen er lukket eller inaktiv og kan ikke længere besvares.');
        }

        if ($action === 'reply') {
            $message = trim((string) ($_POST['message'] ?? ''));
            $messageLength = adminSupportMessageLength($message);
            if ($messageLength === false || $messageLength < 1 || $messageLength > 3000) {
                throw new RuntimeException('Beskeden skal være mellem 1 og 3000 tegn.');
            }
            $pdo->prepare(
                "INSERT INTO support_messages (ticket_id, sender_type, sender_username, message_text)
                 VALUES (?, 'admin', ?, ?)"
            )->execute([$ticketId, $adminUsername, $message]);
            $pdo->prepare(
                "UPDATE support_tickets SET last_activity_at = NOW()
                 WHERE id = ? AND status = 'open'"
            )->execute([$ticketId]);
            $pdo->commit();
            $emailSent = sendSupportStudentNotification($ticket, 'Supporten har svaret på din sag.');
            adminSupportFlash(
                $emailSent ? 'success' : 'error',
                $emailSent
                    ? 'Dit svar er gemt, og eleven har fået en e-mailnotifikation.'
                    : 'Dit svar er gemt, men e-mailen til eleven kunne ikke sendes.'
            );
            adminSupportRedirect($ticketId);
        }

        if ($action === 'resolve') {
            $pdo->prepare(
                "UPDATE support_tickets
                 SET status = 'resolved', closed_at = NOW(), closed_by = 'admin'
                 WHERE id = ? AND status = 'open'"
            )->execute([$ticketId]);
            $pdo->commit();
            $emailSent = sendSupportStudentNotification($ticket, 'Supporten har markeret sagen som løst.');
            adminSupportFlash(
                $emailSent ? 'success' : 'error',
                $emailSent
                    ? 'Sagen er markeret som løst, og eleven har fået besked.'
                    : 'Sagen er markeret som løst, men e-mailen til eleven kunne ikke sendes.'
            );
            adminSupportRedirect($ticketId);
        }

        throw new RuntimeException('Ukendt handling.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin-supporthandling fejlede: ' . $exception->getMessage());
        adminSupportFlash('error', $exception instanceof PDOException
            ? 'Supportsagen kunne ikke gemmes lige nu.'
            : $exception->getMessage());
        adminSupportRedirect($ticketId);
    }
}

$flashes = $_SESSION['admin_support_flashes'] ?? [];
unset($_SESSION['admin_support_flashes']);
$tickets = $pdo->query(
    "SELECT id, ticket_reference, student_number, student_name, student_email,
            subject, status, created_at, last_activity_at
     FROM support_tickets
     ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END,
              last_activity_at DESC
     LIMIT 100"
)->fetchAll();
$selectedTicket = null;
$messages = [];
if ($selectedId > 0) {
    $ticketQuery = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? LIMIT 1');
    $ticketQuery->execute([$selectedId]);
    $selectedTicket = $ticketQuery->fetch() ?: null;
    if (is_array($selectedTicket)) {
        $messageQuery = $pdo->prepare(
            'SELECT sender_type, sender_username, message_text, created_at
             FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC'
        );
        $messageQuery->execute([$selectedId]);
        $messages = $messageQuery->fetchAll();
    }
}
$statusLabels = [
    'open' => ['Åben', 'bg-emerald-100 text-emerald-800'],
    'resolved' => ['Løst', 'bg-blue-100 text-blue-800'],
    'inactive' => ['Inaktiv', 'bg-slate-200 text-slate-700'],
];
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elevsupport · IT-Udlån</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 dark:bg-slate-950 dark:text-slate-100">
    <header class="border-b border-slate-200 bg-white/90 shadow-sm dark:border-slate-800 dark:bg-slate-900/90">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-lg text-white"><i class="fa-solid fa-headset"></i></span>
                <div><p class="font-black">IT-Udlån</p><p class="text-xs text-slate-500">Support · <?= adminSupportH($adminUsername) ?></p></div>
            </div>
            <a href="index.php" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Til adminpanelet</a>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-10">
        <div class="mb-6">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-600 dark:text-blue-300">Elevhenvendelser</p>
            <h1 class="mt-2 text-3xl font-black">Supportindbakke</h1>
            <p class="mt-2 text-sm text-slate-500">Åbne sager bliver inaktive efter 10 minutter uden beskeder. Alle adminer kan se og besvare sager.</p>
        </div>
        <?php foreach ($flashes as $flash): ?>
            <div role="alert" class="mb-5 rounded-xl border p-4 text-sm <?= $flash['category'] === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200' : 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200' ?>"><?= adminSupportH($flash['message']) ?></div>
        <?php endforeach; ?>

        <div class="grid gap-6 xl:grid-cols-[360px_1fr]">
            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                    <h2 class="font-black">Sager</h2>
                    <p class="mt-1 text-xs text-slate-500"><?= count($tickets) ?> seneste</p>
                </div>
                <div class="max-h-[70vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    <?php foreach ($tickets as $ticketRow): ?>
                        <?php $ticketStatus = $statusLabels[$ticketRow['status']] ?? ['Ukendt', 'bg-slate-200 text-slate-700']; ?>
                        <a href="admin_support.php?id=<?= (int) $ticketRow['id'] ?>" class="block p-4 transition hover:bg-slate-50 dark:hover:bg-slate-800/70 <?= $selectedId === (int) $ticketRow['id'] ? 'bg-blue-50 dark:bg-blue-950/30' : '' ?>">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-black text-blue-700 dark:text-blue-300"><?= adminSupportH($ticketRow['ticket_reference']) ?></span>
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold <?= adminSupportH($ticketStatus[1]) ?>"><?= adminSupportH($ticketStatus[0]) ?></span>
                            </div>
                            <p class="mt-2 truncate text-sm font-bold"><?= adminSupportH($ticketRow['student_name']) ?> · <?= adminSupportH($ticketRow['student_number']) ?></p>
                            <p class="mt-1 truncate text-xs text-slate-500"><?= adminSupportH($ticketRow['subject']) ?></p>
                            <time class="mt-2 block text-[11px] text-slate-400"><?= adminSupportH($ticketRow['last_activity_at']) ?></time>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($tickets === []): ?>
                        <p class="p-8 text-center text-sm text-slate-500">Der er ingen supportsager endnu.</p>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (is_array($selectedTicket)): ?>
                <?php $selectedStatus = $statusLabels[$selectedTicket['status']] ?? ['Ukendt', 'bg-slate-200 text-slate-700']; ?>
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-slate-800 sm:p-6">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-700 dark:text-blue-300"><?= adminSupportH($selectedTicket['ticket_reference']) ?></p>
                            <h2 class="mt-1 text-xl font-black"><?= adminSupportH($selectedTicket['subject']) ?></h2>
                            <p class="mt-2 text-sm text-slate-500"><?= adminSupportH($selectedTicket['student_name']) ?> · elev <?= adminSupportH($selectedTicket['student_number']) ?> · <?= adminSupportH($selectedTicket['student_email']) ?></p>
                        </div>
                        <span class="rounded-full px-3 py-1.5 text-xs font-bold <?= adminSupportH($selectedStatus[1]) ?>"><?= adminSupportH($selectedStatus[0]) ?></span>
                    </div>
                    <div class="max-h-[55vh] space-y-3 overflow-y-auto p-5 sm:p-6">
                        <?php foreach ($messages as $message): ?>
                            <?php $isAdminMessage = $message['sender_type'] === 'admin'; ?>
                            <article class="max-w-[90%] rounded-2xl p-4 <?= $isAdminMessage ? 'ml-auto bg-blue-600 text-white' : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100' ?>">
                                <div class="mb-2 flex items-center justify-between gap-4 text-xs <?= $isAdminMessage ? 'text-blue-100' : 'text-slate-500 dark:text-slate-400' ?>">
                                    <span class="font-bold"><?= $isAdminMessage ? 'Admin · ' . adminSupportH($message['sender_username'] ?? 'Support') : 'Elev' ?></span>
                                    <time><?= adminSupportH($message['created_at']) ?></time>
                                </div>
                                <p class="whitespace-pre-wrap break-words text-sm leading-6"><?= adminSupportH($message['message_text']) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($selectedTicket['status'] === 'open'): ?>
                        <div class="grid gap-4 border-t border-slate-200 p-5 dark:border-slate-800 sm:grid-cols-[1fr_auto] sm:p-6">
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?= adminSupportH(adminSupportCsrfToken()) ?>">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                                <label class="sr-only" for="admin-support-reply">Svar eleven</label>
                                <textarea id="admin-support-reply" name="message" rows="3" maxlength="3000" required placeholder="Skriv et svar til eleven…" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-white"></textarea>
                                <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-blue-700"><i class="fa-solid fa-paper-plane mr-2"></i>Send svar</button>
                            </form>
                            <form method="post" class="self-end" onsubmit="return confirm('Markér sagen som løst?')">
                                <input type="hidden" name="csrf_token" value="<?= adminSupportH(adminSupportCsrfToken()) ?>">
                                <input type="hidden" name="action" value="resolve">
                                <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                                <button type="submit" class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">Markér som løst</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <p class="border-t border-slate-200 p-5 text-sm text-slate-500 dark:border-slate-800">Sagen er lukket og kan ikke besvares.</p>
                        <form method="post" class="border-t border-slate-200 p-5 dark:border-slate-800" onsubmit="return confirm('Slet denne afsluttede supportsag og hele dens beskedhistorik permanent?')">
                            <input type="hidden" name="csrf_token" value="<?= adminSupportH(adminSupportCsrfToken()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                            <button type="submit" class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-800 transition hover:bg-rose-100 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">Slet afsluttet sag</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="flex min-h-64 items-center justify-center rounded-3xl border border-dashed border-slate-300 bg-white/60 p-8 text-center dark:border-slate-700 dark:bg-slate-900/60">
                    <div><i class="fa-solid fa-comments text-3xl text-blue-500"></i><h2 class="mt-3 font-black">Vælg en supportsag</h2><p class="mt-1 text-sm text-slate-500">Sager åbnes her, så du kan læse og svare eleven.</p></div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
