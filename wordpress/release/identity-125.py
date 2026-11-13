#!/usr/bin/env python3
"""Exact checksum and installed path/bytes gate, before every configuration."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile
SHA = '7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e'
SIZE = 396314

def audit(archive, installed=None):
    data = archive.read_bytes()
    if hashlib.sha256(data).hexdigest() != SHA or len(data) != SIZE:
        raise ValueError('STOP — CHECKSUM DRIFT')
    with zipfile.ZipFile(archive) as z:
        records = z.infolist()
        assert len(records) == 37 and len({i.filename for i in records}) == 37
        payload = {i.filename.removeprefix('writeleash/'): z.read(i) for i in records}
        assert all(i.filename.startswith('writeleash/') for i in records)
    if installed is not None:
        paths = list(installed.rglob('*'))
        assert all(not p.is_symlink() for p in paths)
        actual = {p.relative_to(installed).as_posix():p.read_bytes() for p in paths if p.is_file()}
        if actual != payload: raise ValueError('STOP — INSTALLED TREE MISMATCH')
    rows = [{'path':p,'sha256':hashlib.sha256(d).hexdigest(),'size':len(d)} for p,d in sorted(payload.items())]
    tree = hashlib.sha256(b''.join((json.dumps(row,sort_keys=True,separators=(',',':'))+'\n').encode() for row in rows)).hexdigest()
    return {'ZIP_SHA256':SHA,'ZIP_SIZE':SIZE,'INSTALLED_TREE_EQUALS_ZIP':'PASS' if installed else 'NOT_TESTED','TREE_HASH':tree,'RUNTIME_FILE_COUNT':len(rows)}

if __name__ == '__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('archive',type=Path);p.add_argument('--installed',type=Path)
    args=p.parse_args();print(json.dumps(audit(args.archive,args.installed)))
