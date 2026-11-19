<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/php_config.php';

function gH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gDate(string $value): string
{
    return date('d-m-Y', strtotime($value));
}

$pdo = database();
ensureLoanApprovalTable($pdo);

$token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$notice = null;
$approval = null;
$groupApprovals = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
    $action = $_POST['action'] ?? '';
    if ($token === '' || !is_string($action)) {
        $notice = 'Der mangler oplysninger til at fortsætte.';
    } else {
        $groupQuery = $pdo->prepare(
            'SELECT ag.id AS group_id, ag.status AS group_status, ag.expires_at,
                    a.loan_id, a.status AS approval_status, a.renewed_until,
                    u.elevnummer, u.computernummer, u.udloebsdato, u.afleveringsdato,
                    b.navn, b.email, c.fabrikat, c.model, c.mus
             FROM udlaan_godkendelsesgrupper ag
             JOIN udlaan_godkendelser a ON a.group_id = ag.id
             JOIN udlaan u ON u.id = a.loan_id
             JOIN brugere b ON b.elevnummer = u.elevnummer
             JOIN computere c ON c.computernummer = u.computernummer
             WHERE ag.token = ?
             ORDER BY a.loan_id'
        );
        $groupQuery->execute([$token]);
        $groupApprovals = $groupQuery->fetchAll();
        if ($groupApprovals === []) {
            $groupApprovals = null;
        }

        if ($groupApprovals !== null) {
            $groupStatus = (string) $groupApprovals[0]['group_status'];
            if ($groupStatus !== 'pending') {
                $notice = 'Denne samlede godkendelse er allerede behandlet.';
            } elseif (strtotime((string) $groupApprovals[0]['expires_at']) < time()) {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'UPDATE udlaan_godkendelsesgrupper
                     SET status = "accepted", response_at = NOW(),
                         response_note = "Automatisk godkendt efter frist"
                     WHERE id = ? AND status = "pending"'
                )->execute([$groupApprovals[0]['group_id']]);
                $pdo->prepare(
                    'UPDATE udlaan_godkendelser
                     SET status = "accepted", response_at = NOW(),
                         response_note = "Automatisk godkendt efter frist"
                     WHERE group_id = ? AND status = "pending"'
                )->execute([$groupApprovals[0]['group_id']]);
                $pdo->commit();
                $groupApprovals[0]['group_status'] = 'accepted';
                foreach ($groupApprovals as &$groupApproval) {
                    $groupApproval['approval_status'] = 'accepted';
                    $groupApproval['response_note'] = 'Automatisk godkendt efter frist';
                }
                unset($groupApproval);
                $notice = 'Dine afleveringer blev automatisk godkendt, fordi du ikke svarede inden fristen.';
            } elseif ($action === 'godkend' || $action === 'afvis') {
                $newDueDate = null;
                if ($action === 'afvis') {
                    $newDueDate = trim((string) ($_POST['ny_udloebsdato'] ?? ''));
                    if ($newDueDate === '') {
                        $newDueDate = date('Y-m-d', time() + 7 * 24 * 60 * 60);
                    }
                    $parsedDate = DateTime::createFromFormat('!Y-m-d', $newDueDate);
                    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $newDueDate || $newDueDate < date('Y-m-d')) {
                        $notice = 'Vælg en gyldig afleveringsfrist i dag eller senere.';
                    }
                }

                if ($notice === null) {
                    $pdo->beginTransaction();
                    $lockGroup = $pdo->prepare(
                        'SELECT status FROM udlaan_godkendelsesgrupper WHERE id = ? FOR UPDATE'
                    );
                    $lockGroup->execute([$groupApprovals[0]['group_id']]);
                    $lockedStatus = $lockGroup->fetchColumn();
                    if ($lockedStatus !== 'pending') {
                        $pdo->rollBack();
                        $groupApprovals[0]['group_status'] = (string) $lockedStatus;
                        $notice = 'Denne samlede godkendelse er allerede behandlet.';
                    } elseif ($action === 'godkend') {
                        $pdo->prepare(
                            'UPDATE udlaan_godkendelser
                             SET status = "accepted", response_at = NOW(),
                                 response_note = "Elev godkendte afleveringen"
                             WHERE group_id = ? AND status = "pending"'
                        )->execute([$groupApprovals[0]['group_id']]);
                        $pdo->prepare(
                            'UPDATE udlaan_godkendelsesgrupper
                             SET status = "accepted", response_at = NOW(),
                                 response_note = "Elev godkendte afleveringen"
                             WHERE id = ?'
                        )->execute([$groupApprovals[0]['group_id']]);
                        $pdo->commit();
                        foreach ($groupApprovals as &$groupApproval) {
                            $groupApproval['group_status'] = 'accepted';
                            $groupApproval['approval_status'] = 'accepted';
                            $groupApproval['response_note'] = 'Elev godkendte afleveringen';
                        }
                        unset($groupApproval);
                        $notice = 'Tak. Alle afleveringer på listen er nu godkendt.';
                    } else {
                        $renewLoan = $pdo->prepare(
                            'UPDATE udlaan SET afleveret = 0, afleveringsdato = NULL, udloebsdato = ?
                             WHERE id = ?'
                        );
                        $markComputerLoaned = $pdo->prepare(
                            "UPDATE computere SET status = 'udlånt' WHERE computernummer = ?"
                        );
                        foreach ($groupApprovals as $groupApproval) {
                            $renewLoan->execute([$newDueDate, $groupApproval['loan_id']]);
                            $markComputerLoaned->execute([$groupApproval['computernummer']]);
                        }
                        $pdo->prepare(
                            'UPDATE udlaan_godkendelser
                             SET status = "rejected", response_at = NOW(),
                                 response_note = "Elev afviste afleveringen og anmodede om forlængelse",
                                 renewed_until = ?
                             WHERE group_id = ? AND status = "pending"'
                        )->execute([$newDueDate, $groupApprovals[0]['group_id']]);
                        $pdo->prepare(
                            'UPDATE udlaan_godkendelsesgrupper
                             SET status = "rejected", response_at = NOW(),
                                 response_note = "Elev afviste afleveringen og anmodede om forlængelse",
                                 renewed_until = ?
                             WHERE id = ?'
                        )->execute([$newDueDate, $groupApprovals[0]['group_id']]);
                        $pdo->commit();
                        foreach ($groupApprovals as &$groupApproval) {
                            $groupApproval['group_status'] = 'rejected';
                            $groupApproval['approval_status'] = 'rejected';
                            $groupApproval['renewed_until'] = $newDueDate;
                            $groupApproval['udloebsdato'] = $newDueDate;
                            $groupApproval['response_note'] = 'Elev afviste afleveringen og anmodede om forlængelse';
                        }
                        unset($groupApproval);
                        $notice = 'Afvisningen er registreret. Alle lån på listen er forlænget til ' . gDate($newDueDate) . '.';
                    }
                }
            } else {
                $notice = 'Ukendt handling.';
            }
        } else {
            $approvalQuery = $pdo->prepare(
                'SELECT g.loan_id, g.status, g.expires_at, g.response_at, g.response_note, g.renewed_until,
                        u.elevnummer, u.computernummer, u.udloebsdato, u.afleveringsdato,
                        b.navn, b.email, c.fabrikat, c.model, c.mus
                 FROM udlaan_godkendelser g
                 JOIN udlaan u ON u.id = g.loan_id
                 JOIN brugere b ON b.elevnummer = u.elevnummer
                 JOIN computere c ON c.computernummer = u.computernummer
                 WHERE g.token = ? AND g.group_id IS NULL'
            );
            $approvalQuery->execute([$token]);
            $approval = $approvalQuery->fetch();

            if ($approval === false) {
                $notice = 'Linket er ugyldigt eller er allerede brugt.';
            } elseif ($approval['status'] !== 'pending') {
                $notice = 'Denne godkendelse er allerede behandlet.';
            } elseif (strtotime((string) $approval['expires_at']) < time()) {
                $pdo->prepare(
                    'UPDATE udlaan_godkendelser
                     SET status = "accepted", response_at = NOW(),
                         response_note = "Automatisk godkendt efter frist"
                     WHERE loan_id = ? AND status = "pending"'
                )->execute([$approval['loan_id']]);
                $approval['status'] = 'accepted';
                $approval['response_note'] = 'Automatisk godkendt efter frist';
                $approval['response_at'] = date('Y-m-d H:i:s');
                $notice = 'Din aflevering blev automatisk godkendt, fordi du ikke svarede inden fristen.';
            } elseif ($action === 'godkend') {
                $pdo->prepare(
                    'UPDATE udlaan_godkendelser
                     SET status = "accepted", response_at = NOW(),
                         response_note = "Elev godkendte afleveringen"
                     WHERE loan_id = ? AND status = "pending"'
                )->execute([$approval['loan_id']]);
                $approval['status'] = 'accepted';
                $notice = 'Tak. Din aflevering er nu godkendt og registreret.';
            } elseif ($action === 'afvis') {
                $newDueDate = trim((string) ($_POST['ny_udloebsdato'] ?? ''));
                if ($newDueDate === '') {
                    $newDueDate = date('Y-m-d', time() + 7 * 24 * 60 * 60);
                }
                $parsedDate = DateTime::createFromFormat('!Y-m-d', $newDueDate);
                if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $newDueDate || $newDueDate < date('Y-m-d')) {
                    $notice = 'Vælg en gyldig afleveringsfrist i dag eller senere.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'UPDATE udlaan SET afleveret = 0, afleveringsdato = NULL, udloebsdato = ? WHERE id = ?'
                    )->execute([$newDueDate, $approval['loan_id']]);
                    $pdo->prepare(
                        "UPDATE computere SET status = 'udlånt' WHERE computernummer = ?"
                    )->execute([$approval['computernummer']]);
                    $pdo->prepare(
                        'UPDATE udlaan_godkendelser
                         SET status = "rejected", response_at = NOW(),
                             response_note = "Elev afviste afleveringen og anmodede om forlængelse",
                             renewed_until = ?
                         WHERE loan_id = ?'
                    )->execute([$newDueDate, $approval['loan_id']]);
                    $pdo->commit();
                    $approval['status'] = 'rejected';
                    $approval['renewed_until'] = $newDueDate;
                    $notice = 'Din afvisning er registreret. Det aktive lån er nu forlænget til ' . gDate($newDueDate) . '.';
                }
            } else {
                $notice = 'Ukendt handling.';
            }
        }
    }
}

if ($token !== '' && $groupApprovals === null) {
    $groupQuery = $pdo->prepare(
        'SELECT ag.id AS group_id, ag.status AS group_status, ag.expires_at,
                a.loan_id, a.status AS approval_status, a.renewed_until,
                u.elevnummer, u.computernummer, u.udloebsdato, u.afleveringsdato,
                b.navn, b.email, c.fabrikat, c.model, c.mus
         FROM udlaan_godkendelsesgrupper ag
         JOIN udlaan_godkendelser a ON a.group_id = ag.id
         JOIN udlaan u ON u.id = a.loan_id
         JOIN brugere b ON b.elevnummer = u.elevnummer
         JOIN computere c ON c.computernummer = u.computernummer
         WHERE ag.token = ?
         ORDER BY a.loan_id'
    );
    $groupQuery->execute([$token]);
    $groupApprovals = $groupQuery->fetchAll();
    if ($groupApprovals === []) {
        $groupApprovals = null;
    } elseif (
        $groupApprovals[0]['group_status'] === 'pending'
        && strtotime((string) $groupApprovals[0]['expires_at']) < time()
    ) {
        $pdo->beginTransaction();
        $pdo->prepare(
            'UPDATE udlaan_godkendelsesgrupper
             SET status = "accepted", response_at = NOW(),
                 response_note = "Automatisk godkendt efter frist"
             WHERE id = ? AND status = "pending"'
        )->execute([$groupApprovals[0]['group_id']]);
        $pdo->prepare(
            'UPDATE udlaan_godkendelser
             SET status = "accepted", response_at = NOW(),
                 response_note = "Automatisk godkendt efter frist"
             WHERE group_id = ? AND status = "pending"'
        )->execute([$groupApprovals[0]['group_id']]);
        $pdo->commit();
        $groupApprovals[0]['group_status'] = 'accepted';
        foreach ($groupApprovals as &$groupApproval) {
            $groupApproval['approval_status'] = 'accepted';
        }
        unset($groupApproval);
        $notice = 'Dine afleveringer blev automatisk godkendt, fordi du ikke svarede inden fristen.';
    }
}

if ($token !== '' && $approval === null && $groupApprovals === null) {
    $approvalQuery = $pdo->prepare(
        'SELECT g.loan_id, g.status, g.expires_at, g.response_at, g.response_note, g.renewed_until,
                u.elevnummer, u.computernummer, u.udloebsdato, u.afleveringsdato,
                b.navn, b.email, c.fabrikat, c.model, c.mus
         FROM udlaan_godkendelser g
         JOIN udlaan u ON u.id = g.loan_id
         JOIN brugere b ON b.elevnummer = u.elevnummer
         JOIN computere c ON c.computernummer = u.computernummer
         WHERE g.token = ? AND g.group_id IS NULL'
    );
    $approvalQuery->execute([$token]);
    $approval = $approvalQuery->fetch();
    if ($approval !== false && $approval['status'] === 'pending' && strtotime((string) $approval['expires_at']) < time()) {
        $pdo->prepare(
            'UPDATE udlaan_godkendelser
             SET status = "accepted", response_at = NOW(),
                 response_note = "Automatisk godkendt efter frist"
             WHERE loan_id = ? AND status = "pending"'
        )->execute([$approval['loan_id']]);
        $approval['status'] = 'accepted';
        $approval['response_note'] = 'Automatisk godkendt efter frist';
        $approval['response_at'] = date('Y-m-d H:i:s');
        $notice = 'Din aflevering blev automatisk godkendt, fordi du ikke svarede inden fristen.';
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Godkendelse af aflevering</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(59,130,246,0.18),transparent_25%),radial-gradient(circle_at_bottom_right,_rgba(168,85,247,0.15),transparent_28%),linear-gradient(135deg,_#eff6ff_0%,_#f8fafc_40%,_#eef2ff_100%)] text-slate-800">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center justify-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-[28px] border border-white/70 bg-white/80 shadow-[0_30px_80px_rgba(15,23,42,0.12)] backdrop-blur-xl">
            <div class="bg-gradient-to-r from-slate-950 via-blue-950 to-indigo-950 px-8 py-7 text-white">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">IT-Udlån</p>
                <h1 class="mt-2 text-3xl font-black">Godkendelse af aflevering</h1>
            </div>
            <div class="p-8">
                <?php if ($notice !== null): ?>
                    <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
                        <?= gH($notice) ?>
                    </div>
                <?php endif; ?>

                <?php if ($approval === false || ($approval === null && $groupApprovals === null)): ?>
                    <p class="text-slate-600">Linket er ugyldigt eller er ikke længere aktivt.</p>
                <?php elseif ($groupApprovals !== null): ?>
                    <?php $groupStatus = (string) $groupApprovals[0]['group_status']; ?>
                    <div class="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <p class="text-sm font-bold uppercase tracking-[0.2em] text-slate-500">Låner</p>
                        <div class="mt-3 text-lg font-black"><?= gH($groupApprovals[0]['navn']) ?></div>
                        <div class="mt-1 text-slate-600"><?= gH($groupApprovals[0]['email']) ?></div>
                    </div>

                    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-lg font-bold text-slate-900">Afleveringer (<?= count($groupApprovals) ?>)</h2>
                            <span class="text-sm text-slate-500">Svarfrist: <?= gH(date('d-m-Y H:i', strtotime((string) $groupApprovals[0]['expires_at']))) ?></span>
                        </div>
                        <div class="space-y-3">
                            <?php foreach ($groupApprovals as $groupApproval): ?>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="font-bold text-slate-900">Computer <?= gH((string) $groupApproval['computernummer']) ?></div>
                                            <div class="text-sm text-slate-600"><?= gH($groupApproval['fabrikat'] . ' ' . $groupApproval['model']) ?> · Mus: <?= gH((string) $groupApproval['mus']) ?></div>
                                        </div>
                                        <div class="text-sm text-slate-600">Afleveringsfrist: <?= gH(gDate((string) $groupApproval['udloebsdato'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($groupStatus === 'pending'): ?>
                        <form method="post" class="space-y-5">
                            <input type="hidden" name="token" value="<?= gH((string) $token) ?>">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700">Ny afleveringsfrist for alle lån ved afvisning (valgfrit)</label>
                                <input type="date" name="ny_udloebsdato" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 focus:border-blue-500 focus:bg-white" min="<?= date('Y-m-d') ?>">
                                <p class="mt-2 text-sm text-slate-500">Hvis du afviser, forlænges alle lån på listen til samme dato. Udfyldes feltet ikke, forlænges de med 7 dage.</p>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <button type="submit" name="action" value="godkend" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">
                                    Godkend alle afleveringer
                                </button>
                                <button type="submit" name="action" value="afvis" class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-bold text-amber-900 hover:bg-amber-100">
                                    Afvis alle / forny lån
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            <strong>Status:</strong> <?= gH($groupStatus) ?>
                            <?php if ($groupStatus === 'rejected' && !empty($groupApprovals[0]['renewed_until'])): ?>
                                <div class="mt-2">Alle lån er forlænget til <?= gH(gDate((string) $groupApprovals[0]['renewed_until'])) ?>.</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <p class="text-sm font-bold uppercase tracking-[0.2em] text-slate-500">Låner</p>
                        <div class="mt-3 text-lg font-black"><?= gH($approval['navn']) ?></div>
                        <div class="mt-1 text-slate-600"><?= gH($approval['email']) ?></div>
                    </div>

                    <div class="mb-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Computer</div>
                            <div class="mt-2 text-xl font-black"><?= gH((string) $approval['computernummer']) ?></div>
                            <div class="text-slate-600"><?= gH($approval['fabrikat'] . ' ' . $approval['model']) ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Frist</div>
                            <div class="mt-2 text-xl font-black"><?= gH(gDate((string) $approval['udloebsdato'])) ?></div>
                            <div class="text-slate-600">Godkendelse udløber: <?= gH(gDate((string) $approval['expires_at'])) ?></div>
                        </div>
                    </div>

                    <?php if ($approval['status'] === 'pending'): ?>
                        <form method="post" class="space-y-5">
                            <input type="hidden" name="token" value="<?= gH((string) $token) ?>">

                            <div>
                                <label class="block text-sm font-semibold text-slate-700">Nyt afleveringsdato ved forlængelse (valgfrit)</label>
                                <input type="date" name="ny_udloebsdato" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 focus:border-blue-500 focus:bg-white" min="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <button type="submit" name="action" value="godkend" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">
                                    Godkend aflevering
                                </button>
                                <button type="submit" name="action" value="afvis" class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-bold text-amber-900 hover:bg-amber-100">
                                    Afvis / forny lån
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            <strong>Status:</strong> <?= gH($approval['status']) ?>
                            <?php if (!empty($approval['response_note'])): ?>
                                <div class="mt-2">Kommentar: <?= gH((string) $approval['response_note']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
