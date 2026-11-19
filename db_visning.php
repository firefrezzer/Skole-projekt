<?php
require __DIR__ . '/php_config.php';
require __DIR__ . '/database.php';

header('Content-Type: text/html; charset=utf-8');
$pdo = database();

$users = $pdo->query(
    "SELECT elevnummer, navn, email, stamklasse, rolle, blacklisted, adgangskode_hash IS NOT NULL AS konto_oprettet
     FROM brugere ORDER BY rolle, navn"
)->fetchAll();

$admins = $pdo->query("SELECT id, username FROM admins ORDER BY username")->fetchAll();

$adminRegs = $pdo->query("SELECT id, username, email, created_at FROM admin_registrations ORDER BY id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database-indhold — IT-Udlån</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 p-8 text-slate-800">
    <div class="mx-auto max-w-5xl space-y-8">
        <h1 class="text-3xl font-black">Database-indhold</h1>
        <p class="text-sm text-slate-500">Denne side viser alt indhold i databasen lige nu. Opdater siden (F5) efter at have oprettet en konto for at se ændringer.</p>

        <section class="rounded-2xl bg-white p-6 shadow">
            <h2 class="mb-4 text-xl font-bold">Brugere (brugere-tabellen) — <?= count($users) ?> i alt</h2>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Elevnummer</th>
                        <th class="p-3">Navn</th>
                        <th class="p-3">E-mail</th>
                        <th class="p-3">Klasse</th>
                        <th class="p-3">Rolle</th>
                        <th class="p-3">Konto oprettet</th>
                        <th class="p-3">Blacklistet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="p-3 font-bold text-blue-600"><?= htmlspecialchars($user['elevnummer']) ?></td>
                        <td class="p-3 font-semibold"><?= htmlspecialchars($user['navn']) ?></td>
                        <td class="p-3 text-slate-600"><?= htmlspecialchars($user['email']) ?></td>
                        <td class="p-3"><?= htmlspecialchars($user['stamklasse']) ?></td>
                        <td class="p-3"><span class="rounded-full px-2 py-1 text-xs font-bold <?= $user['rolle'] === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>"><?= htmlspecialchars($user['rolle']) ?></span></td>
                        <td class="p-3"><?= (int)$user['konto_oprettet'] === 1 ? '<span class="text-emerald-600 font-bold">Ja</span>' : '<span class="text-slate-400">Nej</span>' ?></td>
                        <td class="p-3"><?= (int)$user['blacklisted'] === 1 ? '<span class="text-rose-600 font-bold">Ja</span>' : '<span class="text-slate-400">Nej</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                    <tr><td colspan="7" class="p-6 text-center text-slate-400">Ingen brugere i databasen.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow">
            <h2 class="mb-4 text-xl font-bold">Admin-konti (admins-tabellen) — <?= count($admins) ?> i alt</h2>
            <?php if ($admins): ?>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr><th class="p-3">ID</th><th class="p-3">Brugernavn</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($admins as $admin): ?>
                    <tr><td class="p-3"><?= (int)$admin['id'] ?></td><td class="p-3 font-semibold"><?= htmlspecialchars($admin['username']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-slate-400">Ingen admins i admins-tabellen.</p>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow">
            <h2 class="mb-4 text-xl font-bold">Admin-ansøgninger (admin_registrations) — <?= count($adminRegs) ?> i alt</h2>
            <?php if ($adminRegs): ?>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr><th class="p-3">ID</th><th class="p-3">Brugernavn</th><th class="p-3">E-mail</th><th class="p-3">Oprettet</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($adminRegs as $reg): ?>
                    <tr><td class="p-3"><?= (int)$reg['id'] ?></td><td class="p-3 font-semibold"><?= htmlspecialchars($reg['username']) ?></td><td class="p-3 text-slate-600"><?= htmlspecialchars($reg['email']) ?></td><td class="p-3 text-xs"><?= htmlspecialchars((string)$reg['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-slate-400">Ingen admin-ansøgninger.</p>
            <?php endif; ?>
        </section>

        <p class="text-center text-sm text-slate-400"><a href="index.php?view=brugere" class="text-blue-600 hover:underline">← Tilbage til elevregisteret</a></p>
    </div>
</body>
</html>