#!/usr/bin/env python3
"""Local-only WordPress.org v0.2 ZIP and SVN staging; never publishes.

The historical v0.1 builder stays source-bound and unmodified. Reuse its
deterministic byte/asset helpers, with an explicit clean source SHA and version.
WordPress.org release notes are in readme.txt; the Woo-only changelog/manifest
and repository README are not part of this channel's runtime allowlist.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import zipfile

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('historical_org', HERE / 'build-wordpress-org.py')
ORG = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ORG)
MANIFEST = 'wordpress/release/writeleash-distribution-files.txt'
VERSION = '0.2.0'
EXPECTED_COUNT = 48
require = ORG.require


def git(root, *args):
    # One boundary for ALL Git reads, including ORG.blob's tree/blob reads.
    # -C must select this source, not an inherited directory/index/object override.
    env = dict(os.environ)
    for name in ('GIT_DIR', 'GIT_WORK_TREE', 'GIT_COMMON_DIR', 'GIT_INDEX_FILE',
                 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_NAMESPACE'):
        env.pop(name, None)
    env['GIT_NO_REPLACE_OBJECTS'] = '1'
    return subprocess.check_output(['git', '--no-replace-objects', '-C', str(root), *args], env=env)


# This imported module is private to v0.2. Historical on-disk tooling is untouched.
# Route inherited helpers through the same replacement-independent boundary.
ORG.git = git


def read_manifest(data):
    lines = data.decode('utf-8').splitlines()
    require(all(x == x.strip() for x in lines), 'manifest whitespace')
    paths = [x for x in lines if x and not x.startswith('#')]
    require(paths == sorted(set(paths)) and len(paths) == EXPECTED_COUNT,
            'manifest count/order/duplicates')
    require(all(re.fullmatch(r'[A-Za-z0-9_.\-/]+', x) and
                not x.startswith('/') and '..' not in x and
                all(p not in ('', '.') for p in x.split('/')) for x in paths),
            'unsafe manifest')
    require('includes/free/class-price-presets.php' in paths and
            'includes/free/class-price-range.php' in paths and
            'languages/writeleash.pot' in paths, 'missing v0.2 runtime')
    return paths


def metadata(payload, version):
    require(version == VERSION, 'unreviewed release version')
    main = payload['writeleash.php'].decode('utf-8')
    readme = payload['readme.txt'].decode('utf-8')
    headers = {'Plugin Name': 'WriteLeash', 'Version': version,
               'Author': 'Duy Tran', 'Text Domain': 'writeleash',
               'Requires Plugins': 'woocommerce', 'Requires at least': '7.0',
               'Tested up to': '7.1', 'Requires PHP': '7.4',
               'WC requires at least': '10.0', 'WC tested up to': '11.1',
               'License': 'GPL v2 or later'}
    for key, value in headers.items():
        require(re.search(r'^\s*\*\s*' + re.escape(key) + r':\s*' +
                          re.escape(value) + r'\s*$', main, re.M),
                'plugin metadata mismatch: ' + key)
    require("define( 'WRITELEASH_VERSION', '" + version + "' );" in main,
            'runtime version mismatch')
    require(re.search(r'^Stable tag:\s*' + re.escape(version) + r'\s*$', readme, re.M),
            'stable-tag mismatch')
    require(re.search(r'^Contributors:\s*duyytrann\s*$', readme, re.M), 'contributor mismatch')
    require(len(payload['readme.txt']) < 10000 and '= 0.2.0 =' in readme,
            'readme size or release notes')
    require(sum(bool(re.search(rb'^\s*\*?\s*Plugin Name:', d, re.M))
                for p, d in payload.items() if p.endswith('.php')) == 1,
            'duplicate plugin header')


def audit_zip(path, expected, version):
    with zipfile.ZipFile(path) as z:
        entries = z.infolist()
        require([i.filename for i in entries] == ['writeleash/' + p for p in sorted(expected)],
                'ZIP root/path/order/duplicate/extra/missing mismatch')
        actual = {}
        for i in entries:
            require(i.date_time == ORG.STAMP and i.create_system == 3 and
                    i.external_attr == (stat.S_IFREG | 0o644) << 16 and
                    not i.extra and not i.comment and not i.flag_bits and
                    i.compress_type == zipfile.ZIP_STORED, 'ZIP metadata mismatch')
            actual[i.filename[11:]] = z.read(i)
        require(not z.comment and actual == expected, 'ZIP bytes mismatch')
    metadata(actual, version)
    ORG.scan(actual)
    return actual


def audit_staging(output, runtime, assets, version):
    require(ORG.tree(output / 'svn/trunk') == runtime, 'ZIP/trunk mismatch')
    require(ORG.tree(output / ('svn/tags/' + version)) == runtime, 'trunk/tag mismatch')
    require(ORG.tree(output / 'svn/assets') == assets, 'asset hash drift')


def build(root, requested, output, version):
    root, output = root.resolve(), output.resolve()
    require(re.fullmatch(r'[0-9a-f]{40}', requested), 'invalid source SHA')
    require(ORG.git(root, 'rev-parse', '--show-toplevel').decode().strip() == str(root),
            'source is not the repository root')
    require(ORG.git(root, 'cat-file', '-t', requested).decode().strip() == 'commit',
            'source SHA is not a commit')
    require(ORG.git(root, 'rev-parse', 'HEAD').decode().strip() == requested, 'wrong source HEAD')
    require(not ORG.git(root, 'status', '--porcelain', '--untracked-files=all', '--ignored'),
            'dirty tree or untracked/ignored input')
    require(not output.is_relative_to(root) and not output.exists(), 'unsafe/existing output')
    manifest = ORG.blob(root, MANIFEST)
    runtime = {p: ORG.blob(root, 'wordpress/writeleash/' + p) for p in read_manifest(manifest)}
    assets = {p: ORG.blob(root, 'wordpress/assets/' + p) for p in ORG.ASSETS}
    require(sorted(ORG.git(root, 'ls-tree', '--name-only', 'HEAD:wordpress/assets').decode().splitlines()) == ORG.ASSETS,
            'unexpected asset inventory')
    metadata(runtime, version)
    ORG.scan(runtime)
    output.mkdir()
    try:
        archive = output / ('writeleash-' + version + '.zip')
        with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_STORED) as z:
            for p, data in sorted(runtime.items()):
                info = zipfile.ZipInfo('writeleash/' + p, ORG.STAMP)
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                z.writestr(info, data)
        extracted = audit_zip(archive, runtime, version)
        for target in ['extracted/writeleash', 'svn/trunk', 'svn/tags/' + version]:
            ORG.write_tree(output / target, extracted)
        ORG.write_tree(output / 'svn/assets', assets)
        audit_staging(output, runtime, assets, version)
        tests = HERE.parent / 'tests/release'
        candidate = output / 'extracted/writeleash'
        for name, args in [('package-preflight.php', [root / MANIFEST, '--version=' + version]),
                           ('inventory-audit.php', [root / 'wordpress/release/PUBLIC-PAYLOAD.md', root / MANIFEST, '--candidate']),
                           ('source-audit.php', []), ('readme-validate.php', [])]:
            subprocess.run(['php', str(tests / name), str(candidate), *map(str, args)], check=True)
        subprocess.run(['php', str(HERE.parents[1] / '.github/ci/asset-audit.php')],
                       input=ORG.git(root, 'ls-files', '-z', 'wordpress/assets/*'), cwd=root, check=True)
        require(ORG.git(root, 'rev-parse', 'HEAD').decode().strip() == requested and
                not ORG.git(root, 'status', '--porcelain', '--untracked-files=all', '--ignored'),
                'source changed during build')
        evidence = {'CHANNEL': 'WordPress.org', 'SOURCE_GIT_SHA': requested,
                    'BUILDER_GIT_SHA': ORG.git(HERE, 'rev-parse', 'HEAD').decode().strip(),
                    'BUILDER_SHA256': ORG.sha(Path(__file__).read_bytes()),
                    'VERSION': version, 'STABLE_TAG': version,
                    'PUBLIC_MANIFEST_SHA256': ORG.sha(manifest),
                    'ZIP_SHA256': ORG.sha(archive.read_bytes()), 'ZIP_SIZE': archive.stat().st_size,
                    'RUNTIME_FILE_COUNT': len(runtime), 'RUNTIME_TREE_HASH': ORG.tree_hash(runtime),
                    'SVN_TRUNK_TREE_HASH': ORG.tree_hash(runtime), 'SVN_TAG_TREE_HASH': ORG.tree_hash(runtime),
                    'RUNTIME_FILES': ORG.inventory(runtime), 'ASSET_SHA256_SET': ORG.inventory(assets),
                    'TOOLS': {'python': sys.version.split()[0], 'git': ORG.git(root, '--version').decode().strip(),
                              'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;']).decode()},
                    'ZIP_ENTRY_AUDIT': 'PASS', 'ZIP_PUBLIC_CLOSURE': 'PASS',
                    'FORBIDDEN_PAYLOAD_SCAN': 'PASS', 'LOCAL_SVN_PARITY': 'PASS',
                    'REMOTE_SVN_WRITES': 'NONE'}
        (output / 'evidence.json').write_text(json.dumps(evidence, indent=2) + '\n')
        return evidence
    except Exception:
        shutil.rmtree(output)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--version', default=VERSION)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        print('ZIP_SHA256=' + build(args.source, args.sha, args.output, args.version)['ZIP_SHA256'])
    except (ValueError, subprocess.CalledProcessError, OSError, KeyError) as error:
        print('BLOCKED: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        sys.exit(1)
