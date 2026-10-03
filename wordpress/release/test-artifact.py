#!/usr/bin/env python3
"""Cheap, network-free adversarial tests for #123."""
import importlib.util
import json
import sys
sys.dont_write_bytecode = True
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('artifact', Path(__file__).with_name('build-wordpress-org.py'))
a = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)
ROOT = Path(__file__).resolve().parents[2]

class ArtifactCases(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.base = Path(cls.temp.name)
        cls.source = cls.base / 'source'
        subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(cls.source)], check=True)
        subprocess.run(['git', '-C', str(cls.source), 'checkout', '--quiet', '--detach', a.SOURCE], check=True)
        cls.payload = {p: a.blob(cls.source, 'wordpress/writeleash/' + p)
                       for p in a.read_manifest(a.blob(cls.source, a.MANIFEST))}
        cls.assets = {p: a.blob(cls.source, 'wordpress/assets/' + p) for p in a.ASSETS}

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def rejected(self, function, *args):
        with self.assertRaises((ValueError, subprocess.CalledProcessError, KeyError)):
            function(*args)

    def test_clean_identity(self):
        a.clean_source(self.source, a.SOURCE)
        self.rejected(a.clean_source, self.source, '0' * 40)
        p = self.source / 'untracked-release-input'
        p.write_text('input')
        self.rejected(a.clean_source, self.source, a.SOURCE)
        p.unlink()
        ignored = self.source / 'ignored-input'
        exclude = self.source / '.git/info/exclude'
        exclude.write_text(exclude.read_text() + '\nignored-input\n')
        ignored.write_text('ignored release input')
        self.rejected(a.clean_source, self.source, a.SOURCE)
        ignored.unlink()
        p = self.source / 'wordpress/writeleash/readme.txt'
        original = p.read_bytes()
        p.write_bytes(original + b'changed')
        self.rejected(a.clean_source, self.source, a.SOURCE)
        p.write_bytes(original)
        subprocess.run(['git', '-C', str(self.source), 'checkout', '--quiet', '--detach', a.SOURCE + '^'], check=True)
        self.rejected(a.clean_source, self.source, a.SOURCE)
        subprocess.run(['git', '-C', str(self.source), 'checkout', '--quiet', '--detach', a.SOURCE], check=True)

    def test_missing_manifest_file(self):
        p = self.source / 'wordpress/writeleash/readme.txt'
        original = p.read_bytes()
        p.unlink()
        self.rejected(a.blob, self.source, 'wordpress/writeleash/readme.txt')
        p.write_bytes(original)

    def test_source_symlink_and_blob_bytes(self):
        p = self.source / 'wordpress/writeleash/readme.txt'
        original = p.read_bytes()
        target = self.base / 'same-bytes'
        target.write_bytes(original)
        p.unlink()
        try:
            p.symlink_to(target)
            self.rejected(a.blob, self.source, 'wordpress/writeleash/readme.txt')
        finally:
            p.unlink()
            p.write_bytes(original)
        p.write_bytes(original + b'drift')
        try:
            self.rejected(a.blob, self.source, 'wordpress/writeleash/readme.txt')
        finally:
            p.write_bytes(original)

    def test_output_safety(self):
        self.rejected(a.build, self.source, a.SOURCE, self.source / 'output')
        existing = self.base / 'existing-output'
        existing.mkdir()
        self.rejected(a.build, self.source, a.SOURCE, existing)

    def test_manifest_traversal(self):
        for data in [b'../evil\n', b'/absolute\n', b'a\na\n']:
            self.rejected(a.read_manifest, data)

    def make_zip(self, name, changes=None, root='writeleash/', duplicate=False):
        path = self.base / name
        payload = self.payload.copy()
        if changes:
            for p, value in changes.items():
                if value is None: payload.pop(p)
                else: payload[p] = value
        with zipfile.ZipFile(path, 'w') as z:
            for p, data in sorted(payload.items()):
                info = zipfile.ZipInfo(root + p, a.STAMP)
                info.create_system = 3
                info.external_attr = (a.stat.S_IFREG | 0o644) << 16
                z.writestr(info, data)
            if duplicate:
                z.writestr(root + 'writeleash.php', payload['writeleash.php'])
        return path

    def test_archive_rejections(self):
        a.audit_zip(self.make_zip('valid.zip'), self.payload)
        cases = [('missing', {'readme.txt':None}), ('extra', {'extra.php':b'<?php'}),
                 ('asset', {'assets/icon.svg':b'<svg/>'}),
                 ('historical', {'historical-minimum-121.php':b'<?php'}),
                 ('traversal', {'../outside':b'no'}), ('absolute', {'/absolute':b'no'})]
        for name, changes in cases:
            with self.subTest(name=name):
                self.rejected(a.audit_zip, self.make_zip(name + '.zip', changes), self.payload)
        self.rejected(a.audit_zip, self.make_zip('root.zip', root='wordpress/writeleash/'), self.payload)
        self.rejected(a.audit_zip, self.make_zip('duplicate.zip', duplicate=True), self.payload)

    def test_archive_metadata(self):
        for kind in ['timestamp', 'symlink', 'permissions', 'uid_extra']:
            path = self.make_zip(kind + '.zip')
            with zipfile.ZipFile(path) as archive:
                records = [(i, archive.read(i)) for i in archive.infolist()]
            info = records[0][0]
            if kind == 'timestamp': info.date_time = (2020, 1, 1, 0, 0, 0)
            if kind == 'symlink': info.external_attr = (a.stat.S_IFLNK | 0o777) << 16
            if kind == 'permissions': info.external_attr = (a.stat.S_IFREG | 0o755) << 16
            if kind == 'uid_extra': info.extra = b'\x75\x78\x02\x00\x00\x00'
            with zipfile.ZipFile(path, 'w') as archive:
                for entry, data in records: archive.writestr(entry, data)
            self.rejected(a.audit_zip, path, self.payload)

    def test_metadata_and_secret_scan(self):
        for path, before, after in [('writeleash.php', b'Version: 0.1.0', b'Version: 0.2.0'),
                                   ('readme.txt', b'Stable tag: 0.1.0', b'Stable tag: 0.2.0')]:
            payload = self.payload.copy()
            payload[path] = payload[path].replace(before, after)
            self.rejected(a.metadata, payload)
        a.scan(self.payload)
        self.rejected(a.scan, {'example.php': b'-----BEGIN PRIVATE KEY-----'})

    def test_staging_mismatches(self):
        stage = self.base / 'staging'
        a.write_tree(stage / 'svn/trunk', self.payload)
        a.write_tree(stage / 'svn/tags/0.1.0', self.payload)
        a.write_tree(stage / 'svn/assets', self.assets)
        a.audit_staging(stage, self.payload, self.assets)
        for relative in ['svn/trunk/readme.txt', 'svn/tags/0.1.0/readme.txt', 'svn/assets/icon.svg']:
            p = stage / relative
            original = p.read_bytes()
            p.write_bytes(original + b'drift')
            self.rejected(a.audit_staging, stage, self.payload, self.assets)
            p.write_bytes(original)

    def test_independent_builds(self):
        first = a.build(self.source, a.SOURCE, self.base / 'build1')
        source2 = self.base / 'source2'
        subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(source2)], check=True)
        subprocess.run(['git', '-C', str(source2), 'checkout', '--quiet', '--detach', a.SOURCE], check=True)
        second = a.build(source2, a.SOURCE, self.base / 'build2')
        self.assertNotEqual(first['ZIP_SHA256'], '2ecfd3edf667c15b36fc75fbb525551df07b075bdfc5696a07813a704eb6cf57')
        subprocess.run([sys.executable, str(Path(__file__).with_name('nonce-artifact-audit.py')), str(self.base / 'build1/extracted/writeleash')], check=True)
        self.assertEqual(first['ZIP_SHA256'], second['ZIP_SHA256'])
        self.assertEqual(first['ZIP_SIZE'], second['ZIP_SIZE'])
        reviewed = json.loads(Path(__file__).with_name('artifact-124-evidence.json').read_text())
        for field in ['SOURCE_GIT_SHA', 'VERSION', 'STABLE_TAG', 'PUBLIC_MANIFEST_SHA256',
                      'ZIP_SHA256', 'ZIP_SIZE', 'RUNTIME_FILE_COUNT', 'SVN_TRUNK_TREE_HASH',
                      'SVN_TAG_TREE_HASH', 'ASSET_SHA256_SET', 'RUNTIME_FILES']:
            self.assertEqual(first[field], reviewed[field], field)
        self.assertEqual((self.base / 'build1/writeleash-0.1.0.zip').read_bytes(),
                         (self.base / 'build2/writeleash-0.1.0.zip').read_bytes())

if __name__ == '__main__':
    unittest.main()
