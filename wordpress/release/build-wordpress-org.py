#!/usr/bin/env python3
"""Local-only exact-SHA artifact builder (#123/#144); never publishes."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import struct
import subprocess
import sys
import zipfile
import importlib.util
sys.dont_write_bytecode = True
_target_spec = importlib.util.spec_from_file_location("plugin_targets", Path(__file__).with_name("plugin-targets.py"))
targets = importlib.util.module_from_spec(_target_spec)
_target_spec.loader.exec_module(targets)

SOURCE = '5ccc75c1d895a2fb379866ce4cf0e9901d1c276c'
MANIFEST = 'wordpress/release/writeleash-distribution-files.txt'
ASSETS = sorted(['icon-128x128.png', 'icon-256x256.png', 'icon.svg',
                 'banner-772x250.png', 'banner-1544x500.png'] +
                [f'screenshot-{i}.png' for i in range(1, 7)])
STAMP = (1980, 1, 1, 0, 0, 0)

def require(ok, message):
    if not ok:
        raise ValueError(message)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args])

def clean_source(root, requested, target=None):
    require(re.fullmatch(r"[0-9a-f]{40}", requested), "source must be an exact Git SHA")
    require(target is not None or requested == SOURCE, 'requested SHA is not the reviewed source')
    require(git(root, 'rev-parse', 'HEAD').decode().strip() == requested, 'wrong HEAD')
    require(not git(root, 'status', '--porcelain', '--untracked-files=all', '--ignored'),
            'dirty tree or untracked/ignored input')

def read_manifest(data, count=37):
    lines = data.decode().splitlines()
    require(all(x == x.strip() for x in lines), 'manifest whitespace')
    paths = [x for x in lines if x and not x.startswith('#')]
    require(paths == sorted(set(paths)) and (count is None or len(paths) == count) and bool(paths), 'manifest count/order/duplicates')
    require(all(re.fullmatch(r'[A-Za-z0-9_.\-/]+', x) and
                not x.startswith('/') and '..' not in x and
                all(p not in ('', '.') for p in x.split('/')) for x in paths), 'unsafe manifest')
    return paths

def blob(root, path):
    mode = git(root, 'ls-tree', 'HEAD', '--', path).decode().split()[0]
    require(mode == '100644', 'nonregular source file')
    data = git(root, 'show', f'HEAD:{path}')
    local = root / path
    require(local.is_file() and not local.is_symlink() and local.read_bytes() == data,
            'source differs from committed blob')
    return data

def inventory(payload):
    return [{'path': p, 'size': len(data), 'sha256': sha(data)} for p, data in sorted(payload.items())]

def tree_hash(payload):
    # Canonical UTF-8 JSON lines: path, decimal size, SHA256; final LF included.
    return sha(b''.join((json.dumps(row, sort_keys=True, separators=(',', ':')) + '\n').encode()
                       for row in inventory(payload)))

def tree(root):
    require(root.is_dir(), 'missing tree')
    result = {}
    for p in root.rglob('*'):
        require(not p.is_symlink(), 'tree symlink')
        if p.is_file():
            result[p.relative_to(root).as_posix()] = p.read_bytes()
    return result

def metadata(payload, spec=None):
    if spec is not None:
        targets.payload_check(payload, spec, sorted(payload))
        return
    main = payload['writeleash.php'].decode()
    readme = payload['readme.txt'].decode()
    for key, value in {'Plugin Name':'WriteLeash', 'Version':'0.1.0',
                       'Text Domain':'writeleash', 'Requires Plugins':'woocommerce',
                       'Requires at least':'7.0',
                       'Requires PHP':'7.4', 'License':'GPL v2 or later'}.items():
        require(re.search(r'^\s*\*\s*' + re.escape(key) + r':\s*' + re.escape(value) + r'\s*$', main, re.M),
                'plugin metadata mismatch: ' + key)
    require(re.search(r'^Tested up to:\s*7\.1\s*$', readme, re.M), 'tested-up-to mismatch')
    require(re.search(r'^Stable tag:\s*0\.1\.0\s*$', readme, re.M), 'stable-tag mismatch')
    require('11.1.2' in readme, 'missing Woo support claim')
    require(sum(bool(re.search(rb'^\s*\*?\s*Plugin Name:', d, re.M))
                for p, d in payload.items() if p.endswith('.php')) == 1, 'duplicate plugin header')

def scan(payload):
    forbidden = re.compile(r'(?:^|/)(?:\.env(?:\..*)?|tests|\.github|assets|Dockerfile|docker-compose[^/]*|fixture[^/]*|proof\.json|historical[^/]*|operator-setup[^/]*|[^/]*\.(?:zip|tar|gz|sql|log))$', re.I)
    credential = re.compile(rb'-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}|(?:password|secret|token)\s*[=:]\s*[\x22\x27][^\x22\x27]{8,}[\x22\x27]', re.I)
    for p, data in payload.items():
        require(not forbidden.search(p), 'forbidden package path')
        require(not credential.search(data), 'credential-like material (value suppressed)')
        require(not re.search(rb'/(?:home|Users)/[^\s\x22\x27]+', data), 'local absolute path')

def audit_zip(path, expected, spec=None):
    slug = Path(spec["root"]).name if spec else "writeleash"
    with zipfile.ZipFile(path) as z:
        entries = z.infolist()
        require([i.filename for i in entries] == [slug + '/' + p for p in sorted(expected)],
                'ZIP root/path/order/duplicate/extra/missing mismatch')
        actual = {}
        for i in entries:
            require(i.date_time == STAMP and i.create_system == 3 and
                    i.external_attr == (stat.S_IFREG | 0o644) << 16 and
                    not i.extra and not i.comment and not i.flag_bits and
                    i.compress_type == zipfile.ZIP_STORED, 'ZIP metadata mismatch')
            actual[i.filename[len(slug) + 1:]] = z.read(i)
        require(not z.comment and actual == expected, 'ZIP bytes mismatch')
    metadata(actual, spec)
    scan(actual)
    return actual

def audit_staging(output, runtime, assets, version="0.1.0"):
    require(tree(output / 'svn/trunk') == runtime, 'ZIP/trunk mismatch')
    require(tree(output / ('svn/tags/' + version)) == runtime, 'trunk/tag mismatch')
    require(tree(output / 'svn/assets') == assets, 'asset hash drift')

def write_tree(root, payload):
    root.mkdir(parents=True)
    for p, data in sorted(payload.items()):
        destination = root / p
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(data)
        destination.chmod(0o644)
        os.utime(destination, (315532800, 315532800))
    for p in [root] + list(root.rglob('*')):
        if p.is_dir():
            p.chmod(0o755)
            os.utime(p, (315532800, 315532800))

def build(root, requested, output, target=None):
    root = root.resolve()
    output = output.resolve()
    clean_source(root, requested, target)
    require(not output.is_relative_to(root), 'output must be outside source checkout')
    require(not output.exists(), 'output already exists')
    spec = targets.target(target, root) if target is not None else None
    manifest_path = spec['manifest'] if spec else MANIFEST
    source_path = spec['root'] if spec else 'wordpress/writeleash'
    slug = Path(source_path).name
    version = spec['version'] if spec else '0.1.0'
    manifest = blob(root, manifest_path)
    paths = read_manifest(manifest, 37 if spec is None else None)
    if spec is not None:
        # Refuse unmapped production code anywhere, not silently drop it.
        targets.source_check(root)
    runtime = {p: blob(root, source_path + '/' + p) for p in paths}
    asset_root = spec['assets'] if spec else 'wordpress/assets'
    asset_paths = (targets.manifest(blob(root, spec['asset_manifest']), empty=True)
                   if spec and target != 'writeleash' else ASSETS)
    assets = {p: blob(root, asset_root + '/' + p) for p in asset_paths}
    asset_source = git(root, 'ls-tree', '-r', '--name-only', 'HEAD', '--', asset_root).decode().splitlines()
    listing = [p[len(asset_root) + 1:] for p in asset_source]
    if spec and target == 'writeleash':
        # Satellite listing directories are separate owners, never flagship assets.
        listing = [p for p in listing if not p.startswith(('price-history/', 'price-campaigns/'))]
    elif spec:
        listing = [p for p in listing if not p.endswith('.md')]
    require(sorted(listing) == asset_paths, 'unexpected target asset set')
    metadata(runtime, spec)
    scan(runtime)
    # Accepted #120 gates run on the extracted/generated closure below.
    output.mkdir()
    try:
        archive = output / (slug + '-' + version + '.zip')
        with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_STORED) as z:
            for p, data in sorted(runtime.items()):
                info = zipfile.ZipInfo(slug + '/' + p, STAMP)
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                z.writestr(info, data)
        extracted = audit_zip(archive, runtime, spec)
        write_tree(output / ('extracted/' + slug), extracted)
        write_tree(output / 'svn/trunk', extracted)
        write_tree(output / ('svn/tags/' + version), extracted)
        write_tree(output / 'svn/assets', assets)
        audit_staging(output, extracted, assets, version)
        audits = Path(__file__).resolve().parents[1] / 'tests/release'
        candidate = output / ('extracted/' + slug)
        flagship_audits = [('package-preflight.php', [root / MANIFEST]),
                           ('inventory-audit.php', [root / 'wordpress/release/PUBLIC-PAYLOAD.md', root / MANIFEST, '--candidate']),
                           ('source-audit.php', []), ('readme-validate.php', [])]
        for name, args in (flagship_audits if target in (None, 'writeleash') else []):
            subprocess.run(['php', str(audits / name), str(candidate), *map(str, args)], check=True)
        clean_source(root, requested, target)
        asset_rows = inventory(assets)
        for row in asset_rows:
            data = assets[row['path']]
            if data.startswith(b'\x89PNG\r\n\x1a\n'):
                row['dimensions'] = list(struct.unpack('>II', data[16:24]))
            else:
                row['viewBox'] = re.search(r'viewBox="([^"]+)"', data.decode()).group(1)
        evidence = {'SOURCE_GIT_SHA': requested, 'VERSION':version, 'STABLE_TAG':version,
                    'PUBLIC_MANIFEST_SHA256':sha(manifest), 'ZIP_SHA256':sha(archive.read_bytes()),
                    'ZIP_SIZE':archive.stat().st_size, 'RUNTIME_FILE_COUNT':len(runtime),
                    'SVN_TRUNK_TREE_HASH':tree_hash(runtime), 'SVN_TAG_TREE_HASH':tree_hash(runtime),
                    'ASSET_SHA256_SET':asset_rows, 'RUNTIME_FILES':inventory(runtime),
                    'BUILD_COMMAND':f'python3 wordpress/release/build-wordpress-org.py --source source --sha {SOURCE} --output candidate',
                    'TOOLS':{'python':sys.version.split()[0], 'archive':'Python stdlib zipfile; ZIP_STORED; no compression dependency',
                             'git':git(root, '--version').decode().strip(),
                             'php':subprocess.check_output(['php', '-r', 'echo PHP_VERSION;']).decode()},
                    'ZIP_ENTRY_AUDIT':'PASS', 'ZIP_PUBLIC_CLOSURE':'PASS',
                    'FORBIDDEN_PAYLOAD_SCAN':'PASS', 'PAYLOAD_EQUIVALENCE':'PASS'}
        if spec is not None:
            evidence.update({'TARGET': target, 'PLUGIN_ROOT': slug, 'MAIN_PLUGIN_FILE': spec['main'],
                             'DISTRIBUTION_MANIFEST': manifest_path, 'ASSET_ROOT': asset_root,
                             'RELEASE_ROOT': spec['release'],
                             'BUILD_COMMAND': f'python3 wordpress/release/build-wordpress-org.py --target {target} --source source --sha {requested} --output candidate'})
        (output / 'evidence.json').write_text(json.dumps(evidence, indent=2) + '\n')
        return evidence
    except Exception:
        shutil.rmtree(output)
        raise

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--target', choices=['writeleash', 'price-history', 'price-campaigns'],
                        help='Explicit target uses its exact-SHA manifest; omitted preserves frozen #123 build')
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        result = build(args.source, args.sha, args.output, args.target)
        print('ZIP_SHA256=' + result['ZIP_SHA256'])
    except (ValueError, subprocess.CalledProcessError, OSError, KeyError) as error:
        # Never print subprocess/blob data or credential values.
        print('BLOCKED: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        sys.exit(1)
