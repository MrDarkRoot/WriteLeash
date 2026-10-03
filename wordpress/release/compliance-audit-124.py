#!/usr/bin/env python3
"""Verify #124 exact artifact and fail closed on unreviewed Plugin Check output."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys
sys.dont_write_bytecode = True

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('artifact123', HERE / 'build-wordpress-org.py')
a = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)

def audit(archive, installed=None, plugin_check=None):
    reviewed = json.loads((HERE / 'artifact-123-evidence.json').read_text())
    data = archive.read_bytes()
    a.require(hashlib.sha256(data).hexdigest() == reviewed['ZIP_SHA256'] and len(data) == reviewed['ZIP_SIZE'], 'CHECKSUM DRIFT')
    import zipfile
    with zipfile.ZipFile(archive) as z:
        payload = {p[len('writeleash/'):]: z.read(p) for p in z.namelist()}
    a.require(a.inventory(payload) == reviewed['RUNTIME_FILES'], 'runtime identity drift')
    a.audit_zip(archive, payload)
    root = installed
    if root is not None:
        a.require(a.tree(root) == payload, 'installed tree mismatch')
    else:
        import tempfile
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'writeleash'
            a.write_tree(root, payload)
            source_checks(root)
    if installed is not None:
        source_checks(installed)
    if plugin_check is not None:
        # The reviewed exact artifact emits zero findings. New/unknown output
        # is retained and blocks; this is not an ignore-list classifier.
        a.require(plugin_check.read_text().strip() == 'Success: Checks complete. No errors found.', 'Plugin Check findings require review')
    print('Exact ZIP identity, public closure, metadata, package scan and installed bytes PASS')

def source_checks(root):
    tests = HERE.parent / 'tests/release'
    for name in ['source-audit.php', 'readme-validate.php']:
        subprocess.run(['php', str(tests / name), str(root)], check=True)

if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--zip', type=Path, required=True)
    p.add_argument('--installed', type=Path)
    p.add_argument('--plugin-check', type=Path)
    args = p.parse_args()
    try:
        audit(args.zip, args.installed, args.plugin_check)
    except (ValueError, OSError, subprocess.CalledProcessError, KeyError) as error:
        print('BLOCKED: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        sys.exit(1)
