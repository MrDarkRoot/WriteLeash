<?php
declare(strict_types=1);
$tool = dirname(__DIR__, 2) . '/release/i18n-inventory.php';
$base = sys_get_temp_dir() . '/writeleash-i18n-' . bin2hex(random_bytes(6));
mkdir($base);
mkdir($base . '/root');
try {
    file_put_contents($base . '/root/listed.php', "<?php\n// 'comment excluded'\n\$label = 'Visible';\n\$code = 'CONFLICT';\n\$url = 'https://example.com/tools';\n\$format = '%1\$s';\n\$machine_key = 'writeleash_free_apply';\n");
    file_put_contents($base . '/root/listed.js', "const label = 'Refresh';\nconst message = \"Unable to refresh\";\n");
    file_put_contents($base . '/root/unlisted.php', "<?php \$hidden = 'SHOULD_NOT_APPEAR';\n");
    file_put_contents($base . '/root/notes.txt', "not scanned\n");
    file_put_contents($base . '/manifest.txt', "# fixture distribution allowlist\nlisted.php\nlisted.js\nnotes.txt\n");

    $run = function (array $args) use ($tool): array {
        $lines = [];
        $status = -1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null', $lines, $status);
        return [$status, $lines];
    };

    [$status, $lines] = $run([$base . '/root', $base . '/manifest.txt']);
    $data = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    if ($status !== 0 || $data['purpose'] !== 'literal-review-candidates-not-gettext-catalog' || $data['text_domain'] !== 'writeleash') { throw new RuntimeException('inventory envelope mismatch'); }
    if ($data['source']['mode'] !== 'distribution-manifest' || $data['source']['manifest_entries'] !== 3 || $data['source']['scanned_files'] !== 2) { throw new RuntimeException('manifest scope metadata mismatch'); }
    if (count($data['candidates']) !== 7) { throw new RuntimeException('manifest candidate count mismatch'); }
    $files = array_values(array_unique(array_column($data['candidates'], 'file')));
    sort($files, SORT_STRING);
    if ($files !== ['listed.js', 'listed.php']) { throw new RuntimeException('manifest scoping mismatch (unlisted file leaked)'); }

    $php = array_values(array_filter($data['candidates'], fn($r) => $r['file'] === 'listed.php'));
    if (count($php) !== 5 || $php[0]['line'] !== 3 || $php[0]['literal'] !== "'Visible'" || $php[0]['class'] !== 'text' || $php[0]['reason'] !== 'text') { throw new RuntimeException('php source line/classification mismatch'); }
    if ($php[1]['literal'] !== "'CONFLICT'" || $php[1]['class'] !== 'machine' || $php[1]['reason'] !== 'code') { throw new RuntimeException('ALLCAPS code classification mismatch'); }
    if ($php[2]['class'] !== 'machine' || $php[2]['reason'] !== 'url') { throw new RuntimeException('URL classification mismatch'); }
    if ($php[3]['class'] !== 'machine' || $php[3]['reason'] !== 'format-placeholder') { throw new RuntimeException('printf-only classification mismatch'); }
    if ($php[4]['class'] !== 'machine' || $php[4]['reason'] !== 'key') { throw new RuntimeException('machine key classification mismatch'); }
    $js = array_values(array_filter($data['candidates'], fn($r) => $r['file'] === 'listed.js'));
    if (count($js) !== 2 || $js[0]['literal'] !== "'Refresh'" || $js[0]['class'] !== 'text' || $js[1]['class'] !== 'text') { throw new RuntimeException('JS candidates/classification mismatch'); }

    [$againStatus, $again] = $run([$base . '/root', $base . '/manifest.txt']);
    if ($again !== $lines || $againStatus !== 0) { throw new RuntimeException('nondeterministic inventory output'); }

    [$sumStatus, $sumLines] = $run(['--summary', $base . '/root', $base . '/manifest.txt']);
    $summary = json_decode(implode("\n", $sumLines), true, 512, JSON_THROW_ON_ERROR);
    if ($sumStatus !== 0 || $summary['totals'] !== ['candidates' => 7, 'files' => 2]) { throw new RuntimeException('summary totals mismatch'); }
    if ($summary['classification']['text'] !== 3 || $summary['classification']['machine'] !== 4) { throw new RuntimeException('summary text/machine totals mismatch'); }
    $perFile = [];
    foreach ($summary['files'] as $entry) { $perFile[$entry['file']] = $entry; }
    if ($perFile['listed.php'] !== ['file' => 'listed.php', 'candidates' => 5, 'text' => 1, 'machine' => 4] || $perFile['listed.js'] !== ['file' => 'listed.js', 'candidates' => 2, 'text' => 2, 'machine' => 0]) { throw new RuntimeException('summary per-file counts mismatch'); }
    if (count($summary['files']) !== 2) { throw new RuntimeException('summary must list every scanned file'); }
    [, $sumAgain] = $run(['--summary', $base . '/root', $base . '/manifest.txt']);
    if ($sumAgain !== $sumLines) { throw new RuntimeException('nondeterministic summary output'); }

    [$allStatus, $allLines] = $run([$base . '/root', '--all']);
    $allData = json_decode(implode("\n", $allLines), true, 512, JSON_THROW_ON_ERROR);
    if ($allStatus !== 0 || $allData['source']['mode'] !== 'source-tree' || $allData['source']['manifest'] !== null || count($allData['candidates']) !== 8) { throw new RuntimeException('--all raw scan mismatch'); }
    if (!in_array('unlisted.php', array_column($allData['candidates'], 'file'), true)) { throw new RuntimeException('--all must scan the full source tree'); }

    [$missingRootStatus] = $run([$base . '/missing-root', $base . '/manifest.txt']);
    if ($missingRootStatus !== 2) { throw new RuntimeException('missing root accepted'); }

    [$missingManifestStatus] = $run([$base . '/root', $base . '/missing-manifest.txt']);
    if ($missingManifestStatus !== 3) { throw new RuntimeException('missing manifest accepted'); }

    file_put_contents($base . '/manifest-missing-entry.txt', "listed.php\ngone.php\n");
    [$missingEntryStatus] = $run([$base . '/root', $base . '/manifest-missing-entry.txt']);
    if ($missingEntryStatus !== 4) { throw new RuntimeException('missing manifest entry accepted'); }

    file_put_contents($base . '/manifest-unsafe.txt', "../escape.php\n");
    [$unsafeStatus] = $run([$base . '/root', $base . '/manifest-unsafe.txt']);
    if ($unsafeStatus !== 4) { throw new RuntimeException('unsafe manifest entry accepted'); }

    file_put_contents($base . '/manifest-empty.txt', "# only comments\nnotes.txt\n");
    [$emptyStatus] = $run([$base . '/root', $base . '/manifest-empty.txt']);
    if ($emptyStatus !== 5) { throw new RuntimeException('manifest without scannable files accepted'); }

    [$unknownStatus] = $run([$base . '/root', '--bogus']);
    if ($unknownStatus !== 64) { throw new RuntimeException('unknown option accepted'); }

    echo "i18n literal inventory: PASS (manifest scoping, PHP comments, source lines, JS candidates, text/machine classification, summary counts, determinism, --all raw scan, missing root/manifest/entry, unsafe entry, empty manifest, unknown option)\n";
} finally {
    foreach (['listed.php', 'listed.js', 'unlisted.php', 'notes.txt'] as $name) { @unlink($base . '/root/' . $name); }
    @rmdir($base . '/root');
    foreach (['manifest.txt', 'manifest-missing-entry.txt', 'manifest-unsafe.txt', 'manifest-empty.txt'] as $name) { @unlink($base . '/' . $name); }
    @rmdir($base);
}
