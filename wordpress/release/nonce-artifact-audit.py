#!/usr/bin/env python3
"""C124-001 static gate for extracted or ZIP-installed candidate bytes."""
import argparse
from pathlib import Path
import re


def audit(candidate):
    text = (candidate / 'includes/free/class-free-admin.php').read_text()
    helper = re.search(r'private static function normalize_nonce\( \$value \): \?string \{(.*?)\n\t\}', text, re.S)
    assert helper and re.fullmatch(r'\s*if \( ! is_string\( \$value \) \) \{ return null; \}\s*return sanitize_text_field\( wp_unslash\( \$value \) \);\s*', helper[1]), 'nonce helper drift'
    assert len(re.findall(r'wp_verify_nonce\(', text)) == 4, 'nonce verification count drift'
    for name in ['gate', 'process_approve', 'process_resume', 'process_undo']:
        body = re.search(r'(?:public|private) static function ' + name + r'\([^\n]*\)[^{\n]*\{(.*?)\n\t\}', text, re.S)
        assert body, name
        assert "$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );" in body[1], name + ' missing normalization'
        assert re.search(r'! is_string\( \$nonce \) \|\| ! wp_verify_nonce\( \$nonce,', body[1]), name + ' raw verification'
    preview = re.search(r'public static function process_preview\([^\n]*\)[^{\n]*\{(.*?)\n\t\}', text, re.S)
    assert preview and 'self::gate( self::ACTION_PREVIEW, $post, $method )' in preview[1], 'preview gate drift'
    for action in ['Preview', 'Approve', 'Resume', 'Undo']:
        print('C124-001 extracted/installed ' + action + ' PASS')
    print('RAW REQUEST NONCE: NONE')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('candidate', type=Path)
    audit(parser.parse_args().candidate)
