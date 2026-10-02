#!/usr/bin/env python3
"""Static release image/action locks and explicit research-toolchain exception."""
import argparse
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]


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
