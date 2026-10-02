#!/usr/bin/env python3
"""Fail-closed production ownership; stdlib-only PR classifier/policy audit."""
import argparse
import fnmatch
import json
import os
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
MAP = json.loads((ROOT / '.github/ci/path-ownership.json').read_text())


def production():
    result = {}
    for group in MAP['production_groups']:
        for name in group['files']:
            path = group['prefix'] + name
            assert path not in result, 'Duplicate production owner: ' + path
            result[path] = set(group['gates'])
    return result


def classify(path):
    owners = production()
    if path in owners:
        return owners[path]
    if path.startswith('wordpress/writeleash/') and path.lower().endswith('.php'):
        raise AssertionError('Unmapped production PHP: ' + path)
    if path.lower().endswith('.md'):
        return set()
    gates = set()
    matched = False
    for rule in MAP['rules']:
        if any(fnmatch.fnmatchcase(path, pattern) for pattern in rule['paths']):
            matched = True
            gates.update(rule['gates'])
    # New executable test harnesses cannot silently become documentation.
    if not matched and path.startswith('wordpress/tests/'):
        raise AssertionError('Unmapped test harness: ' + path)
    return gates


def listing_header_only(before, after):
    """Only an allowlisted field in the first PHP comment; byte-identical body.

    Match the actual first closing comment delimiter before normalizing values,
    so a forged comment terminator cannot hide executable code on a header line.
    Dependency/version/runtime constant changes are not exempted.
    """
    def normalize(source):
        match = re.fullmatch(r'(<\?php\s*/\*\*)(.*?)(\*/)(.*)', source, re.S)
        if not match:
            return None
        header = match[2]
        fields = '|'.join(re.escape(field) for field in MAP['listing_header_fields'])
        header = re.sub(r'^( \* (?:' + fields + r'):)[^\r\n]*$', r'\1 <listing-value>', header, flags=re.M)
        return match[1] + header + match[3] + match[4]
    left, right = normalize(before), normalize(after)
    return before != after and left is not None and left == right


def listing_wiring_only(path, before, after):
    allowed = MAP['listing_audit_wiring'].get(path, [])
    def normalize(source):
        return ''.join(line for line in source.splitlines(keepends=True) if line.rstrip('\r\n') not in allowed)
    return bool(allowed) and before != after and normalize(before) == normalize(after)


def audit():
    owners = production()
    actual = {p.relative_to(ROOT).as_posix() for p in (ROOT / 'wordpress/writeleash').rglob('*') if p.is_file() and p.suffix.lower() == '.php'}
    assert set(owners) == actual, 'Production inventory differs: ' + repr(set(owners) ^ actual)
    for path, gates in owners.items():
        assert gates and gates <= MAP['workflows'].keys(), 'Invalid production gates: ' + path
    for p in (ROOT / 'wordpress/tests').rglob('*'):
        if p.is_file() and not any(part in ('cache', '.cache', '__pycache__') for part in p.parts):
            classify(p.relative_to(ROOT).as_posix())
    pr = (ROOT / '.github/workflows/pr-fast.yml').read_text()
    full = (ROOT / '.github/workflows/release-full.yml').read_text()
    for gate, workflow in MAP['workflows'].items():
        text = (ROOT / '.github/workflows' / workflow).read_text()
        assert re.search(r'^on:\n  workflow_call:', text, re.M), workflow + ' must be reusable only'
        assert 'pull_request:' not in text and '  push:' not in text, workflow + ' auto trigger'
        assert 'uses: ./.github/workflows/' + workflow in full, 'Missing release evidence: ' + gate
        if gate != 'acceptance':
            assert 'uses: ./.github/workflows/' + workflow in pr, 'Missing integration: ' + gate
            assert f"needs.fast.outputs.{gate} == 'true'" in pr, 'Missing owner condition: ' + gate
            assert f"{gate}: ${{{{ steps.owners.outputs.{gate} }}}}" in pr, 'Missing classifier output: ' + gate
            assert f"{gate}:\n    needs: preflight\n    uses: ./.github/workflows/{workflow}" in full, 'Missing release preflight dependency: ' + gate
    assert 'wordpress-woo-acceptance.yml' not in pr, '#112 must be intentional release evidence'
    assert 'cancel-in-progress: true' in pr and 'concurrency:' not in full
    assert 'name: CI_COVERAGE' in pr and 'if: always()' in pr, 'Stable owning-check summary missing'
    assert "test \"$REQUESTED_SHA\" = \"$GITHUB_SHA\"" in full, 'Exact-SHA release guard missing'
    for p in (ROOT / '.github/workflows').glob('*.yml'):
        text = p.read_text()
        assert 'contents: read' in text and 'pull_request_target' not in text and 'self-hosted' not in text
        assert 'secrets:' not in text and '${{ secrets.' not in text, 'Secrets must not enter test code'
        for action in re.findall(r'uses:\s*(\S+)', text):
            assert action.startswith('./.github/workflows/') or re.fullmatch(r'[\w-]+/[\w./-]+@[a-f0-9]{40}', action), 'Mutable action: ' + action
        for checkout in re.finditer(r'uses: actions/checkout@[^\n]+\n([^\n]*\n){0,4}', text):
            assert 'persist-credentials: false' in checkout.group(0), 'Persisted checkout token: ' + p.name
    # Meaningful boundary regressions, including a file not in current HEAD.
    for path in ['wordpress/writeleash/readme.txt', 'wordpress/release/CLAIM-MATRIX.md', 'wordpress/release/DIRECTORY-LISTING.md', 'wordpress/assets/banner-772x250.png', 'wordpress/tests/release/claim-matrix-audit.php', 'wordpress/tests/adapter/README.md', 'experiments/native_tx_state/README.md']:
        assert not classify(path), 'Listing-only path schedules integration: ' + path
    for name, required in [('change-plan', {'plan','journal','jobs','undo','admin','acceptance'}), ('job-worker', {'jobs','undo','admin','acceptance'}), ('undo-worker', {'undo','admin','acceptance'}), ('free-admin', {'admin','acceptance'})]:
        assert required <= classify('wordpress/writeleash/includes/free/class-' + name + '.php')
    try:
        classify('wordpress/writeleash/includes/free/class-unreviewed.php')
    except AssertionError:
        pass
    else:
        raise AssertionError('New production PHP fell through')
    main = (ROOT / 'wordpress/writeleash/writeleash.php').read_text()
    changed = re.sub(r'( \* Requires at least:)[^\r\n]*', r'\1 99.0', main, count=1)
    assert listing_header_only(main, changed), 'Listing minimum header classification broken'
    assert not listing_header_only(main, changed + '\nrequire "untrusted.php";'), 'Runtime change hidden by metadata'
    forged = re.sub(r'( \* Requires at least:)[^\r\n]*', r'\1 */ system("untrusted"); /*', main, count=1)
    assert not listing_header_only(main, forged), 'Comment termination hid runtime code'
    release = (ROOT / 'wordpress/tests/release/run.sh').read_text()
    added = release + 'php "$here/claim-matrix-audit.php" "$repo"\n'
    assert listing_wiring_only('wordpress/tests/release/run.sh', release, added)
    assert not listing_wiring_only('wordpress/tests/release/run.sh', release, added + 'exit 0\n')
    for selected, result, expected in [(False, 'skipped', 0), (True, 'success', 0), (True, 'failure', 1), (True, 'skipped', 1), (True, 'cancelled', 1)]:
        needs = {'fast': {'result': 'success', 'outputs': {'plan': str(selected).lower()}}, 'plan': {'result': result}}
        process = subprocess.run(['python3', str(ROOT / '.github/ci/check-coverage.py')], env={**os.environ, 'NEEDS_JSON': json.dumps(needs)}, capture_output=True)
        assert process.returncode == expected, 'Owning integration failure/skip did not propagate'
    print(f'#133 ownership/policy audit: {len(owners)} production PHP files; 13 release suites; listing-only=PR_FAST; new PHP rejected PASS')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--audit', action='store_true')
    parser.add_argument('--base')
    parser.add_argument('--head', default='HEAD')
    parser.add_argument('paths', nargs='*')
    args = parser.parse_args()
    if args.audit:
        audit()
    paths = args.paths
    if args.base:
        # Include both names on rename and deletion; no GitHub 300-file filter limit.
        raw = subprocess.check_output(['git', 'diff', '--name-only', '--no-renames', '-z', args.base, args.head], cwd=ROOT)
        paths += [p for p in raw.decode().split('\0') if p]
    gates = set()
    for path in paths:
        if args.base and (path == 'wordpress/writeleash/writeleash.php' or path in MAP['listing_audit_wiring']):
            before = subprocess.check_output(['git', 'show', args.base + ':' + path], cwd=ROOT).decode()
            after = subprocess.check_output(['git', 'show', args.head + ':' + path], cwd=ROOT).decode()
            if (path == 'wordpress/writeleash/writeleash.php' and listing_header_only(before, after)) or listing_wiring_only(path, before, after):
                continue
        gates.update(classify(path))
    if paths:
        print(json.dumps({'PR_FAST': True, 'CODE_INTEGRATION': sorted(gates - {'acceptance'}), 'RELEASE_FULL_OWNERS': sorted(gates)}))
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            for gate in MAP['workflows']:
                output.write(f'{gate}={str(gate in gates).lower()}\n')


if __name__ == '__main__':
    main()
