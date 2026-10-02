#!/usr/bin/env python3
"""Stable cheap required check propagating every selected integration failure."""
import json
import os
from pathlib import Path

needs = json.loads(os.environ['NEEDS_JSON'])
assert needs['fast']['result'] == 'success', 'PR_FAST failed or was cancelled'
owners = json.loads(Path(__file__).with_name('path-ownership.json').read_text())['workflows']
outputs = needs['fast']['outputs']
assert all(outputs.get(gate) in ('true', 'false') for gate in owners), 'Missing/invalid owner output (including acceptance)'
selected = {gate for gate in owners if outputs[gate] == 'true'}
if 'acceptance' in selected:
    assert outputs.get('acceptance_budget') == 'approved', (
        'Direct #112 owner requires maintainer budget approval ci112:<exact-head-sha>; '
        'selected suites remain blocked, not passed, while approval is pending.'
    )
for gate in sorted(selected):
    assert needs.get(gate, {}).get('result') == 'success', (
        'Owning integration did not succeed: ' + gate + '. Fork runtime changes require '
        'maintainer promotion of the reviewed exact SHA to a same-repository PR branch. '
        'Direct #112 changes require budget approval label ci112:<exact-head-sha>; '
        'an unexecuted acceptance owner is never green.'
    )
print('#133 CI_COVERAGE: ' + str(len(selected)) + ' selected integrations succeeded; no skipped owner PASS')
