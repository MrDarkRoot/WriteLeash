<?php
declare(strict_types=1);
$tool = dirname(__DIR__, 2) . '/release/i18n-inventory.php';
$root = sys_get_temp_dir() . '/writeleash-i18n-' . bin2hex(random_bytes(6));
mkdir($root);
try {
    file_put_contents($root . '/sample.php', "<?php\n// 'comment excluded'\n\$label = 'Visible';\n\$code = 'CONFLICT';\n");
    file_put_contents($root . '/sample.js', "const label = 'Refresh';\nconst message = \"Unable to refresh\";\n");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($root), $lines, $status);
    $data = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    if ($status !== 0 || count($data['candidates']) !== 4 || $data['purpose'] !== 'literal-review-candidates-not-gettext-catalog' || $data['text_domain'] !== 'writeleash') { throw new RuntimeException('inventory mismatch'); }
    $php = array_values(array_filter($data['candidates'], fn($r) => $r['file'] === 'sample.php'));
    if ($php[0]['line'] !== 3 || $php[1]['literal'] !== "'CONFLICT'" || $php[1]['review'] !== 'unclassified') { throw new RuntimeException('line/machine classification mismatch'); }
    $again = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($root), $again, $status);
    if ($again !== $lines || $status !== 0) { throw new RuntimeException('nondeterministic output'); }
    $missing = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($root . '/missing') . ' 2>/dev/null', $missing, $status);
    if ($status !== 2) { throw new RuntimeException('missing root accepted'); }
    echo "i18n literal inventory: PASS (PHP comments, source lines, JS candidates, machine-string review, determinism, missing root)\n";
} finally { unlink($root . '/sample.php'); unlink($root . '/sample.js'); rmdir($root); }
