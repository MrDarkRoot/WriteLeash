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
    # Suite definitions are executable ownership, not generic CI documentation.
    for gate, workflow in MAP['workflows'].items():
        if path == '.github/workflows/' + workflow:
            return {gate}
    # Even #112 evidence documentation changes must consume the direct owner.
    if path.startswith('wordpress/tests/acceptance/'):
        return {'acceptance'}
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
    if not matched and path.startswith(('wordpress/tests/', '.github/workflows/')):
        raise AssertionError('Unmapped test harness: ' + path)
    return gates


def header_only(before, after, allowed_fields):
    """Normalize specified fields in the first PHP comment; byte-identical body.

    Match the actual first closing comment delimiter before normalizing values,
    so a forged comment terminator cannot hide executable code on a header line.
    Callers choose listing-only or compatibility ownership; this is not a gate exemption.
    """
    def normalize(source):
        match = re.fullmatch(r'(<\?php\s*/\*\*)(.*?)(\*/)(.*)', source, re.S)
        if not match:
            return None
        header = match[2]
        fields = '|'.join(re.escape(field) for field in allowed_fields)
        header = re.sub(r'^ \* (?:' + fields + r'):[^\r\n]*\r?\n?', '', header, flags=re.M)
        return match[1] + header + match[3] + match[4]
    left, right = normalize(before), normalize(after)
    return before != after and left is not None and left == right


def listing_header_only(before, after):
    return header_only(before, after, MAP['listing_header_fields'])


def classify_change(path, before, after):
    if path == 'wordpress/writeleash/writeleash.php':
        if listing_header_only(before, after):
            return set()
        if header_only(before, after, MAP['listing_header_fields'] + MAP['compatibility_header_fields']):
            return set(MAP['compatibility_header_gates'])
    if listing_wiring_only(path, before, after):
        return set()
    return classify(path)


def requirements(paths, changes=None):
    changes = changes or {}
    gates = set()
    for path in paths:
        gates.update(classify_change(path, *changes[path]) if path in changes else classify(path))
    direct_acceptance = any(
        path == '.github/workflows/wordpress-woo-acceptance.yml' or path.startswith('wordpress/tests/acceptance/')
        for path in paths
    )
    integrations = gates - {'acceptance'}
    if direct_acceptance:
        integrations.add('acceptance')
    return {'PR_FAST': True, 'CODE_INTEGRATION': sorted(integrations),
            'RELEASE_FULL_OWNERS': sorted(gates),
            'DEFERRED_RELEASE_OWNERS': ['acceptance'] if 'acceptance' in gates and not direct_acceptance else []}


def acceptance_budget_approved(event):
    pr = event.get('pull_request', {})
    sha = pr.get('head', {}).get('sha', '')
    repository = event.get('repository', {}).get('full_name')
    return bool(re.fullmatch('[0-9a-f]{40}', sha) and repository and
                pr.get('head', {}).get('repo', {}).get('full_name') == repository and
                any(label.get('name') == 'ci112:' + sha for label in pr.get('labels', [])))


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
        assert classify('.github/workflows/' + workflow) == {gate}, 'Suite workflow lost self-owner: ' + gate
        assert 'uses: ./.github/workflows/' + workflow in pr, 'Missing integration: ' + gate
        condition = f"if: needs.fast.outputs.{gate} == 'true' && (needs.fast.outputs.acceptance != 'true' || needs.fast.outputs.acceptance_budget == 'approved') && github.event.pull_request.head.repo.full_name == github.repository"
        assert condition in pr, 'Missing owner/budget/fork condition: ' + gate
        assert f"{gate}: ${{{{ steps.owners.outputs.{gate} }}}}" in pr, 'Missing classifier output: ' + gate
        assert f"{gate}:\n    needs: preflight\n    uses: ./.github/workflows/{workflow}" in full, 'Missing release preflight dependency: ' + gate
    assert 'needs: [fast, feasibility, native, engine, foundation, research, adapter, historical, plan, journal, jobs, undo, admin, acceptance]' in pr, 'Coverage must consume every integration including acceptance'
    assert "acceptance_budget: ${{ steps.owners.outputs.acceptance_budget }}" in pr
    assert 'labeled, unlabeled' in pr, 'SHA-bound budget approval must re-evaluate'
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
    assert not requirements(['wordpress/writeleash/readme.txt', 'wordpress/release/CLAIM-MATRIX.md', 'wordpress/tests/release/claim-matrix-audit.php'])['CODE_INTEGRATION']
    for name, required in [('change-plan', {'plan','journal','jobs','undo','admin','acceptance'}), ('job-worker', {'jobs','undo','admin','acceptance'}), ('undo-worker', {'undo','admin','acceptance'}), ('free-admin', {'admin','acceptance'})]:
        assert required <= classify('wordpress/writeleash/includes/free/class-' + name + '.php')
    try:
        classify('wordpress/writeleash/includes/free/class-unreviewed.php')
    except AssertionError:
        pass
    else:
        raise AssertionError('New production PHP fell through')
    main = (ROOT / 'wordpress/writeleash/writeleash.php').read_text()
    main_path = 'wordpress/writeleash/writeleash.php'
    for field in ['Requires at least', 'Requires PHP']:
        changed = re.sub(r'( \* ' + re.escape(field) + r':)[^\r\n]*', r'\1 99.0', main, count=1)
        assert not listing_header_only(main, changed), 'Runtime requirement exempted: ' + field
        assert classify_change(main_path, main, changed) == {'foundation', 'adapter', 'historical'}, 'Compatibility owner missing: ' + field
        print('#133 ' + field + ' header-only change: adapter, foundation, historical PASS')
    for field in ['Description', 'Tested up to']:
        changed = re.sub(r'( \* ' + re.escape(field) + r':)[^\r\n]*', r'\1 listing-change', main, count=1)
        if changed == main:
            changed = main.replace(' */', ' * ' + field + ': 7.1\n */', 1)
        assert listing_header_only(main, changed) and not classify_change(main_path, main, changed), 'Non-runtime listing field scheduled integration: ' + field
        print('#133 ' + field + ' header-only change: PR_FAST only PASS')
        assert classify_change(main_path, main, changed + '\nrequire "untrusted.php";') == production()[main_path], 'Runtime change hidden by metadata'
    forged = re.sub(r'( \* Requires at least:)[^\r\n]*', r'\1 */ system("untrusted"); /*', main, count=1)
    assert not listing_header_only(main, forged), 'Comment termination hid runtime code'
    assert classify_change(main_path, main, forged) == production()[main_path], 'Forged terminator lost runtime owners'
    release = (ROOT / 'wordpress/tests/release/run.sh').read_text()
    added = release + 'php "$here/claim-matrix-audit.php" "$repo"\n'
    assert listing_wiring_only('wordpress/tests/release/run.sh', release, added)
    assert not listing_wiring_only('wordpress/tests/release/run.sh', release, added + 'exit 0\n')
    for path in ['.github/workflows/wordpress-woo-acceptance.yml', 'wordpress/tests/acceptance/journey.php', 'wordpress/tests/acceptance/SUPPORT-REPAIR.md']:
        assert requirements([path])['CODE_INTEGRATION'] == ['acceptance'], 'Direct acceptance owner was dropped'
    runtime = requirements(['wordpress/writeleash/includes/free/class-free-admin.php'])
    assert runtime['CODE_INTEGRATION'] == ['admin'] and runtime['DEFERRED_RELEASE_OWNERS'] == ['acceptance'], 'Runtime-only #112 deferral not explicit'
    for gate in ['plan', 'jobs', 'historical', 'native', 'acceptance']:
        for selected, result, expected in [(False, 'skipped', 0), (True, 'success', 0), (True, 'failure', 1), (True, 'skipped', 1), (True, 'cancelled', 1), (True, None, 1)]:
            outputs = {'acceptance_budget': 'approved', **{owner: 'false' for owner in MAP['workflows']}}
            outputs[gate] = str(selected).lower()
            needs = {'fast': {'result': 'success', 'outputs': outputs}}
            needs.update({owner: {'result': 'skipped'} for owner in MAP['workflows']})
            if result is None:
                del needs[gate]
            else:
                needs[gate] = {'result': result}
            process = subprocess.run(['python3', str(ROOT / '.github/ci/check-coverage.py')], env={**os.environ, 'NEEDS_JSON': json.dumps(needs)}, capture_output=True)
            assert process.returncode == expected, 'Owning integration failure/skip did not propagate: ' + gate
    missing = {'fast': {'result': 'success', 'outputs': {owner: 'false' for owner in MAP['workflows'] if owner != 'acceptance'}}}
    process = subprocess.run(['python3', str(ROOT / '.github/ci/check-coverage.py')], env={**os.environ, 'NEEDS_JSON': json.dumps(missing)}, capture_output=True)
    assert process.returncode == 1, 'Coverage accepted an unconsumed acceptance output'
    pending = {'fast': {'result': 'success', 'outputs': {'acceptance_budget': 'pending', **{owner: 'false' for owner in MAP['workflows']}}}, 'acceptance': {'result': 'success'}}
    pending['fast']['outputs']['acceptance'] = 'true'
    process = subprocess.run(['python3', str(ROOT / '.github/ci/check-coverage.py')], env={**os.environ, 'NEEDS_JSON': json.dumps(pending)}, capture_output=True)
    assert process.returncode == 1, 'Unapproved acceptance bypassed budget control'
    try:
        classify('.github/workflows/unreviewed.yml')
    except AssertionError:
        pass
    else:
        raise AssertionError('Unknown suite workflow silently became static-only')
    event = {'repository': {'full_name': 'owner/repo'}, 'pull_request': {'head': {'sha': 'a' * 40, 'repo': {'full_name': 'owner/repo'}}, 'labels': [{'name': 'ci112:' + 'a' * 40}]}}
    assert acceptance_budget_approved(event)
    event['pull_request']['head']['sha'] = 'b' * 40
    assert not acceptance_budget_approved(event), 'Old approval authorized a new SHA'
    event['pull_request']['head']['sha'] = 'a' * 40
    event['pull_request']['head']['repo']['full_name'] = 'fork/repo'
    assert not acceptance_budget_approved(event), 'Fork code authorized expensive work'
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
    changes = {}
    for path in paths:
        if args.base and (path == 'wordpress/writeleash/writeleash.php' or path in MAP['listing_audit_wiring']):
            before = subprocess.check_output(['git', 'show', args.base + ':' + path], cwd=ROOT).decode()
            after = subprocess.check_output(['git', 'show', args.head + ':' + path], cwd=ROOT).decode()
            changes[path] = (before, after)
    result = requirements(paths, changes)
    if paths:
        print(json.dumps(result))
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            for gate in MAP['workflows']:
                output.write(f'{gate}={str(gate in result["CODE_INTEGRATION"]).lower()}\n')
            event = json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text()) if os.environ.get('GITHUB_EVENT_PATH') else {}
            output.write('acceptance_budget=' + ('approved' if acceptance_budget_approved(event) else 'pending') + '\n')


if __name__ == '__main__':
    main()
