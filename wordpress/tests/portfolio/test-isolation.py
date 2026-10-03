#!/usr/bin/env python3
"""Network-free adversarial target, package, identity and #133 owner cases."""
import argparse
import importlib.util
import json
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
import zipfile
sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[3]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


a = module('artifact', 'wordpress/release/build-wordpress-org.py')
t = a.targets
o = module('owners', '.github/ci/ownership.py')
parser = argparse.ArgumentParser()
parser.add_argument('--target', choices=['writeleash', 'price-history', 'price-campaigns'])
args, remaining = parser.parse_known_args()
SELECTED = [args.target] if args.target else list(t.targets())


class IsolationCases(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.base = Path(cls.temp.name)
        cls.source = cls.base / 'source'
        cls.source.mkdir()
        # Test the exact current source, including staged/uncommitted implementation.
        # Never recursively copy broad directories into a runtime package.
        files = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT)
        for name in set(filter(None, files.decode().split('\0'))):
            source = ROOT / name
            if source.is_file():
                destination = cls.source / name
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, destination)
        subprocess.run(['git', 'init', '-q', str(cls.source)], check=True)
        cls.commit('test source snapshot')
        cls.sha = a.git(cls.source, 'rev-parse', 'HEAD').decode().strip()
        cls.registry = t.source_check(cls.source)

    @classmethod
    def commit(cls, message):
        a.git(cls.source, 'add', '-A')
        a.git(cls.source, '-c', 'user.name=Isolation Fixture', '-c', 'user.email=fixture@example.test',
              '-c', 'commit.gpgsign=false', 'commit', '-qm', message)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def payload(self, target):
        spec = self.registry[target]
        paths = t.manifest((self.source / spec['manifest']).read_bytes())
        return spec, paths, {p: (self.source / spec['root'] / p).read_bytes() for p in paths}

    def refused(self, function, *args):
        with self.assertRaises((ValueError, KeyError, AssertionError)):
            function(*args)

    def test_01_finite_target_metadata(self):
        for target in SELECTED:
            spec, paths, payload = self.payload(target)
            t.payload_check(payload, spec, paths)
            self.assertEqual(len(paths), 37 if target == 'writeleash' else 5)
            for path in [spec['main'], 'readme.txt']:
                corrupted = payload.copy()
                corrupted[path] = corrupted[path].replace(spec['version'].encode(), b'99.0.0')
                self.refused(t.payload_check, corrupted, spec, paths)
            corrupted = payload.copy()
            corrupted[spec['main']] = corrupted[spec['main']].replace(b'Text Domain: ' + spec['text_domain'].encode(), b'Text Domain: another-plugin')
            self.refused(t.payload_check, corrupted, spec, paths)

    def test_02_wrong_plugin_injection(self):
        for target in SELECTED:
            spec, paths, payload = self.payload(target)
            for other in self.registry:
                if other == target:
                    continue
                foreign, _, foreign_payload = self.payload(other)
                for entry in [foreign['main'], 'includes/class-plugin.php']:
                    contaminated = {**payload, 'foreign.php': foreign_payload[entry]}
                    self.refused(t.payload_check, contaminated, spec, paths)
                    if target != 'writeleash':
                        replaced = {**payload, 'includes/class-plugin.php': foreign_payload[entry]}
                        self.refused(t.payload_check, replaced, spec, paths)
            if target == 'writeleash':
                for foreign_option in ['writeleash_price_history_version', 'writeleash_price_campaigns_version', 'writeleash_%']:
                    injected = payload[spec['main']] + ("\nupdate_option('" + foreign_option + "', 'foreign');").encode()
                    self.refused(t.payload_check, {**payload, spec['main']: injected}, spec, paths)
            if target != 'writeleash':
                historical = (self.source / 'wordpress/writeleash/includes/class-guard.php').read_bytes()
                self.refused(t.payload_check, {**payload, 'includes/class-plugin.php': historical}, spec, paths)

    def test_03_target_ambiguity(self):
        for unknown in ['', 'all', 'writeleash-price-history', '../price-history', None]:
            self.refused(t.target, unknown)
        registry = self.source / t.REGISTRY
        original = registry.read_bytes()
        try:
            registry.write_bytes(original.replace(b'"price-history": {', b'"writeleash": {}, "price-history": {'))
            self.refused(t.targets, self.source)
            registry.write_bytes(original.replace(b'wordpress/writeleash-price-history', b'wordpress/writeleash-price-campaigns'))
            self.refused(t.targets, self.source)
        finally:
            registry.write_bytes(original)
        for data in [b'../sibling.php\n', b'/absolute.php\n', b'a.php\na.php\n', b'./a.php\n', b'']:
            self.refused(t.manifest, data)

    def test_04_symbol_and_import_collisions(self):
        for target in [key for key in SELECTED if key != 'writeleash']:
            spec, paths, payload = self.payload(target)
            for extra in [b'\nfinal class Plugin {}', b'\nnamespace { function shared() {} }',
                          b"\ndefine('WRITELEASH_VERSION', '99');", b'\nconst SHARED = 1;',
                          b"\nrequire __DIR__ . '/../writeleash/writeleash.php';",
                          b'\nrequire $sibling;', b'\n\\WriteLeash\\Plugin::boot();',
                          b"\nregister_rest_route('writeleash/v1', '/dispatch', array());",
                          b"\nupdate_option('writeleash_runner_state', 'active');",
                          b"\nupdate_option('write' . 'leash_runner_state', 'active');",
                          b"\nupdate_option($option, 'active');",
                          b'\n`echo unreviewed`;',
                          b"\n$wpdb->query('DELETE FROM wp_options WHERE option_name LIKE writeleash_%');",
                          b"\nas_unschedule_all_actions('writeleash_process_job', array(), 'writeleash-jobs');"]:
                self.refused(t.payload_check, {**payload, spec['main']: payload[spec['main']] + extra}, spec, paths)

            for extra in [b"private const VERSION = '0.0.0';", b'public static function activate(): void {}']:
                changed = payload['includes/class-plugin.php'].replace(b'final class Plugin {', b'final class Plugin {' + extra)
                self.refused(t.payload_check, {**payload, 'includes/class-plugin.php': changed}, spec, paths)

    def test_05_reserved_ownership_collisions(self):
        # No fake tables/routes/actions are registered by the scaffolds.
        # Reserved identities must still reject drift into either sibling/flagship.
        for target in [key for key in SELECTED if key != 'writeleash']:
            spec, paths, payload = self.payload(target)
            for field in ['table_prefix', 'option_prefix', 'scheduler_group', 'action_hook', 'rest_namespace']:
                for foreign in [self.registry[key][field] for key in ('price-history', 'price-campaigns') if key != target] + ['writeleash_jobs', 'writeleash/v1']:
                    changed = payload['includes/class-plugin.php'].replace(spec[field].encode(), foreign.encode())
                    self.refused(t.payload_check, {**payload, 'includes/class-plugin.php': changed}, spec, paths)
        history = self.registry['price-history']
        campaigns = self.registry['price-campaigns']
        for field in ['namespace', 'table_prefix', 'option_prefix', 'scheduler_group', 'action_hook', 'rest_namespace']:
            self.assertNotEqual(history[field], campaigns[field])

    def test_06_bootstrap_and_lifecycle_doubles(self):
        subprocess.run(['php', str(ROOT / 'wordpress/tests/portfolio/bootstrap-lifecycle.php'), str(self.source / 'wordpress')], check=True)
        for target in [key for key in SELECTED if key != 'writeleash']:
            spec, paths, payload = self.payload(target)
            other = 'price-campaigns' if target == 'price-history' else 'price-history'
            changed = payload['uninstall.php'].replace((spec['option_prefix'] + 'version').encode(),
                                                       (self.registry[other]['option_prefix'] + 'version').encode())
            self.refused(t.payload_check, {**payload, 'uninstall.php': changed}, spec, paths)

    def test_07_committed_unknown_source_refused(self):
        extra = self.source / 'wordpress/writeleash-price-history/includes/unmapped.php'
        extra.write_text('<?php namespace WriteLeash\\PriceHistory;')
        try:
            self.commit('adversarial unmapped PHP')
            sha = a.git(self.source, 'rev-parse', 'HEAD').decode().strip()
            self.refused(a.build, self.source, sha, self.base / 'refused', 'price-history')
            self.assertFalse((self.base / 'refused').exists())
        finally:
            a.git(self.source, 'reset', '--hard', self.sha)

    def test_08_archive_contamination(self):
        for target in SELECTED:
            spec, _, payload = self.payload(target)
            for extra in ['writeleash.php', '../sibling.php', 'assets/price-history/icon.png', 'assets/price-campaigns/icon.png', 'internal.php']:
                path = self.base / 'injected.zip'
                with zipfile.ZipFile(path, 'w') as archive:
                    for entry, data in sorted({**payload, extra: b'<?php'}.items()):
                        info = zipfile.ZipInfo(Path(spec['root']).name + '/' + entry, a.STAMP)
                        info.create_system = 3
                        info.external_attr = (stat.S_IFREG | 0o644) << 16
                        archive.writestr(info, data)
                self.refused(a.audit_zip, path, payload, spec)

    def test_09_exact_reproducibility(self):
        for target in SELECTED:
            source2 = self.base / ('source2-' + target)
            subprocess.run(['git', 'clone', '-q', '--shared', str(self.source), str(source2)], check=True)
            first_path = self.base / (target + '-first')
            second_path = self.base / (target + '-second')
            first = a.build(self.source, self.sha, first_path, target)
            second = a.build(source2, self.sha, second_path, target)
            self.assertEqual(first, second)
            self.assertEqual(first['TARGET'], target)
            self.assertEqual(first['SOURCE_GIT_SHA'], self.sha)
            self.assertEqual(first['PLUGIN_ROOT'], Path(self.registry[target]['root']).name)
            archive = first['PLUGIN_ROOT'] + '-' + first['VERSION'] + '.zip'
            self.assertEqual((first_path / archive).read_bytes(), (second_path / archive).read_bytes())
            print('#144 deterministic target=' + target + ' source=' + self.sha + ' ZIP_SHA256=' + first['ZIP_SHA256'])
            self.assertFalse(any('/assets/' in row['path'] for row in first['RUNTIME_FILES']))

    def test_10_ci_owners_and_fanout(self):
        for target in ['price-history', 'price-campaigns']:
            path = self.registry[target]['root'] + '/' + self.registry[target]['main']
            result = o.requirements([path])
            self.assertEqual(result['CODE_INTEGRATION'], [target])
            self.assertEqual(result['STATIC_OWNERS'], [target])
            self.assertEqual(result['DEFERRED_RELEASE_OWNERS'], [])
            for suffix in ['readme.txt', 'README.md']:
                cheap = o.requirements([self.registry[target]['root'] + '/' + suffix])
                self.assertEqual(cheap['CODE_INTEGRATION'], [])
            self.assertEqual(o.requirements([self.registry[target]['assets'] + '/README.md'])['CODE_INTEGRATION'], [])
            for path in [self.registry[target]['root'] + '/includes/new.php', self.registry[target]['root'] + '/doc.php']:
                self.refused(o.classify, path)
        for shared in ['wordpress/release/build-wordpress-org.py', 'wordpress/release/plugin-targets.json',
                       '.github/ci/ownership.py', '.github/ci/path-ownership.json', '.github/ci/pr-fast.sh',
                       'wordpress/tests/portfolio/integration.php', '.github/workflows/release-full.yml']:
            result = o.requirements([shared])
            self.assertEqual(set(result['CODE_INTEGRATION']), {'historical', 'price-history', 'price-campaigns'})
            self.assertEqual(set(result['STATIC_OWNERS']), set(self.registry))
        for unknown in ['wordpress/new-plugin/new.php', 'wordpress/unknown.php', 'wordpress/assets/price-history/loader.php', 'unknown.php', 'docs/loader.php']:
            self.refused(o.classify, unknown)
        # A changed satellite manifest must consume its integration; assets stay cheap.
        self.assertEqual(o.requirements([self.registry['price-history']['manifest']])['CODE_INTEGRATION'], ['price-history'])


if __name__ == '__main__':
    unittest.main(argv=[sys.argv[0], *remaining])
