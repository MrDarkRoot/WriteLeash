#!/usr/bin/env python3
"""B1-B3 discriminating controls; fixtures execute against explicit fault doubles."""
import argparse
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--subject', type=Path, default=ROOT,
                    help='Builder/fixture checkout to challenge (including the vulnerable revision)')
args, unittest_args = parser.parse_known_args()
SUBJECT = args.subject.resolve()
ENV = dict(os.environ, PYTHONDONTWRITEBYTECODE='1')
# The attack explicitly enables ordinary replacement lookup in the caller.
ENV.pop('GIT_NO_REPLACE_OBJECTS', None)


class ReleaseRegressionCases(unittest.TestCase):
    def test_b1_replace_tree_cannot_change_reviewed_bytes(self):
        with tempfile.TemporaryDirectory(prefix='wl253-replace-') as temp:
            base = Path(temp)
            source = base / 'source'
            subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(source)], check=True)
            revision = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).decode().strip()
            subprocess.run(['git', '-C', str(source), 'checkout', '--quiet', '--detach', revision], check=True)
            path = 'wordpress/writeleash/writeleash.php'
            genuine = subprocess.check_output(['git', '--no-replace-objects', '-C', str(source), 'show', revision + ':' + path])
            builder = SUBJECT / 'wordpress/release/build-wordpress-org-v02.py'

            def build(output):
                return subprocess.run(['python3', str(builder), '--source', str(source), '--sha', revision,
                                       '--output', str(output)], env=ENV, capture_output=True, text=True)

            intact = base / 'intact'
            control = build(intact)
            self.assertEqual(control.returncode, 0, control.stdout + control.stderr)
            old = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD^{tree}']).decode().strip()
            changed = genuine.replace(b'<?php\n', b'<?php\n// Independent review source-binding replacement probe.\n', 1)
            blob = subprocess.check_output(['git', '-C', str(source), 'hash-object', '-w', '--stdin'], input=changed).decode().strip()
            subprocess.run(['git', '-C', str(source), 'update-index', '--cacheinfo', '100644,' + blob + ',' + path], check=True)
            new = subprocess.check_output(['git', '-C', str(source), 'write-tree']).decode().strip()
            subprocess.run(['git', '-C', str(source), 'replace', old, new], check=True)
            subprocess.run(['git', '-C', str(source), 'reset', '--quiet', '--hard', 'HEAD'], check=True)
            self.assertEqual(subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=all', '--ignored']), b'')
            self.assertEqual(subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD']).decode().strip(), revision)
            self.assertEqual(subprocess.check_output(['git', '-C', str(source), 'show', 'HEAD:' + path]), changed)
            attacked = base / 'attacked'
            result = build(attacked)
            if result.returncode:
                self.assertIn('BLOCKED: dirty tree or untracked/ignored input', result.stderr)
                self.assertFalse(attacked.exists(), 'Rejected attack must not leave an artifact')
            else:
                with zipfile.ZipFile(attacked / 'writeleash-0.2.0.zip') as archive:
                    self.assertEqual(archive.read('writeleash/writeleash.php'), genuine,
                                     'B1: replacement bytes accepted under the reviewed source SHA')
                self.assertEqual((attacked / 'writeleash-0.2.0.zip').read_bytes(),
                                 (intact / 'writeleash-0.2.0.zip').read_bytes())

    def fixture(self, kind, fault='none'):
        return subprocess.run(['php', str(ROOT / 'wordpress/tests/release/fixture-controls-v02.php'),
                               str(SUBJECT), kind, fault], capture_output=True, text=True)

    def test_b2_parent_positive_control(self):
        result = self.fixture('parent')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('#240', result.stdout)

    def test_b2_variation_blind_observer_is_rejected(self):
        result = self.fixture('parent', 'blind-variations')
        self.assertNotEqual(result.returncode, 0, 'B2: parent-only observer and refused variation saves accepted\n' + result.stdout)
        self.assertIn('variation Apply save observer', result.stderr)

    def test_b3_scale_positive_control(self):
        result = self.fixture('scale')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('#240 scale:', result.stdout)

    def test_b3_false_undone_outcomes_are_rejected(self):
        result = self.fixture('scale', 'false-undone')
        self.assertNotEqual(result.returncode, 0, 'B3: two UNDONE outcomes without restoration accepted\n' + result.stdout)
        self.assertIn('restored price', result.stderr)


if __name__ == '__main__':
    unittest.main(argv=[__file__, *unittest_args], verbosity=2)
