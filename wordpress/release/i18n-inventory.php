<?php
/**
 * Preparation-only literal inventory. Not a gettext extractor or catalog.
 *
 * Default mode scans only the files listed in the public distribution manifest
 * (entries are plugin-root-relative; blank lines and '#' comments are skipped),
 * so non-shipped PHP/JS never appears in the inventory. `--all` restores the
 * historical source-tree-wide raw scan for legacy comparison. `--summary`
 * prints only the small per-file text/machine summary used as the committed
 * review artifact.
 *
 * Usage: php i18n-inventory.php [--summary] [--all] [plugin-source-root] [manifest]
 *
 * Exit codes: 0 ok, 2 missing source root, 3 missing/unreadable manifest,
 * 4 missing or unsafe manifest entry, 5 manifest lists no scannable PHP/JS
 * entries, 64 unknown option.
 */
declare(strict_types=1);

const WRITELEASH_I18N_PURPOSE = 'literal-review-candidates-not-gettext-catalog';
const WRITELEASH_I18N_DOMAIN = 'writeleash';
const WRITELEASH_I18N_DEFAULT_ROOT = 'wordpress/writeleash';
const WRITELEASH_I18N_DEFAULT_MANIFEST = 'wordpress/release/writeleash-distribution-files.txt';
const WRITELEASH_I18N_EXTENSIONS = ['php', 'js'];
const WRITELEASH_I18N_REASONS = [
    'empty',
    'symbols-only',
    'numeric',
    'url',
    'html',
    'format-placeholder',
    'code',
    'path',
    'key',
    'selector',
    'identifier',
];
const WRITELEASH_I18N_HEURISTICS = [
    'empty' => 'empty or whitespace-only value',
    'symbols-only' => 'no ASCII letter or digit',
    'numeric' => 'pure number (optional sign/decimal point)',
    'url' => 'URL with scheme or protocol-relative //',
    'html' => 'starts with an HTML tag or character entity',
    'format-placeholder' => 'printf placeholders only, no letters outside them',
    'code' => 'ALLCAPS code token (has underscore or length >= 3)',
    'path' => 'single token containing / and only path characters',
    'key' => 'snake_case/dot/colon/kebab machine key',
    'selector' => 'CSS id/class selector token (#name / .name)',
    'identifier' => 'single-token camelCase or underscored identifier',
];

function writeleash_i18n_fail(int $code, string $message): never
{
    fwrite(STDERR, "i18n-inventory: {$message}\n");
    exit($code);
}

function writeleash_i18n_strip_quotes(string $literal): string
{
    $length = strlen($literal);
    if ($length >= 2) {
        $quote = $literal[0];
        if (($quote === "'" || $quote === '"') && $literal[$length - 1] === $quote) {
            return substr($literal, 1, -1);
        }
    }
    return $literal;
}

/**
 * Conservative classification: 'text' is the default so an uncertain value is
 * shown to the reviewer; 'machine' is only claimed for clearly identifier-like
 * values. Deterministic and documented (see WRITELEASH_I18N_HEURISTICS).
 *
 * @return array{0: 'text'|'machine', 1: string}
 */
function writeleash_i18n_classify(string $literal): array
{
    $value = writeleash_i18n_strip_quotes($literal);
    if ($value === '') {
        return ['machine', 'empty'];
    }
    if (preg_match('/[A-Za-z0-9]/', $value) !== 1) {
        return ['machine', 'symbols-only'];
    }
    if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $value) === 1) {
        return ['machine', 'numeric'];
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//~i', $value) === 1) {
        return ['machine', 'url'];
    }
    if (preg_match('~^<[a-z!/]~i', $value) === 1 || preg_match('/^&(?:[a-z]+|#\d+);/i', $value) === 1) {
        return ['machine', 'html'];
    }
    $withoutPlaceholders = preg_replace('/%(?:\d+\$)?[bcdeEfFgGosuxX]/', '', $value);
    if (is_string($withoutPlaceholders) && $withoutPlaceholders !== $value && preg_match('/[A-Za-z]/', $withoutPlaceholders) !== 1) {
        return ['machine', 'format-placeholder'];
    }
    if (preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1 && (str_contains($value, '_') || strlen($value) >= 3)) {
        return ['machine', 'code'];
    }
    if (strpbrk($value, " \t\r\n") === false) {
        if (str_contains($value, '/') && preg_match('~^[A-Za-z0-9._/-]+$~', $value) === 1) {
            return ['machine', 'path'];
        }
        if (preg_match('/^[a-z0-9]+(?:[._:-][a-z0-9]+)+$/', $value) === 1) {
            return ['machine', 'key'];
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', $value) === 1) {
            return ['machine', 'key'];
        }
        if (preg_match('/^[#.][A-Za-z][A-Za-z0-9_-]*$/', $value) === 1) {
            return ['machine', 'selector'];
        }
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $value) === 1 && (preg_match('/[a-z][A-Z]/', $value) === 1 || str_contains($value, '_'))) {
            return ['machine', 'identifier'];
        }
    }
    return ['text', 'text'];
}

/**
 * Extract raw literal candidates from one file, preserving encounter order
 * within the file (the global sequence keeps the later sort stable).
 *
 * @return list<array{file: string, line: int, literal: string, seq: int}>
 */
function writeleash_i18n_extract(string $path, string $relative, int &$sequence): array
{
    $source = file_get_contents($path);
    if ($source === false) {
        writeleash_i18n_fail(2, "source file unreadable: {$relative}");
    }
    $rows = [];
    if (str_ends_with($path, '.php')) {
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $rows[] = ['file' => $relative, 'line' => $token[2], 'literal' => $token[1], 'seq' => $sequence++];
            }
        }
    } else {
        // Conservative candidates include comments; template interpolation requires manual review.
        preg_match_all('/([\x22\x27])(?:\\\\.|(?!\1)[^\\\\])*?\1/s', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$literal, $offset]) {
            $rows[] = [
                'file' => $relative,
                'line' => 1 + substr_count(substr($source, 0, $offset), "\n"),
                'literal' => $literal,
                'seq' => $sequence++,
            ];
        }
    }
    return $rows;
}

$arguments = array_slice($argv, 1);
$summaryOnly = false;
$all = false;
$positional = [];
foreach ($arguments as $argument) {
    if ($argument === '--summary') {
        $summaryOnly = true;
    } elseif ($argument === '--all') {
        $all = true;
    } elseif (str_starts_with($argument, '--')) {
        writeleash_i18n_fail(64, "unknown option: {$argument}");
    } else {
        $positional[] = $argument;
    }
}
$rootArgument = $positional[0] ?? null;
$manifestArgument = $positional[1] ?? null;

$root = $rootArgument ?? dirname(__DIR__) . '/writeleash';
$rootDeclared = $rootArgument ?? WRITELEASH_I18N_DEFAULT_ROOT;
if (!is_dir($root)) {
    writeleash_i18n_fail(2, "source directory missing: {$root}");
}
$rootReal = realpath($root);
if ($rootReal === false) {
    writeleash_i18n_fail(2, "source directory unreadable: {$root}");
}

$manifest = null;
$manifestDeclared = null;
$manifestEntries = null;
$files = [];
if ($all) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), WRITELEASH_I18N_EXTENSIONS, true)) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    $files = array_map(static fn (string $path): string => substr($path, strlen($rootReal) + 1), $files);
} else {
    $manifest = $manifestArgument ?? dirname(__DIR__) . '/release/writeleash-distribution-files.txt';
    $manifestDeclared = $manifestArgument ?? WRITELEASH_I18N_DEFAULT_MANIFEST;
    if (!is_file($manifest) || !is_readable($manifest)) {
        writeleash_i18n_fail(3, "distribution manifest missing or unreadable: {$manifest}");
    }
    $lines = file($manifest, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        writeleash_i18n_fail(3, "distribution manifest unreadable: {$manifest}");
    }
    $manifestEntries = 0;
    $targets = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $manifestEntries++;
        if (str_starts_with($line, '/') || str_contains($line, '\\') || in_array('..', explode('/', $line), true)) {
            writeleash_i18n_fail(4, "unsafe manifest entry: {$line}");
        }
        if (!is_file($rootReal . '/' . $line)) {
            writeleash_i18n_fail(4, "manifest entry missing under source root: {$line}");
        }
        if (in_array(pathinfo($line, PATHINFO_EXTENSION), WRITELEASH_I18N_EXTENSIONS, true)) {
            $targets[] = $line;
        }
    }
    if ($targets === []) {
        writeleash_i18n_fail(5, 'manifest lists no scannable PHP/JS files');
    }
    sort($targets, SORT_STRING);
    $files = $targets;
}

$rows = [];
$sequence = 0;
foreach ($files as $relative) {
    foreach (writeleash_i18n_extract($rootReal . '/' . $relative, $relative, $sequence) as $row) {
        $rows[] = $row;
    }
}
usort($rows, static function (array $a, array $b): int {
    $byFile = strcmp($a['file'], $b['file']);
    if ($byFile !== 0) {
        return $byFile;
    }
    if ($a['line'] !== $b['line']) {
        return $a['line'] <=> $b['line'];
    }
    return $a['seq'] <=> $b['seq'];
});

$candidates = [];
$textTotal = 0;
$machineTotal = 0;
$reasonTotals = array_fill_keys(WRITELEASH_I18N_REASONS, 0);
$perFile = [];
foreach ($files as $relative) {
    $perFile[$relative] = ['file' => $relative, 'candidates' => 0, 'text' => 0, 'machine' => 0];
}
foreach ($rows as $row) {
    [$class, $reason] = writeleash_i18n_classify($row['literal']);
    if ($class === 'text') {
        $textTotal++;
    } else {
        $machineTotal++;
        $reasonTotals[$reason]++;
    }
    $perFile[$row['file']]['candidates']++;
    $perFile[$row['file']][$class]++;
    $candidates[] = ['file' => $row['file'], 'line' => $row['line'], 'literal' => $row['literal'], 'class' => $class, 'reason' => $reason];
}

$source = [
    'mode' => $all ? 'source-tree' : 'distribution-manifest',
    'root' => $rootDeclared,
    'manifest' => $all ? null : $manifestDeclared,
    'manifest_entries' => $all ? null : $manifestEntries,
    'scanned_files' => count($files),
];

if ($summaryOnly) {
    $document = [
        'format' => 1,
        'purpose' => WRITELEASH_I18N_PURPOSE,
        'text_domain' => WRITELEASH_I18N_DOMAIN,
        'source' => $source,
        'classification' => [
            'text' => $textTotal,
            'machine' => $machineTotal,
            'machine_reasons' => $reasonTotals,
        ],
        'totals' => ['candidates' => count($candidates), 'files' => count($files)],
        'files' => array_values($perFile),
    ];
} else {
    $document = [
        'format' => 2,
        'purpose' => WRITELEASH_I18N_PURPOSE,
        'text_domain' => WRITELEASH_I18N_DOMAIN,
        'source' => $source,
        'classification' => [
            'text' => $textTotal,
            'machine' => $machineTotal,
            'machine_reasons' => $reasonTotals,
            'heuristics' => WRITELEASH_I18N_HEURISTICS,
        ],
        'limitations' => [
            'Manifest-scoped by default: only files listed in the distribution allowlist are scanned; pass --all to reproduce the historical source-tree-wide raw scan.',
            'Classification is a conservative deterministic review aid (text is the default); it is not a reviewed or merchant-facing inventory.',
            'PHP: only T_CONSTANT_ENCAPSED_STRING is captured; interpolated strings and heredocs/nowdocs require manual review.',
            'JavaScript: the conservative quote scan includes comments and can bridge across code (false positives and false negatives); template literals are not captured. make-pot remains the extraction authority.',
            'Manifest blank lines and # comments are skipped; manifest entries without .php/.js are not scanned.',
            'No translation completeness or extractor correctness claim.',
        ],
        'candidates' => $candidates,
    ];
}
echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
