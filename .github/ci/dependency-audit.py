#!/usr/bin/env python3
"""Static image/download/npm locks and explicit fixture-toolchain exceptions."""
import argparse
import base64
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def audit_admin_browser_tools():
    tools = ROOT / 'wordpress/tests/admin/browser-tools'
    manifest = json.loads((tools / 'package.json').read_text())
    lock = json.loads((tools / 'package-lock.json').read_text())
    assert manifest.get('private') is True, 'Admin browser tools must remain test-only/private'
    dependencies = manifest['dependencies']
    assert set(dependencies) == {'axe-core', 'playwright'}, 'Re-audit changed Admin browser tools'
    assert lock['lockfileVersion'] == 3, 'Admin browser tools require npm lockfile v3'
    packages = lock['packages']
    assert packages['']['dependencies'] == dependencies, 'Admin browser manifest/lock mismatch'
    for name, version in dependencies.items():
        assert re.fullmatch(r'\d+\.\d+\.\d+', version), 'Admin browser dependency must use an exact version: ' + name
        assert packages['node_modules/' + name]['version'] == version, 'Admin browser direct dependency mismatch: ' + name
    for path, package in packages.items():
        if not path:
            continue
        assert path.startswith('node_modules/'), 'Unexpected Admin browser lock entry: ' + path
        name = path.removeprefix('node_modules/')
        version = package['version']
        assert re.fullmatch(r'\d+\.\d+\.\d+', version), 'Admin browser lock must use an exact package version: ' + path
        assert package.get('resolved') == f'https://registry.npmjs.org/{name}/-/{name.rsplit("/", 1)[-1]}-{version}.tgz', 'Admin browser package must resolve its locked version from public npm: ' + path
        integrity = package.get('integrity', '')
        assert integrity.startswith('sha512-') and len(base64.b64decode(integrity[7:], validate=True)) == 64, 'Missing/invalid Admin browser SHA512 integrity: ' + path
        for name, version in {**package.get('dependencies', {}), **package.get('optionalDependencies', {})}.items():
            assert packages['node_modules/' + name]['version'] == version, 'Admin browser transitive dependency mismatch: ' + name
    workflow = (ROOT / '.github/workflows/wordpress-woo-admin.yml').read_text()
    assert 'cp wordpress/tests/admin/browser-tools/package.json wordpress/tests/admin/browser-tools/package-lock.json "$browser_tools/"' in workflow, 'Admin workflow must consume committed npm manifests'
    assert 'npm ci --ignore-scripts --no-audit --no-fund --prefix "$browser_tools"' in workflow, 'Admin workflow must use integrity-locked npm ci without lifecycle scripts'
    assert not re.search(r'\bnpm\s+(?:install|update)\b', workflow), 'Admin workflow must not resolve an unlocked npm graph'
    print(f'#133 Admin browser npm lock: {len(packages) - 1} package versions/public-registry SHA512 integrities; npm ci --ignore-scripts PASS')
    print('#133 ADMIN BROWSER/OS EXCEPTION: Playwright-selected Firefox download has no repository checksum lock; --with-deps OS packages and hosted Chrome/Node remain runner-managed, not an immutable toolchain')


def audit(release=False, acknowledge=False):
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    images = 0
    downloads = []
    unpinned = []
    for name in paths:
        if not name or not (name.endswith(('.yml', '.sh')) or Path(name).name == 'Dockerfile'):
            continue
        text = (ROOT / name).read_text()
        image_source = text if name.endswith('.yml') or Path(name).name == 'Dockerfile' else ''
        for image in re.findall(r'(?:^\s*image:\s*|^FROM\s+|^\s*(?:CORE_IMAGE|CLI_IMAGE|core|cli):\s*)([^\s]+)', image_source, re.M):
            # Build arguments are fixed by the pinned defaults/matrix/Compose.
            if image.startswith('${'):
                continue
            images += 1
            if not re.search(r'@sha256:[a-f0-9]{64}$', image):
                unpinned.append(name + ': image ' + image)
        if re.search(r'curl\s.*downloads\.wordpress\.org', text):
            downloads.append(name)
            assert 'sha256sum -c' in text, 'Unverified ZIP download: ' + name
            assert not re.search(r'downloads\.wordpress\.org/[^\s]*latest', text), 'Floating ZIP: ' + name
    assert not unpinned, 'Unpinned images: ' + repr(unpinned)
    audit_admin_browser_tools()
    # This fixture compiles historical PG research, never the WordPress package.
    # Do not silently describe apk's transitive package repository as immutable.
    native = (ROOT / 'experiments/native_tx_state/Dockerfile').read_text()
    assert 'RUN apk add --no-cache build-base' in native, 'Re-audit changed research toolchain'
    print(f'#133 dependency audit: {images} literal image references digest-pinned; {len(downloads)} plugin ZIP fetch harnesses verify SHA256 PASS')
    print('#133 UNPINNED RESEARCH TOOLCHAIN: experiments/native_tx_state/Dockerfile: Alpine build-base and transitive APK repository packages; never distributed in WordPress runtime')
    if release and not acknowledge:
        raise SystemExit('RELEASE_FULL BLOCKED: lock the APK toolchain or explicitly acknowledge this reviewed research-only exception at dispatch')
    if release:
        print('#133 explicit research-only toolchain exception acknowledged for this exact-SHA evidence attempt')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--release', action='store_true')
    parser.add_argument('--acknowledge-research-toolchain', action='store_true')
    args = parser.parse_args()
    audit(args.release, args.acknowledge_research_toolchain)
