#!/usr/bin/env python3
"""Compare exactly two completed Phase 0 runs offline; never contact Docker."""

import argparse
import json
from pathlib import Path


# Interpretation warning only, not a product performance threshold.
BETWEEN_RUN_BASELINE_SPREAD_WARNING = 0.20
BETWEEN_RUN_PAIRED_CHANGE_SPAN_WARNING_PP = 20.0


def compare(first, second):
    metadata = [json.loads((path / "metadata.json").read_text()) for path in (first, second)]
    for i, item in enumerate(metadata, 1):
        if not item["status"].startswith("complete_"):
            raise ValueError(f"run {i} is not complete; retain its failure artifacts instead")
    if {m["run"] for m in metadata} != {1, 2}:
        raise ValueError("expected run ids 1 and 2, once each")
    if metadata[0]["tested_commit"] != metadata[1]["tested_commit"]:
        raise ValueError("cannot compare different implementation SHAs")
    if metadata[0]["base_digest"] != metadata[1]["base_digest"]:
        raise ValueError("cannot compare different PostgreSQL image digests")
    summaries = [json.loads((path / "summary.json").read_text()) for path in (first, second)]
    indexes = [{cell["case"]: cell for cell in summary["accepted"]} for summary in summaries]
    if set(indexes[0]) != set(indexes[1]):
        raise ValueError("runs did not cover the same accepted workload cases")
    cases = []
    for name in indexes[0]:
        a, b = indexes[0][name], indexes[1][name]
        baselines = [a["baseline"]["mean_tps"], b["baseline"]["mean_tps"]]
        effects = [a["protected_mean_tps_change_pct"], b["protected_mean_tps_change_pct"]]
        warning = []
        if max(baselines) / min(baselines) - 1 > BETWEEN_RUN_BASELINE_SPREAD_WARNING:
            warning.append("between-run baseline mean TPS max/min spread > 20%")
        if min(effects) < 0 < max(effects) and max(effects) - min(effects) > BETWEEN_RUN_PAIRED_CHANGE_SPAN_WARNING_PP:
            warning.append("between-run paired effect reverses sign over > 20 percentage points")
        cases.append({"case": name, "baseline_tps_by_run": baselines,
                      "protected_tps_change_pct_by_run": effects,
                      "within_run_warnings_by_run": [a["reproducibility_warnings"], b["reproducibility_warnings"]],
                      "between_run_warnings": warning})
    return {"tested_commit": metadata[0]["tested_commit"],
            "run_ids_in_input_order": [m["run"] for m in metadata],
            "baseline_spread_warning": BETWEEN_RUN_BASELINE_SPREAD_WARNING,
            "sign_reversing_paired_effect_span_warning_percentage_points": BETWEEN_RUN_PAIRED_CHANGE_SPAN_WARNING_PP,
            "threshold_scope": "measurement reproducibility only; NOT product PASS",
            "interpretation_warning": any(case["between_run_warnings"] or
                                          any(messages for messages in case["within_run_warnings_by_run"])
                                          for case in cases),
            "cases": cases}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run1", type=Path, required=True)
    parser.add_argument("--run2", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    options = parser.parse_args()
    data = compare(options.run1, options.run2)
    options.output.write_text(json.dumps(data, indent=2) + "\n")
    print(f"offline comparison saved: {options.output} (warning={data['interpretation_warning']})")


if __name__ == "__main__":
    main()
