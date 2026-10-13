#!/usr/bin/env python3
"""Artifact rejection controls against an already-built v0.2 candidate."""
import argparse
import importlib.util
import json
from pathlib import Path
import tempfile
import zipfile

spec = importlib.util.spec_from_file_location('v02', Path(__file__).with_name('build-wordpress-org-v02.py'))
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('candidate', type=Path)
args = parser.parse_args()
e = json.loads((args.candidate / 'evidence.json').read_text())
runtime = b.ORG.tree(args.candidate / 'extracted/writeleash')
assets = b.ORG.tree(args.candidate / 'svn/assets')
archive = args.candidate / 'writeleash-0.2.1.zip'
b.audit_zip(archive, runtime, '0.2.1')
b.audit_staging(args.candidate, runtime, assets, '0.2.1')
assert e['ZIP_SHA256'] == b.ORG.sha(archive.read_bytes())
assert e['RUNTIME_TREE_HASH'] == b.ORG.tree_hash(runtime)
checks = 0


def rejected(label, callback):
    global checks
    try:
        callback()
    except ValueError:
        checks += 1
        print('PASS rejection:', label)
    else:
        raise AssertionError('accepted corrupt input: ' + label)


rejected('version', lambda: b.metadata(runtime, '0.1.0'))
bad = dict(runtime)
bad['readme.txt'] = bad['readme.txt'].replace(b'Stable tag: 0.2.1', b'Stable tag: 9.9.9')
rejected('stable tag', lambda: b.metadata(bad, '0.2.1'))
bad = dict(runtime)
bad['writeleash.php'] = bad['writeleash.php'].replace(b"'WRITELEASH_VERSION', '0.2.1'", b"'WRITELEASH_VERSION', '9.9.9'")
rejected('runtime version', lambda: b.metadata(bad, '0.2.1'))
manifest = ('\n'.join(sorted(runtime)) + '\n').encode()
for label, value in [('missing member', manifest.replace(b'includes/free/class-price-range.php\n', b'')),
                     ('duplicate', manifest + b'LICENSE\n'), ('unsafe path', manifest.replace(b'LICENSE', b'../LICENSE'))]:
    rejected(label, lambda value=value: b.read_manifest(value))
rejected('credential marker', lambda: b.ORG.scan({'readme.txt': b'-----BEGIN PRIVATE KEY-----'}))
with tempfile.TemporaryDirectory(prefix='wl240-rejection-') as temp:
    path = Path(temp) / 'bad.zip'
    for label, mutate in [('wrong root', lambda p: 'other/' + p),
                          ('missing member', lambda p: None if p == 'LICENSE' else 'writeleash/' + p),
                          ('extra member', lambda p: 'writeleash/' + p)]:
        with zipfile.ZipFile(archive) as original, zipfile.ZipFile(path, 'w') as z:
            for info in original.infolist():
                name = mutate(info.filename[11:])
                if name is not None:
                    data = original.read(info)
                    info.filename = name
                    z.writestr(info, data)
            if label == 'extra member':
                z.writestr('writeleash/tests.txt', b'not public')
        rejected('archive ' + label, lambda: b.audit_zip(path, runtime, '0.2.1'))
    stage = Path(temp) / 'stage'
    b.ORG.write_tree(stage / 'svn/trunk', runtime)
    b.ORG.write_tree(stage / 'svn/tags/0.2.1', runtime)
    b.ORG.write_tree(stage / 'svn/assets', assets)
    (stage / 'svn/tags/0.2.1/readme.txt').write_bytes(b'changed')
    rejected('tag drift', lambda: b.audit_staging(stage, runtime, assets, '0.2.1'))
print('PASS: intact ZIP/staging identity and', checks, 'independent corruption controls')
