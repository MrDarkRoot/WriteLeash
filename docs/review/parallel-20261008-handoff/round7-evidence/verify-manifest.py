#!/usr/bin/env python3
"""Audit historical handoff entries without modifying historical artifacts."""
import collections
import datetime
import hashlib
import json
import os
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
manifest = root / 'SHA256SUMS'
output = Path(__file__).with_name('manifest-verification.json')
if not manifest.is_file():
    output.write_text(json.dumps({'manifest_path': str(manifest), 'status': 'EVIDENCE_UNAVAILABLE'}, indent=2))
    raise SystemExit(2)
entries, unsafe, invalid, missing, mismatches = [], [], [], [], []
for number, line in enumerate(manifest.read_text().splitlines(), 1):
    match = re.fullmatch(r'([0-9a-fA-F]{64}) ([ *])(.+)', line)
    if not match:
        invalid.append({'line': number, 'raw': line})
        continue
    digest, _, raw = match.groups()
    relative = Path(raw)
    resolved = (root / relative).resolve()
    entry = {'line': number, 'path': raw, 'normalized_path': relative.as_posix(), 'expected_sha256': digest.lower()}
    entries.append(entry)
    if relative.is_absolute() or '..' in relative.parts or not resolved.is_relative_to(root) or '\x00' in raw or '\\' in raw:
        unsafe.append(entry)
        continue
    if not resolved.is_file():
        missing.append(entry)
        continue
    entry['actual_sha256'] = hashlib.sha256(resolved.read_bytes()).hexdigest()
    entry['match'] = entry['actual_sha256'] == entry['expected_sha256']
    if not entry['match']:
        mismatches.append(entry)
counts = collections.Counter(e['normalized_path'] for e in entries)
duplicates = [{'path': path, 'count': count} for path, count in counts.items() if count > 1]
# Include symlinks as evidence paths, even if their target disappears. Do not
# traverse symlink directories into unrelated or task-external environments.
actual, links = set(), []
for directory, subdirectories, filenames in os.walk(root, followlinks=False):
    for name in subdirectories[:] + filenames:
        path = Path(directory) / name
        if path.is_symlink():
            relative = path.relative_to(root).as_posix()
            actual.add(relative)
            links.append({'path': relative, 'target': os.readlink(path), 'resolved_inside_handoff': path.resolve().is_relative_to(root), 'exists': path.exists()})
            if name in subdirectories:
                subdirectories.remove(name)
        elif path.is_file():
            actual.add(path.relative_to(root).as_posix())
new = {path for path in actual if path.startswith('round7-evidence/') or path == 'ROUND-7-INDEPENDENT-AUDIT.md'}
extra = sorted(actual - set(counts) - {'SHA256SUMS'} - new)
fail = any([unsafe, invalid, missing, mismatches, duplicates, extra])
result = {
    'observed_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'manifest_path': str(manifest), 'root_directory': str(root),
    'manifest_sha256': hashlib.sha256(manifest.read_bytes()).hexdigest(),
    'manifest_entry_count': len(entries), 'unique_entry_count': len(counts),
    'files_verified': sum(entry.get('match', False) for entry in entries),
    'missing_files': missing, 'extra_files': extra, 'hash_mismatches': mismatches,
    'duplicate_entries': duplicates, 'unsafe_entries': unsafe, 'invalid_entries': invalid,
    'coverage_rule': 'Historical report claims entire handoff folder; exclude SHA256SUMS itself and explicitly separate new Round 7 report/evidence. External symlinks are reported, not silently excluded.',
    'entry_integrity_status': 'FAIL' if any([unsafe, invalid, missing, mismatches, duplicates]) else 'PASS',
    'whole_folder_coverage_status': 'FAIL' if extra else 'PASS',
    'self_contained_archive_status': 'FAIL' if any(not link['resolved_inside_handoff'] for link in links) else 'PASS',
    'excluded_new_run_files': sorted(new), 'symlinks': links,
    'status': 'FAIL' if fail else 'PASS', 'entries': entries,
}
output.write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps({key: value for key, value in result.items() if key not in ['entries', 'excluded_new_run_files', 'symlinks', 'extra_files']}, indent=2))
raise SystemExit(1 if fail else 0)
