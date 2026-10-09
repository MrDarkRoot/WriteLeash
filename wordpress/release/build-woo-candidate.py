#!/usr/bin/env python3
"""Local-only Woo Marketplace review-candidate builder (#211); never publishes.

Narrowly scoped companion to build-wordpress-org.py. That historical v0.1
builder stays immutable: it still pins SOURCE 6f8ae7d, 42 files and 0.1.0.
This script reuses its shared deterministic ZIP behavior (stable ordering,
archive paths, timestamps, permissions, no compression, blob-pinned payload)
for the new candidate and binds each build to an explicit clean source SHA
plus the proposed candidate version below.

Candidate source binding: the caller passes --sha; the builder requires the
source checkout HEAD to equal it and the tree to be clean (no dirty,
untracked or ignored input), then reads every payload byte from committed
blobs and verifies the worktree matches. evidence.json records the exact
SOURCE_GIT_SHA, manifest hash, runtime tree hash and ZIP digest, so a
candidate is identified by (SHA, version, ZIP SHA-256) together.
"""
import argparse
import importlib.util
import json
import re
import shutil
import stat
import subprocess
import sys
import zipfile
from pathlib import Path

CANDIDATE_VERSION = '0.2.0'
MANIFEST = 'wordpress/release/writeleash-woo-distribution-files.txt'
EXPECTED_COUNT = 45

_HERE = Path(__file__).resolve().parent
_SPEC = importlib.util.spec_from_file_location(
    'build_wordpress_org', _HERE / 'build-wordpress-org.py')
_ORG = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_ORG)

STAMP = _ORG.STAMP


def require(ok, message):
    if not ok:
        raise ValueError(message)


def read_manifest(data):
    lines = data.decode().splitlines()
    require(all(x == x.strip() for x in lines), 'manifest whitespace')
    paths = [x for x in lines if x and not x.startswith('#')]
    require(paths == sorted(set(paths)) and len(paths) == EXPECTED_COUNT,
            'manifest count/order/duplicates')
    require(paths.count('includes/free/admin-logo.png') == 1,
            'missing runtime Admin logo')
    require(paths.count('changelog.txt') == 1, 'missing Woo changelog.txt')
    require(all(re.fullmatch(r'[A-Za-z0-9_.\-/]+', x) and
                not x.startswith('/') and '..' not in x and
                all(p not in ('', '.') for p in x.split('/')) for x in paths),
            'unsafe manifest')
    return paths


def metadata(payload):
    main = payload['writeleash.php'].decode()
    readme = payload['readme.txt'].decode()
    changelog = payload['changelog.txt'].decode()
    for key, value in {
            'Plugin Name': 'WriteLeash',
            'Plugin URI': 'https://github.com/MrDarkRoot/WriteLeash',
            'Version': CANDIDATE_VERSION,
            'Author': 'Duy Tran',
            'Author URI': 'https://profiles.wordpress.org/duyytrann',
            'Developer': 'Duy Tran',
            'Developer URI': 'https://profiles.wordpress.org/duyytrann',
            'Text Domain': 'writeleash',
            'Requires Plugins': 'woocommerce',
            'Requires at least': '7.0',
            'Tested up to': '7.1',
            'Requires PHP': '7.4',
            'WC requires at least': '10.0',
            'WC tested up to': '11.1',
            'License': 'GPL v2 or later'}.items():
        require(re.search(r'^\s*\*\s*' + re.escape(key) + r':\s*' +
                          re.escape(value) + r'\s*$', main, re.M),
                'plugin metadata mismatch: ' + key)
    # The Woo deployment identifier is assigned automatically on submission;
    # a pre-submission candidate must not guess or hand-supply it.
    require(not re.search(r'^\s*\*\s*Woo\s*:', main, re.M),
            'manually supplied Woo deployment identifier')
    require(_ORG.sha(main.encode()) != _ORG.sha(b''),
            'empty plugin entry point')
    require(re.search(r'^Tested up to:\s*7\.1\s*$', readme, re.M),
            'tested-up-to mismatch')
    require(re.search(r'^Stable tag:\s*' + re.escape(CANDIDATE_VERSION) +
                      r'\s*$', readme, re.M), 'stable-tag mismatch')
    require(re.search(
        r'^\* WooCommerce 10\.0 through 11\.x \(version 10\.0\.0 or newer, '
        r'below 12\.0\.0\)\.\s*$', readme, re.M),
        'Woo support contract mismatch')
    require(re.search(r"^define\( 'WRITELEASH_VERSION', '" +
                      re.escape(CANDIDATE_VERSION) + r"' \);",
                      main, re.M), 'runtime version constant mismatch')
    require(sum(bool(re.search(rb'^\s*\*?\s*Plugin Name:', d, re.M))
                for p, d in payload.items() if p.endswith('.php')) == 1,
            'duplicate plugin header')
    require(changelog.startswith('*** WriteLeash Changelog ***\n'),
            'changelog title mismatch')
    require(re.search(r'^2026-\d\d-\d\d - version ' +
                      re.escape(CANDIDATE_VERSION) + r'\s*$', changelog, re.M),
            'changelog candidate version entry missing')


def audit_zip(path, expected):
    with zipfile.ZipFile(path) as z:
        entries = z.infolist()
        require([i.filename for i in entries] ==
                ['writeleash/' + p for p in sorted(expected)],
                'ZIP root/path/order/duplicate/extra/missing mismatch')
        actual = {}
        for i in entries:
            require(i.date_time == STAMP and i.create_system == 3 and
                    i.external_attr == (stat.S_IFREG | 0o644) << 16 and
                    not i.extra and not i.comment and not i.flag_bits and
                    i.compress_type == zipfile.ZIP_STORED,
                    'ZIP metadata mismatch')
            actual[i.filename[len('writeleash/'):]] = z.read(i)
        require(not z.comment and actual == expected, 'ZIP bytes mismatch')
    metadata(actual)
    _ORG.scan(actual)
    return actual


def build(root, requested, output):
    root = root.resolve()
    output = output.resolve()
    head = _ORG.git(root, 'rev-parse', 'HEAD').decode().strip()
    require(head == requested, 'source checkout HEAD is not the requested SHA')
    require(not _ORG.git(root, 'status', '--porcelain',
                         '--untracked-files=all', '--ignored'),
            'dirty tree or untracked/ignored input')
    require(not output.is_relative_to(root),
            'output must be outside source checkout')
    require(not output.exists(), 'output already exists')
    manifest = _ORG.blob(root, MANIFEST)
    paths = read_manifest(manifest)
    runtime = {p: _ORG.blob(root, 'wordpress/writeleash/' + p)
               for p in paths}
    metadata(runtime)
    _ORG.scan(runtime)
    output.mkdir()
    try:
        archive = output / f'writeleash-{CANDIDATE_VERSION}.zip'
        with zipfile.ZipFile(archive, 'w',
                              compression=zipfile.ZIP_STORED) as z:
            for p, data in sorted(runtime.items()):
                info = zipfile.ZipInfo('writeleash/' + p, STAMP)
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                z.writestr(info, data)
        extracted = audit_zip(archive, runtime)
        _ORG.write_tree(output / 'extracted/writeleash', extracted)
        require(_ORG.tree(output / 'extracted/writeleash') == extracted,
                'extracted/installed tree mismatch')
        audits = Path(__file__).resolve().parents[1] / 'tests/release'
        candidate = output / 'extracted/writeleash'
        for name, args in [('package-preflight.php',
                            [root / MANIFEST,
                             f'--version={CANDIDATE_VERSION}']),
                           ('inventory-audit.php',
                            [root / 'wordpress/release/PUBLIC-PAYLOAD.md',
                             root / MANIFEST, '--candidate']),
                           ('source-audit.php', []),
                           ('readme-validate.php', []),
                           ('woo-candidate.php',
                            [root / MANIFEST, requested, CANDIDATE_VERSION,
                             '--exact'])]:
            subprocess.run(['php', str(audits / name), str(candidate),
                            *map(str, args)], check=True)
        # Re-verify the frozen source after the gates: no gate may mutate it.
        require(_ORG.git(root, 'rev-parse', 'HEAD').decode().strip() ==
                requested, 'source HEAD moved during build')
        require(not _ORG.git(root, 'status', '--porcelain',
                             '--untracked-files=all', '--ignored'),
                'source tree dirtied during build')
        evidence = {
            'SOURCE_GIT_SHA': requested, 'VERSION': CANDIDATE_VERSION,
            'STABLE_TAG': CANDIDATE_VERSION,
            'PUBLIC_MANIFEST_SHA256': _ORG.sha(manifest),
            'ZIP_SHA256': _ORG.sha(archive.read_bytes()),
            'ZIP_SIZE': archive.stat().st_size,
            'RUNTIME_FILE_COUNT': len(runtime),
            'RUNTIME_TREE_HASH': _ORG.tree_hash(runtime),
            'RUNTIME_FILES': _ORG.inventory(runtime),
            'BUILD_COMMAND':
                f'python3 wordpress/release/build-woo-candidate.py '
                f'--source source --sha {requested} --output candidate',
            'TOOLS': {
                'python': sys.version.split()[0],
                'archive': 'Python stdlib zipfile; ZIP_STORED; no '
                           'compression dependency',
                'git': _ORG.git(root, '--version').decode().strip(),
                'php': subprocess.check_output(
                    ['php', '-r', 'echo PHP_VERSION;']).decode()},
            'ZIP_ENTRY_AUDIT': 'PASS', 'ZIP_PUBLIC_CLOSURE': 'PASS',
            'FORBIDDEN_PAYLOAD_SCAN': 'PASS', 'PAYLOAD_EQUIVALENCE': 'PASS'}
        (output / 'evidence.json').write_text(json.dumps(evidence, indent=2)
                                              + '\n')
        return evidence
    except Exception:
        shutil.rmtree(output)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        result = build(args.source, args.sha, args.output)
        print('ZIP_SHA256=' + result['ZIP_SHA256'])
    except (ValueError, subprocess.CalledProcessError, OSError,
            KeyError) as error:
        # Never print subprocess/blob data or credential values.
        print('BLOCKED: ' + (str(error) if isinstance(error, ValueError)
                             else type(error).__name__), file=sys.stderr)
        sys.exit(1)
