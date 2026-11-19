<?php
declare(strict_types=1);

function manualInline(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/`(.+?)`/', '<code class="rounded bg-slate-100 px-1.5 py-0.5 text-[0.85em] dark:bg-slate-800">$1</code>', $text);
    $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a class="text-blue-600 hover:underline" href="$2">$1</a>', $text);
    return $text;
}

function manualMarkdownToHtml(string $md): string
{
    $md = str_replace("\r\n", "\n", $md);
    $lines = explode("\n", $md);
    $html = [];
    $inCode = false;
    $codeBuffer = [];
    $inTable = false;
    $inList = false;

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            if ($inCode) {
                $html[] = '<pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-sm text-slate-100"><code>' . implode("\n", $codeBuffer) . '</code></pre>';
                $codeBuffer = [];
                $inCode = false;
            } else {
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $codeBuffer[] = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            continue;
        }

        if (preg_match('/^\|(.+)\|\s*$/', $line, $m)) {
            if (preg_match('/^\|[\s:|-]+\|\s*$/', $line)) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($m[1])));
            if (!$inTable) {
                $html[] = '<div class="overflow-x-auto"><table class="w-full text-left text-sm border-collapse">';
                $html[] = '<thead><tr>';
                foreach ($cells as $cell) {
                    $html[] = '<th class="border border-slate-200 bg-slate-50 px-3 py-2 font-bold dark:border-slate-700 dark:bg-slate-800">' . manualInline($cell) . '</th>';
                }
                $html[] = '</tr></thead><tbody>';
                $inTable = true;
            } else {
                $html[] = '<tr>';
                foreach ($cells as $cell) {
                    $html[] = '<td class="border border-slate-200 px-3 py-2 dark:border-slate-700">' . manualInline($cell) . '</td>';
                }
                $html[] = '</tr>';
            }
            continue;
        }
        if ($inTable) {
            $html[] = '</tbody></table></div>';
            $inTable = false;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $level = strlen($m[1]);
            $sizes = [1 => 'text-3xl', 2 => 'text-2xl', 3 => 'text-xl', 4 => 'text-lg', 5 => 'text-base', 6 => 'text-sm'];
            $html[] = '<h' . $level . ' class="mt-6 mb-2 font-black text-slate-900 dark:text-white ' . ($sizes[$level] ?? 'text-base') . '">' . manualInline($m[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^---+$/', $line)) {
            $html[] = '<hr class="my-6 border-slate-200 dark:border-slate-700">';
            continue;
        }

        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
            if (!$inList) {
                $html[] = '<ul class="list-disc space-y-1 pl-6">';
                $inList = true;
            }
            $html[] = '<li>' . manualInline($m[1]) . '</li>';
            continue;
        }
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }

        if (trim($line) === '') {
            continue;
        }

        $html[] = '<p class="leading-7">' . manualInline($line) . '</p>';
    }

    if ($inCode) {
        $html[] = '<pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-sm text-slate-100"><code>' . implode("\n", $codeBuffer) . '</code></pre>';
    }
    if ($inTable) {
        $html[] = '</tbody></table></div>';
    }
    if ($inList) {
        $html[] = '</ul>';
    }

    return implode("\n", $html);
}

$manualPath = __DIR__ . '/BRUGERMANUAL.md';
$markdown = is_file($manualPath) ? (string) file_get_contents($manualPath) : "# Brugermanual\n\nBrugermanualen kunne ikke findes.";
$content = manualMarkdownToHtml($markdown);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brugermanual · IT-Udlån Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 dark:bg-slate-950 dark:text-slate-100">
    <header class="border-b border-slate-200 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-4">
            <a href="index.php" class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-lg text-white shadow-lg"><i class="fa-solid fa-book"></i></span>
                <span><span class="block text-lg font-black tracking-tight text-slate-900 dark:text-white">Brugermanual</span><span class="text-xs text-slate-500">IT-Udlån Pro</span></span>
            </a>
            <a href="index.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">
                <i class="fa-solid fa-arrow-left"></i> Tilbage
            </a>
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-8">
        <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:p-10">
            <div class="space-y-2 text-slate-700 dark:text-slate-300">
                <?= $content ?>
            </div>
        </article>
        <p class="mt-6 text-center text-sm text-slate-400">
            <a href="index.php" class="text-blue-600 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Tilbage til IT-Udlån Pro</a>
        </p>
    </main>
</body>
</html>