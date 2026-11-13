"""Pure-stdlib checks: no Docker, PostgreSQL, or shared security fixture used."""

import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

import compare
import run as benchmark


BASELINE = {"subscriptions_rows": benchmark.ROWS, "subscriptions_changed": 0,
            "users_rows": benchmark.ROWS, "users_changed": 0, "users_admin": 0,
            "refunds_rows": benchmark.ROWS, "refunds_changed": 0,
            "refunds_total": 0, "audit_rows": 0}


class MethodologyTests(unittest.TestCase):
    def test_two_run_offline_comparison_and_incomplete_gate(self):
        with tempfile.TemporaryDirectory() as directory:
            roots = [Path(directory) / f"run{i}" for i in (1, 2)]
            for i, path in enumerate(roots, 1):
                path.mkdir()
                (path / "metadata.json").write_text(json.dumps({
                    "status": "complete_without_triggered_warnings", "run": i,
                    "tested_commit": "example-sha", "base_digest": "example-digest"}))
                (path / "summary.json").write_text(json.dumps({"accepted": [{
                    "case": "100", "baseline": {"mean_tps": 100},
                    "protected_mean_tps_change_pct": -20,
                    "reproducibility_warnings": []}]}))
            self.assertFalse(compare.compare(*roots)["interpretation_warning"])
            summary = json.loads((roots[1] / "summary.json").read_text())
            summary["accepted"][0]["baseline"]["mean_tps"] = 50
            summary["accepted"][0]["protected_mean_tps_change_pct"] = 15
            (roots[1] / "summary.json").write_text(json.dumps(summary))
            self.assertTrue(compare.compare(*roots)["interpretation_warning"])
            meta = json.loads((roots[1] / "metadata.json").read_text())
            meta["status"] = "interrupted"
            (roots[1] / "metadata.json").write_text(json.dumps(meta))
            with self.assertRaisesRegex(ValueError, "not complete"):
                compare.compare(*roots)

    def test_balanced_order_and_reproducible_pair_seeds(self):
        for run in (1, 2):
            plan = benchmark.schedule(run)
            self.assertEqual(plan, benchmark.schedule(run))
            self.assertEqual(len(plan), benchmark.ROUNDS * len(benchmark.CASES) * 2)
            for round_no in range(1, benchmark.ROUNDS + 1):
                for case in benchmark.CASES:
                    pair = [x for x in plan if x["round"] == round_no and x["case"] == case]
                    self.assertEqual({tuple(x["arms"]) for x in pair},
                                     {("baseline", "protected"), ("protected", "baseline")})
                    self.assertNotEqual(pair[0]["measured_seed"], pair[0]["warmup_seed"])
                    self.assertNotEqual(pair[0]["measured_seed"], pair[1]["measured_seed"])

    def test_pair_elapsed_time_and_variability_warning(self):
        raw = []
        with mock.patch.object(benchmark, "ROUNDS", 3), mock.patch.object(benchmark, "PAIRS", 2):
            for case in benchmark.CASES:
                for round_no in range(1, 4):
                    for pair in (1, 2):
                        for order, arm in enumerate(("baseline", "protected"), 1):
                            tps = 100 if arm == "baseline" else 80
                            for trial in (1, 2):
                                raw.append({"kind": "accepted", "case": case, "phase": "measured",
                                            "round": round_no, "pair": pair, "order": order, "arm": arm,
                                            "trial": trial, "latency_us": 1000 if arm == "baseline" else 1250,
                                            "tps": tps, "elapsed_s": 2/tps, "rows_per_tx": 1})
            for case in ("row", "transition", "numeric"):
                raw.append({"kind": "denied", "case": case, "latency_us": 100, "commit_us": 50})
            result = benchmark.summarize(raw)
        self.assertFalse(result["interpretation_warning"])
        self.assertEqual(result["accepted"][0]["baseline"]["rounds"][0]["tps"], 100)
        self.assertEqual(result["accepted"][0]["protected"]["rounds"][0]["tps"], 80)
        self.assertEqual(len(result["accepted"][0]["paired_blocks"]), 6)
        raw[0]["elapsed_s"] = 2/40
        with mock.patch.object(benchmark, "ROUNDS", 3), mock.patch.object(benchmark, "PAIRS", 2):
            self.assertTrue(benchmark.summarize(raw)["interpretation_warning"])

    def test_first_missing_denial_is_saved_before_stopping(self):
        output = "\n".join(["START_transition_1", "BEGIN", "Time: 0.010 ms",
                            "SAVEPOINT", "Time: 0.010 ms", "ERROR: unrelated failure",
                            "Time: 0.100 ms", "AFTER_UPDATE_transition_1 54000",
                            "ROLLBACK", "Time: 0.010 ms", "ERROR: CommitCap top-level transaction denied",
                            "Time: 0.020 ms", "AFTER_COMMIT_transition_1 54000",
                            "END_transition_1", ""])
        with tempfile.TemporaryDirectory() as directory, \
             mock.patch.object(benchmark, "CONTAINER", "test-container", create=True), \
             mock.patch.object(benchmark, "denial_attempt", return_value=(output, True, 0)) as attempt, \
             mock.patch.object(benchmark, "admin_state", return_value=BASELINE), \
             mock.patch.object(benchmark, "docker", return_value=subprocess.CompletedProcess([], 0, "server log", "")), \
             mock.patch.object(benchmark, "DENIED", 3):
            with self.assertRaisesRegex(RuntimeError, "first anomaly saved"):
                benchmark.denied_case("transition", Path(directory), 1)
            self.assertEqual(attempt.call_count, 1)
            artifact = json.loads((Path(directory) / "denials/transition/trial-001.json").read_text())
            self.assertIn("UPDATE public.users SET role='admin'", artifact["psql_input"])
            self.assertEqual(artifact["combined_stdout_stderr"], output)
            self.assertEqual(artifact["observed"]["update_sqlstates"], ["54000"])
            self.assertEqual(artifact["observed"]["commit_sqlstates"], ["54000"])
            self.assertEqual(artifact["observed"]["server_log_since_started_utc"], "server log")
            self.assertEqual(artifact["observed"]["fresh_admin_state"], BASELINE)
            self.assertFalse(artifact["all_expectations_met"])
            self.assertFalse((Path(directory) / "denials/transition/trial-002.json").exists())

    def test_durable_over_authority_aborts_and_saves_state(self):
        bad = dict(BASELINE, users_changed=1, users_admin=1)
        with tempfile.TemporaryDirectory() as directory, \
             mock.patch.object(benchmark, "CONTAINER", "test-container", create=True), \
             mock.patch.object(benchmark, "denial_attempt", return_value=("END_transition_1\n", True, 0)) as attempt, \
             mock.patch.object(benchmark, "admin_state", return_value=bad), \
             mock.patch.object(benchmark, "docker", return_value=subprocess.CompletedProcess([], 0, "", "")), \
             mock.patch.object(benchmark, "DENIED", 3):
            with self.assertRaises(benchmark.DurableAuthorityFailure):
                benchmark.denied_case("transition", Path(directory), 2)
            self.assertEqual(attempt.call_count, 1)
            artifact = json.loads((Path(directory) / "denials/transition/trial-001.json").read_text())
            self.assertTrue(artifact["observed"]["durable_authority_failure"])
            self.assertEqual(artifact["observed"]["fresh_admin_state"]["users_admin"], 1)

    def test_successful_denial_keeps_command_timings_separate(self):
        output = "\n".join(["BEGIN", "Time: 0.010 ms", "SAVEPOINT", "Time: 0.011 ms",
                            "ERROR: CommitCap forbidden state transition (* -> admin)",
                            "Time: 0.222 ms", "AFTER_UPDATE_transition_1 54000",
                            "ROLLBACK", "Time: 0.012 ms",
                            "ERROR: CommitCap top-level transaction denied after mutation authority violation",
                            "Time: 0.333 ms", "AFTER_COMMIT_transition_1 54000",
                            "END_transition_1", ""])
        with tempfile.TemporaryDirectory() as directory, \
             mock.patch.object(benchmark, "CONTAINER", "test-container", create=True), \
             mock.patch.object(benchmark, "denial_attempt", return_value=(output, True, 0)), \
             mock.patch.object(benchmark, "admin_state", return_value=BASELINE), \
             mock.patch.object(benchmark, "docker", return_value=subprocess.CompletedProcess([], 0, "log", "")), \
             mock.patch.object(benchmark, "DENIED", 1):
            self.assertEqual(benchmark.denied_case("transition", Path(directory), 2), [(222.0, 333.0)])
            artifact = json.loads((Path(directory) / "denials/transition/trial-001.json").read_text())
            self.assertTrue(artifact["all_expectations_met"])


if __name__ == "__main__":
    unittest.main()
