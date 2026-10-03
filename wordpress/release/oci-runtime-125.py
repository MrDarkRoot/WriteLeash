#!/usr/bin/env python3
"""User-namespace OCI layer extraction/execution; no daemon or host service edits."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile


def extract(image, output):
    manifest = json.loads((image / 'manifest.json').read_text())
    output.mkdir()
    root = output.resolve()
    for layer in manifest['layers']:
        archive = image / layer['digest'].split(':')[1]
        assert hashlib.sha256(archive.read_bytes()).hexdigest() == layer['digest'].split(':')[1]
        with tarfile.open(archive) as tar:
            for member in tar:
                assert not member.name.startswith('/') and '..' not in Path(member.name).parts
                path = root / member.name
                assert path.parent.resolve().is_relative_to(root)
                if path.name.startswith('.wh.'):
                    victim = path.parent / path.name[4:]
                    if path.name == '.wh..wh..opq':
                        for child in path.parent.iterdir():
                            if child.is_dir() and not child.is_symlink(): shutil.rmtree(child)
                            else: child.unlink()
                    elif victim.is_dir() and not victim.is_symlink(): shutil.rmtree(victim)
                    elif victim.exists() or victim.is_symlink(): victim.unlink()
                    continue
                if member.isdir():
                    path.mkdir(parents=True, exist_ok=True)
                elif member.issym():
                    path.parent.mkdir(parents=True, exist_ok=True)
                    if path.exists() or path.is_symlink(): path.unlink()
                    target = (root / member.linkname.lstrip('/')) if member.linkname.startswith('/') else (path.parent / member.linkname)
                    target = Path(os.path.normpath(target))
                    assert target.is_relative_to(root)
                    path.symlink_to(os.path.relpath(target, path.parent))
                elif member.islnk():
                    target = root / member.linkname.lstrip('/')
                    assert target.resolve().is_relative_to(root)
                    if path.exists(): path.unlink()
                    path.parent.mkdir(parents=True, exist_ok=True)
                    os.link(target, path)
                elif member.isfile():
                    path.parent.mkdir(parents=True, exist_ok=True)
                    if path.is_symlink(): path.unlink()
                    assert path.resolve().is_relative_to(root)
                    if path.exists(): path.chmod(0o600)
                    with tar.extractfile(member) as src, path.open('wb') as dst: shutil.copyfileobj(src, dst)
                    path.chmod(member.mode & 0o777)
    print('Verified OCI layers extracted: ' + output.name)


def command(root, work, repo, argv):
    for mount in ['work', 'repo', 'proc', 'dev', 'evidence']:
        (root / mount).mkdir(exist_ok=True)
    (root / 'etc/resolv.conf').touch(exist_ok=True)
    return ['bwrap', '--unshare-user', '--unshare-pid', '--ro-bind', str(root), '/',
            '--dev', '/dev', '--proc', '/proc', '--bind', str(work), '/work', '--bind', str(work / 'tmp'), '/tmp',
            '--ro-bind', str(repo), '/repo', '--bind', str(work / 'evidence'), '/evidence', '--ro-bind', '/etc/resolv.conf', '/etc/resolv.conf',
            '--setenv', 'PATH', '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            '--chdir', '/work', '--', *argv]

if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest='action', required=True)
    x = sub.add_parser('extract'); x.add_argument('image', type=Path); x.add_argument('output', type=Path)
    r = sub.add_parser('run'); r.add_argument('root', type=Path); r.add_argument('work', type=Path); r.add_argument('repo', type=Path); r.add_argument('argv', nargs=argparse.REMAINDER)
    args = p.parse_args()
    if args.action == 'extract': extract(args.image, args.output)
    else: raise SystemExit(subprocess.call(command(args.root, args.work, args.repo, args.argv)))
