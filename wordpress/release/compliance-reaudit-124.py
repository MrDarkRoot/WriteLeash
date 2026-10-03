#!/usr/bin/env python3
"""Fail-closed identity and evidence check for the new #124 artifact only."""
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import zipfile
sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('builder', HERE / 'build-wordpress-org.py')
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)
EXACT_SHA = '7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e'
EXACT_SIZE = 396314


def plugin_check_output(output):
    b.require(output.strip() == 'Success: Checks complete. No errors found.',
              'Plugin Check output requires individual finding review')


def audit(archive, installed, plugin_check=None, identity_only=False):
    data = archive.read_bytes()
    b.require(b.sha(data) == EXACT_SHA and len(data) == EXACT_SIZE, 'CHECKSUM DRIFT')
    reviewed = json.loads((HERE / 'artifact-124-evidence.json').read_text())
    b.require(reviewed['ZIP_SHA256'] == EXACT_SHA and reviewed['ZIP_SIZE'] == EXACT_SIZE,
              'regeneration evidence identity drift')
    with zipfile.ZipFile(archive) as z:
        payload = {i.filename.removeprefix('writeleash/'): z.read(i) for i in z.infolist()}
    b.audit_zip(archive, payload)
    b.require(b.inventory(payload) == reviewed['RUNTIME_FILES'], 'runtime inventory drift')
    b.require(b.tree(installed) == payload, 'installed tree != ZIP')
    b.require(b.tree_hash(payload) == reviewed['SVN_TRUNK_TREE_HASH'] == reviewed['SVN_TAG_TREE_HASH'], 'tree hash drift')
    subprocess.run([sys.executable, str(HERE / 'nonce-artifact-audit.py'), str(installed)], check=True)
    if plugin_check is not None:
        plugin_check_output(plugin_check.read_text())
    if not identity_only:
        b.require(plugin_check is not None, 'current Plugin Check output required')
        evidence = json.loads((HERE / 'artifact-124-reaudit-evidence.json').read_text())
        b.require(evidence['ZIP_SHA256'] == EXACT_SHA and evidence['ZIP_SIZE'] == EXACT_SIZE,
                  'reaudit evidence identity drift')
        b.require(evidence['UNRESOLVED_SECURITY_FINDINGS'] == [] and evidence['SOURCE_CHANGE_REQUIRED'] is False,
                  'unresolved finding; source change required')
        required = {'CHECKSUM_REPRODUCTION', 'INSTALLED_TREE_MATCHES_ZIP', 'PLUGIN_CHECK',
                    'PHPCS_SUPPRESSION_REVIEW', 'C124_001', 'CAPABILITY_NONCE',
                    'MUTATION_INPUT_VALIDATION', 'OUTPUT_ESCAPING', 'SQL_SCHEMA_SAFETY',
                    'REST_AUTHORIZATION', 'DIRECT_ACCESS_GUARDS', 'REMOTE_CODE_UPDATER_TRACKING',
                    'FREE_TRIALWARE_PAYWALL', 'ADMIN_BEHAVIOR', 'PACKAGE_CLEANLINESS',
                    'SOURCE_READABILITY', 'INSTALL', 'DEACTIVATE', 'REACTIVATE',
                    'UNINSTALL_SAFETY', 'WOO_PRODUCT_DATA_PRESERVED', 'CLAIM_MATRIX'}
        b.require(set(evidence['REQUIRED_GATES']) == required and
                  all(v == 'PASS' for v in evidence['REQUIRED_GATES'].values()), 'required gate incomplete')
        b.require(evidence['PHPCS_SUPPRESSION_SITES'] == len(evidence['PHPCS_SUPPRESSIONS']) == 44,
                  'suppression review incomplete')
        for site in evidence['PHPCS_SUPPRESSIONS']:
            b.require(site['artifact_file_sha256'] == b.sha(payload[site['file']]) and
                      site['disposition'] == 'NON_BLOCKING_NOTE' and bool(site['reason']),
                      'suppression evidence mismatch')
        pcp = evidence['PLUGIN_CHECK']
        b.require(all(pcp[level] == 0 for level in ['ERROR', 'WARNING', 'NOTICE', 'INFO']) and
                  pcp['FINDINGS'] == [] and pcp['EXCLUSIONS'] == [], 'Plugin Check evidence mismatch')
        b.require(evidence['C124_001']['STATUS'] == 'CLOSED' and evidence['C124_001']['ASSERTIONS'] == 111,
                  'C124-001 not closed')
        b.require(evidence['AUTHORIZED_FOR_125'] is True and evidence['AUTHORIZED_ZIP'] == EXACT_SHA,
                  '#125 not authorized for this checksum')
    print('New exact-artifact identity/installed boundary/evidence PASS' + (' (identity only; no authorization)' if identity_only else ''))


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--zip', type=Path, required=True)
    p.add_argument('--installed', type=Path, required=True)
    p.add_argument('--plugin-check', type=Path)
    p.add_argument('--identity-only', action='store_true')
    args = p.parse_args()
    try:
        audit(args.zip, args.installed, args.plugin_check, args.identity_only)
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print('BLOCKED: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        sys.exit(1)
