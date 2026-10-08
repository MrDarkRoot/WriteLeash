<?php
/** Preparation-only literal inventory. Not a gettext extractor or catalog. */
declare(strict_types=1);
$root = $argv[1] ?? dirname(__DIR__) . '/writeleash';
if (!is_dir($root)) { fwrite(STDERR, "Source directory missing\n"); exit(2); }
$root = realpath($root);
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'], true)) { $files[] = $file->getPathname(); }
}
sort($files, SORT_STRING);
$rows = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    $relative = substr($file, strlen($root) + 1);
    if (str_ends_with($file, '.php')) {
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $rows[] = ['file' => $relative, 'line' => $token[2], 'literal' => $token[1], 'review' => 'unclassified'];
            }
        }
    } else {
        // Conservative candidates include comments; template interpolation requires manual review.
        preg_match_all('/([\x22\x27])(?:\\\\.|(?!\1)[^\\\\])*?\1/s', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$literal, $offset]) {
            $rows[] = ['file' => $relative, 'line' => 1 + substr_count(substr($source, 0, $offset), "\n"), 'literal' => $literal, 'review' => 'unclassified'];
        }
    }
}
echo json_encode(['format' => 1, 'purpose' => 'literal-review-candidates-not-gettext-catalog', 'text_domain' => 'writeleash', 'limitations' => ['Includes machine strings and markup; review every candidate.', 'PHP interpolated strings and JavaScript template literals require manual inventory.', 'No translation completeness or extractor correctness claim.'], 'candidates' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
