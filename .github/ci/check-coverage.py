#!/usr/bin/env python3
"""Stable cheap required check propagating every selected integration failure."""
import json
import os

needs = json.loads(os.environ['NEEDS_JSON'])
assert needs['fast']['result'] == 'success', 'PR_FAST failed or was cancelled'
selected = {gate for gate, value in needs['fast']['outputs'].items() if value == 'true'}
for gate in selected:
    assert needs[gate]['result'] == 'success', (
        'Owning integration did not succeed: ' + gate + '. Fork runtime changes require '
        'maintainer promotion of the reviewed exact SHA to a same-repository PR branch.'
    )
print('#133 CI_COVERAGE: ' + str(len(selected)) + ' selected integrations succeeded; no skipped owner PASS')
