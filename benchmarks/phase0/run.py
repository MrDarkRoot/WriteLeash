#!/usr/bin/env python3
"""Disposable PG16.4 Phase 0 benchmark; see README.md for measurement scope."""

import argparse
import csv
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import re
import statistics
import subprocess
import sys
import time

import telemetry


ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
PROJECT = "commitcap_bench"
DIGEST = "sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c"
ADMIN = "commitcap_native_admin"
WRITER = "commitcap_writer"
PASSWORDS = {ADMIN: "commitcap_native_admin_experiment_only", WRITER: "commitcap_writer_experiment_only"}
ROWS = 10000
WARMUP = 50
TX = 200
ROUNDS = 3
RUNS = 2
PAIRS = 2
DENIED = 30
CASES = ("1", "5", "100", "users", "refunds", "mixed")
# Interpretation warnings only. These are not product performance PASS criteria.
BASELINE_PAIR_SPREAD_WARNING = 0.20
BASELINE_ROUND_CV_WARNING = 0.10
PAIRED_CHANGE_SPREAD_WARNING_PP = 20.0
FIELDS = ["kind", "run", "round", "pair", "order", "arm", "phase", "case", "trial",
          "latency_us", "commit_us", "rows_per_tx", "tps", "elapsed_s", "durable_check"]


def utc():
    return datetime.now(timezone.utc).isoformat()


def seed_for(run, round_no, case, pair, phase):
    material = f"phase0:{run}:{round_no}:{case}:{pair}:{phase}".encode()
    return int.from_bytes(hashlib.sha256(material).digest()[:8], "big") % 2147483646 + 1


def schedule(run):
    """A/B then B/A, or B/A then A/B, in every round/case block."""
    plan = []
    for round_no in range(1, ROUNDS + 1):
        for case in CASES:
            first = seed_for(run, round_no, case, 0, "order") % 2
            orders = (("baseline", "protected"), ("protected", "baseline"))
            for pair, arms in enumerate((orders[first], orders[1-first]), 1):
                plan.append({"run": run, "round": round_no, "case": case,
                             "pair": pair, "arms": arms,
                             "warmup_seed": seed_for(run, round_no, case, pair, "warmup"),
                             "measured_seed": seed_for(run, round_no, case, pair, "measured")})
    return plan


def call(args, *, input_text=None, check=True, timeout=None):
    p = subprocess.run(args, cwd=ROOT, input=input_text, text=True,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       check=False, timeout=timeout)
    if check and p.returncode:
        raise RuntimeError(f"command {args} failed ({p.returncode}):\n{p.stdout}\n{p.stderr}")
    return p


def docker(*args, **kwargs):
    return call(["docker", *args], **kwargs)


def compose(*args):
    return docker("compose", "-p", PROJECT, "-f", str(HERE / "compose.yml"), *args)


def db(user, sql, *, stop=True, extra=()):
    return docker("exec", "-i", "-e", "PGPASSWORD=" + PASSWORDS[user],
                  CONTAINER, "psql", "-X", "-h", "127.0.0.1", "-U", user,
                  "-d", "commitcap_native", "-A", "-t", "-v",
                  "ON_ERROR_STOP=" + ("1" if stop else "0"), *extra,
                  input_text=sql)


def scalar(sql):
    return db(ADMIN, sql).stdout.strip()


def admin_state():
    """One new trusted-admin connection; no writer-session snapshot assumptions."""
    sql = """SELECT row_to_json(t) FROM (
    SELECT (SELECT count(*) FROM public.subscriptions) AS subscriptions_rows,
           (SELECT count(*) FROM public.subscriptions WHERE status <> 'baseline') AS subscriptions_changed,
           (SELECT count(*) FROM public.users) AS users_rows,
           (SELECT count(*) FROM public.users WHERE role <> 'member') AS users_changed,
           (SELECT count(*) FROM public.users WHERE role = 'admin') AS users_admin,
           (SELECT count(*) FROM public.refunds) AS refunds_rows,
           (SELECT count(*) FROM public.refunds WHERE amount <> 0.00) AS refunds_changed,
           (SELECT sum(amount) FROM public.refunds) AS refunds_total,
           (SELECT count(*) FROM public.unprotected_audit) AS audit_rows
    ) AS t;"""
    return json.loads(scalar(sql))


def postgres_observations():
    queries = {
        "database": "SELECT row_to_json(t) FROM (SELECT xact_commit, xact_rollback, blks_read, "
                    "blks_hit, temp_files, deadlocks, stats_reset FROM pg_stat_database "
                    "WHERE datname=current_database()) AS t;",
        "checkpoints_bgwriter": "SELECT row_to_json(t) FROM (SELECT * FROM pg_stat_bgwriter) AS t;",
        "wal": "SELECT row_to_json(t) FROM (SELECT * FROM pg_stat_wal) AS t;",
        "relation_maintenance": "SELECT coalesce(json_agg(row_to_json(t)), '[]'::json) FROM "
                                "(SELECT relname, n_tup_upd, n_dead_tup, vacuum_count, "
                                "autovacuum_count, analyze_count, autoanalyze_count "
                                "FROM pg_stat_user_tables WHERE relname IN "
                                "('subscriptions','users','refunds',"
                                "'baseline_subscriptions','baseline_users','baseline_refunds') "
                                "ORDER BY relname) AS t;",
        "settings": "SELECT coalesce(json_agg(row_to_json(t)), '[]'::json) FROM "
                    "(SELECT name, setting, unit FROM pg_settings WHERE name IN "
                    "('shared_buffers','max_wal_size','checkpoint_timeout','autovacuum',"
                    "'autovacuum_naptime','autovacuum_vacuum_scale_factor',"
                    "'autovacuum_analyze_scale_factor','synchronous_commit','fsync',"
                    "'full_page_writes','wal_level','track_io_timing') ORDER BY name) AS t;",
    }
    observed = {}
    for name, sql in queries.items():
        try:
            observed[name] = json.loads(scalar(sql))
        except (RuntimeError, ValueError) as exc:
            observed[name] = {"unavailable": str(exc)[:500]}
    return observed


def snapshot(outdir, run, round_no, phase, *, case=None, pair=None, arm=None):
    data = {"utc": utc(), "run": run, "round": round_no, "phase": phase,
            "case": case, "pair": pair, "arm": arm, "host": telemetry.host(),
            "containers": telemetry.container(CONTAINER),
            "postgres": postgres_observations()}
    with (outdir / "telemetry.jsonl").open("a") as f:
        f.write(json.dumps(data, sort_keys=True) + "\n")


def require(actual, expected, label):
    if str(actual) != str(expected):
        raise RuntimeError(f"{label}: observed {actual!r}, expected {expected!r}")


def install():
    for filename in (ROOT / "experiments/native_tx_state/setup.sql", HERE / "fixture.sql"):
        db(ADMIN, filename.read_text(), extra=("-f", "-"))
    require(scalar("SHOW server_version;"), "16.4", "PostgreSQL server version")
    require(scalar("SHOW transaction_isolation;"), "read committed", "isolation")
    require(scalar("SELECT count(*) FROM pg_trigger WHERE tgname IN "
                   "('subscriptions_update_budget','users_role_transition','refunds_positive_delta') AND tgenabled='O';"),
            3, "existing enabled native triggers")
    require(scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid IN "
                   "('public.baseline_subscriptions'::regclass,'public.baseline_users'::regclass,"
                   "'public.baseline_refunds'::regclass) AND NOT tgisinternal;"), 0, "baseline triggers")
    for protected, bare, column in (("subscriptions", "baseline_subscriptions", "status"),
                                    ("users", "baseline_users", "role"), ("refunds", "baseline_refunds", "amount")):
        # Compare SQL-visible columns and index definitions after removing table names.
        require(scalar(f"SELECT string_agg(attname||':'||format_type(atttypid,atttypmod)||':'||attnotnull,',' ORDER BY attnum) "
                       f"FROM pg_attribute WHERE attrelid='public.{protected}'::regclass AND attnum>0 AND NOT attisdropped;"),
                scalar(f"SELECT string_agg(attname||':'||format_type(atttypid,atttypmod)||':'||attnotnull,',' ORDER BY attnum) "
                       f"FROM pg_attribute WHERE attrelid='public.{bare}'::regclass AND attnum>0 AND NOT attisdropped;"),
                f"{protected} columns")
        for table in (protected, bare):
            require(scalar(f"SELECT count(*) FROM pg_index WHERE indrelid='public.{table}'::regclass "
                           "AND indisprimary AND indkey::text='1';"), 1, f"{table} primary index")
            require(scalar(f"SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='public.{table}'::regclass;"),
                    "commitcap_owner", f"{table} owner")
            require(scalar(f"SELECT has_column_privilege('{WRITER}','public.{table}','id','SELECT') AND "
                           f"has_column_privilege('{WRITER}','public.{table}','{column}','UPDATE') AND "
                           f"NOT has_table_privilege('{WRITER}','public.{table}','INSERT') AND "
                           f"NOT has_table_privilege('{WRITER}','public.{table}','DELETE');"), "t", f"{table} grants")
    require(db(WRITER, "SHOW commitcap_native.test_budget;").stdout.strip(), 100, "trusted role budget")
    require(db(WRITER, "SHOW commitcap_native.test_numeric_budget;").stdout.strip(), "100.00", "numeric budget")
    # Explicitly verify per-statement affected rows, not just the assumed range shape.
    reset("protected")
    for n in (1, 5, 100):
        p = db(WRITER, "", extra=("-c", f"UPDATE public.subscriptions SET status='bench' WHERE id BETWEEN 100 AND {99+n};"))
        require(p.stdout.strip(), f"UPDATE {n}", f"protected {n}-row tag")
    for arm in ("protected", "baseline"):
        p = "" if arm == "protected" else "baseline_"
        if arm == "baseline":
            reset(arm)
        for sql, count in ((f"UPDATE public.{p}users SET role='moderator' WHERE id BETWEEN 1 AND 2", 2),
                           (f"UPDATE public.{p}refunds SET amount=amount+0.01 WHERE id BETWEEN 1 AND 2", 2)):
            result = db(WRITER, "", extra=("-c", sql)).stdout.strip()
            require(result, f"UPDATE {count}", f"{arm} affected-row probe")
        require(scalar(f"SELECT count(*) FROM public.{p}users WHERE role='moderator';"), 2,
                f"{arm} role probe durable")
        require(scalar(f"SELECT sum(amount) FROM public.{p}refunds;"), "0.02",
                f"{arm} numeric probe durable")
    reset("protected")


def reset(arm):
    prefix = "" if arm == "protected" else "baseline_"
    sql = f"""TRUNCATE public.{prefix}subscriptions, public.{prefix}users, public.{prefix}refunds;
INSERT INTO public.{prefix}subscriptions SELECT id, 'baseline' FROM generate_series(1,{ROWS}) AS id;
INSERT INTO public.{prefix}users SELECT id, 10, 'member' FROM generate_series(1,{ROWS}) AS id;
INSERT INTO public.{prefix}refunds SELECT id, 10, 0.00 FROM generate_series(1,{ROWS}) AS id;
ANALYZE public.{prefix}subscriptions; ANALYZE public.{prefix}users; ANALYZE public.{prefix}refunds;
"""
    db(ADMIN, sql)


def script(arm, case):
    p = "" if arm == "protected" else "baseline_"
    if case == "users":
        return f"""\\set id random(1,{ROWS})
BEGIN;
UPDATE public.{p}users SET role='moderator' WHERE id=:id;
COMMIT;
""", 1
    if case == "refunds":
        return f"""\\set id random(1,{ROWS})
BEGIN;
UPDATE public.{p}refunds SET amount=amount+0.01 WHERE id=:id;
COMMIT;
""", 1
    if case == "mixed":
        return f"""\\set sid random(1,{ROWS-2})
\\set uid random(1,{ROWS-1})
\\set rid random(1,{ROWS-1})
BEGIN;
UPDATE public.{p}subscriptions SET status='bench' WHERE id BETWEEN :sid AND :sid+2;
UPDATE public.{p}users SET role='moderator' WHERE id BETWEEN :uid AND :uid+1;
UPDATE public.{p}refunds SET amount=amount+0.01 WHERE id BETWEEN :rid AND :rid+1;
COMMIT;
""", 7
    n = int(case)
    return f"""\\set id random(1,{ROWS-n+1})
BEGIN;
UPDATE public.{p}subscriptions SET status='bench' WHERE id BETWEEN :id AND :id+{n-1};
COMMIT;
""", n


def bench(arm, case, round_no, pair, phase, count, seed, outdir):
    text, per_tx = script(arm, case)
    local_script = outdir / "workload.sql"
    local_script.write_text(text)
    docker("cp", str(local_script), f"{CONTAINER}:/tmp/workload.sql")
    name = f"cc_{round_no}_{pair}_{arm}_{case}_{phase}"
    argv = ["exec", "-e", "PGPASSWORD=" + PASSWORDS[WRITER], CONTAINER,
            "pgbench", "-h", "127.0.0.1", "-U", WRITER, "-d", "commitcap_native",
            "-n", "-M", "prepared", "-c", "1", "-j", "1", "-t", str(count),
            "--random-seed=" + str(seed),
            "-f", "/tmp/workload.sql"]
    if phase == "measured":
        argv += ["-l", "--log-prefix=/tmp/" + name]
    result = docker(*argv)
    elapsed = re.search(r"^latency average = ([\d.]+) ms$", result.stdout, re.M)
    tps = re.search(r"^tps = ([\d.]+) \(without initial connection time\)$", result.stdout, re.M)
    require(bool(elapsed and tps), True, f"pgbench summary {name}: {result.stdout}")
    rows = []
    if phase == "measured":
        listing = docker("exec", CONTAINER, "sh", "-c", f"ls /tmp/{name}.*").stdout.strip().splitlines()
        require(len(listing), 1, f"one pgbench log for {name}")
        log = outdir / "pgbench.raw"
        docker("cp", f"{CONTAINER}:{listing[0]}", str(log))
        for line in log.read_text().splitlines():
            if line.startswith("#") or not line.strip():
                continue
            parts = line.split()
            require(len(parts) >= 5, True, f"pgbench log shape: {line}")
            rows.append(float(parts[2]))
        require(len(rows), count, f"pgbench completed transaction count {name}")
        log.unlink()
        docker("exec", CONTAINER, "rm", listing[0])
    local_script.unlink()
    value = float(tps.group(1))
    return rows, per_tx, value, count / value


def durable(arm, case, transactions):
    p = "" if arm == "protected" else "baseline_"
    require(scalar(f"SELECT count(*) FROM public.{p}subscriptions;"), ROWS, "subscription row count")
    require(scalar(f"SELECT count(*) FROM public.{p}subscriptions WHERE status NOT IN ('baseline','bench');"),
            0, f"{arm} {case} subscription values")
    if case not in ("users", "refunds"):
        if int(scalar(f"SELECT count(*) FROM public.{p}subscriptions WHERE status='bench';")) == 0:
            raise RuntimeError(f"{arm} {case}: no durable subscription changes")
    require(scalar(f"SELECT count(*) FROM public.{p}users WHERE role NOT IN ('member','moderator');"),
            0, "allowed role values")
    if case in ("users", "mixed"):
        if int(scalar(f"SELECT count(*) FROM public.{p}users WHERE role='moderator';")) == 0:
            raise RuntimeError(f"{arm} users: no durable allowed transitions")
    require(scalar(f"SELECT count(*) FROM public.{p}refunds;"), ROWS, "refund row count")
    require(scalar(f"SELECT sum(amount) FROM public.{p}refunds;"),
            f"{transactions*2/100:.2f}" if case == "mixed" else
            (f"{transactions/100:.2f}" if case == "refunds" else "0.00"),
            f"{arm} {case} durable numeric effects")


class DurableAuthorityFailure(RuntimeError):
    """A denied writer committed protected or sibling state: stop immediately."""


def denial_attempt(text, marker):
    """Return one complete combined psql transcript, or timed-out partial output."""
    args = ["docker", "exec", "-i", "-e", "PGPASSWORD=" + PASSWORDS[WRITER],
            CONTAINER, "psql", "-X", "-h", "127.0.0.1", "-U", WRITER,
            "-d", "commitcap_native", "-A", "-t", "-v", "ON_ERROR_STOP=0"]
    try:
        p = subprocess.run(args, cwd=ROOT, input=text, text=True,
                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                           timeout=30, check=False)
        output, exit_code = p.stdout, p.returncode
    except subprocess.TimeoutExpired as exc:
        partial = exc.stdout or b""
        output = (partial.decode(errors="replace") if isinstance(partial, bytes) else partial)
        output += "\n[writer timeout]\n"
        exit_code = None
    except OSError as exc:
        output = f"[writer process failed before producing output: {exc}]\n"
        exit_code = None
    return output, marker in output.splitlines(), exit_code


def denied_case(case, outdir, run):
    if case == "row":
        # Set 100-row test budget above; denial on event 101 within the transaction.
        sql = "UPDATE public.subscriptions SET status='bench' WHERE id BETWEEN 1 AND 101;"
        expected = "CommitCap mutation budget exceeded"
    elif case == "transition":
        sql = "UPDATE public.users SET role='admin' WHERE id=1;"
        expected = "CommitCap forbidden state transition"
    else:
        sql = "UPDATE public.refunds SET amount=101.00 WHERE id=1;"
        expected = "CommitCap numeric delta budget exceeded"
    directory = outdir / "denials" / case
    directory.mkdir(parents=True, exist_ok=True)
    results = []
    for trial in range(1, DENIED + 1):
        marker = f"END_{case}_{trial}"
        text = (f"\\timing on\n\\set VERBOSITY verbose\n"
                f"\\echo START_{case}_{trial}\nBEGIN;\nSAVEPOINT deny;\n{sql}\n"
                f"\\echo AFTER_UPDATE_{case}_{trial} :SQLSTATE\n"
                f"ROLLBACK TO SAVEPOINT deny;\nCOMMIT;\n"
                f"\\echo AFTER_COMMIT_{case}_{trial} :SQLSTATE\n\\echo {marker}\n")
        started = utc()
        output, completed, exit_code = denial_attempt(text, marker)
        finished = utc()
        update = re.findall(rf"^AFTER_UPDATE_{case}_{trial} (\S+)$", output, re.M)
        commit = re.findall(rf"^AFTER_COMMIT_{case}_{trial} (\S+)$", output, re.M)
        timings = [float(m.group(1))*1000 for line in output.splitlines()
                   if (m := re.match(r"Time: ([\d.]+) ms", line))]
        try:
            state = admin_state()
            state_error = None
        except (RuntimeError, ValueError) as exc:
            state = None
            state_error = str(exc)[:1000]
        try:
            log = docker("logs", "--since", started, "--until", finished,
                         "--timestamps", CONTAINER, check=False, timeout=15)
        except (OSError, subprocess.TimeoutExpired) as exc:
            log = subprocess.CompletedProcess([], -1, "", f"[server log unavailable: {exc}]")
        durable_failure = state is not None and (
            state["subscriptions_rows"] != ROWS or state["subscriptions_changed"] != 0 or
            state["users_rows"] != ROWS or state["users_changed"] != 0 or
            state["refunds_rows"] != ROWS or state["refunds_changed"] != 0 or
            str(state.get("refunds_total")) not in ("0", "0.0", "0.00") or
            state["audit_rows"] != 0)
        match = (completed and expected in output and update == ["54000"] and
                 commit == ["54000"] and len(timings) == 5 and
                 exit_code == 0 and state_error is None and not durable_failure)
        record = {"run": run, "case": case, "trial": trial, "started_utc": started,
                  "finished_utc": finished, "exact_sql": sql, "psql_input": text,
                  "combined_stdout_stderr": output, "writer_end_marker_observed": completed,
                  "writer_process_exit_code": exit_code,
                  "expected": {"message": expected, "update_sqlstate": "54000",
                               "commit_sqlstate": "54000", "timing_lines": 5,
                               "durable_protected_changes": 0},
                  "observed": {"message_match": expected in output,
                               "update_sqlstates": update, "commit_sqlstates": commit,
                               "timings_us": timings, "fresh_admin_state": state,
                               "fresh_admin_error": state_error,
                               "server_log_since_started_utc": log.stdout + log.stderr,
                               "server_log_exit_code": log.returncode,
                               "durable_authority_failure": durable_failure},
                  "all_expectations_met": match}
        path = directory / f"trial-{trial:03}.json"
        path.write_text(json.dumps(record, indent=2) + "\n")
        if durable_failure:
            raise DurableAuthorityFailure(
                f"DURABLE OVER-AUTHORITY WRITE: stop; fresh-admin state in {path}")
        if not match:
            raise RuntimeError(f"{case} denial trial {trial}: first anomaly saved in {path}; "
                               "stop without retry or further benchmark work")
        results.append((timings[2], timings[4]))
    return results


def pct(values, percent):
    values = sorted(values)
    return values[math.ceil(len(values)*percent/100)-1]


def summarize(raw):
    cells = []
    for case in CASES:
        per_arm = {}
        for arm in ("baseline", "protected"):
            entries = [x for x in raw if x["kind"] == "accepted" and x["phase"] == "measured" and x["arm"] == arm and x["case"] == case]
            latencies = [float(x["latency_us"]) for x in entries]
            runs = []
            for r in range(1, ROUNDS+1):
                one = [x for x in entries if x["round"] == r]
                # Pool elapsed time, not the first of the two pair TPS values.
                elapsed = sum(float(next(x for x in one if x["pair"] == pair)["elapsed_s"])
                              for pair in range(1, PAIRS + 1))
                throughput = len(one) / elapsed
                runs.append({"round": r, "p50_us": pct([float(x["latency_us"]) for x in one], 50),
                             "p95_us": pct([float(x["latency_us"]) for x in one], 95),
                             "p99_us": pct([float(x["latency_us"]) for x in one], 99),
                             "tps": throughput, "rows_per_sec": throughput*int(one[0]["rows_per_tx"])})
            per_arm[arm] = {"transactions": len(entries), "p50_us": pct(latencies, 50),
                            "p95_us": pct(latencies, 95), "p99_us": pct(latencies, 99),
                            "mean_tps": statistics.mean(x["tps"] for x in runs),
                            "tps_sample_sd": statistics.stdev(x["tps"] for x in runs),
                            "mean_rows_per_sec": statistics.mean(x["rows_per_sec"] for x in runs),
                            "rounds": runs}
        b, p = per_arm["baseline"], per_arm["protected"]
        blocks = []
        for round_no in range(1, ROUNDS + 1):
            for pair in range(1, PAIRS + 1):
                block = [x for x in raw if x["kind"] == "accepted" and x["case"] == case
                         and x["round"] == round_no and x["pair"] == pair]
                rates = {arm: len([x for x in block if x["arm"] == arm]) /
                         float(next(x for x in block if x["arm"] == arm)["elapsed_s"])
                         for arm in ("baseline", "protected")}
                blocks.append({"round": round_no, "pair": pair,
                               "order": next(x for x in block if x["order"] == 1)["arm"] + "_first",
                               "baseline_tps": rates["baseline"], "protected_tps": rates["protected"],
                               "protected_tps_change_pct": 100*(rates["protected"]/rates["baseline"]-1)})
        baseline_pairs = [block["baseline_tps"] for block in blocks]
        changes = [block["protected_tps_change_pct"] for block in blocks]
        baseline_rounds = [one["tps"] for one in b["rounds"]]
        cv = statistics.stdev(baseline_rounds) / statistics.mean(baseline_rounds)
        warnings = []
        if max(baseline_pairs) / min(baseline_pairs) - 1 > BASELINE_PAIR_SPREAD_WARNING:
            warnings.append("baseline pair TPS max/min spread > 20%")
        if cv > BASELINE_ROUND_CV_WARNING:
            warnings.append("baseline round TPS sample CV > 10%")
        if min(changes) < 0 < max(changes) and max(changes) - min(changes) > PAIRED_CHANGE_SPREAD_WARNING_PP:
            warnings.append("paired overhead reverses sign with > 20 percentage-point spread")
        cells.append({"case": case, "baseline": b, "protected": p,
                      "protected_p50_latency_overhead_pct": 100*(p["p50_us"]/b["p50_us"]-1),
                      "protected_mean_tps_change_pct": 100*(p["mean_tps"]/b["mean_tps"]-1),
                      "paired_round_tps_change_pct": [100*(p["rounds"][i]["tps"]/b["rounds"][i]["tps"]-1) for i in range(ROUNDS)],
                      "paired_blocks": blocks, "baseline_round_tps_cv": cv,
                      "reproducibility_warnings": warnings})
    denied = {}
    for case in ("row", "transition", "numeric"):
        entries = [x for x in raw if x["kind"] == "denied" and x["case"] == case]
        denied[case] = {field: {"p50_us": pct([float(x[field]) for x in entries], 50),
                                "p95_us": pct([float(x[field]) for x in entries], 95),
                                "p99_us": pct([float(x[field]) for x in entries], 99)}
                        for field in ("latency_us", "commit_us")}
    return {"accepted": cells, "denied_protected_only": denied,
            "interpretation_warning": any(cell["reproducibility_warnings"] for cell in cells),
            "percentile_method": "nearest rank across all measured transactions; per-round values retained",
            "throughput_method": "per-pair pgbench TPS without initial connection; per-round 200 transactions / sum of both pair elapsed times; arithmetic mean of rounds and sample SD"}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-id", type=int, choices=(1, 2), required=True,
                        help="one of the two predeclared independent runs")
    parser.add_argument("--output", type=Path, help="new output directory outside checkout")
    parser.add_argument("--plan-only", action="store_true", help="print order/seeds; do not use Docker")
    a = parser.parse_args()
    plan = schedule(a.run_id)
    method = {"planned_runs": RUNS, "rounds_per_run": ROUNDS, "pairs_per_case_per_round": PAIRS,
              "warmup_tx_per_pair_arm": WARMUP // PAIRS,
              "measured_tx_per_pair_arm": TX // PAIRS,
              "denied_trials_per_case": DENIED,
              "baseline_pair_tps_max_min_spread_warning": BASELINE_PAIR_SPREAD_WARNING,
              "baseline_round_tps_sample_cv_warning": BASELINE_ROUND_CV_WARNING,
              "sign_reversing_paired_change_span_warning_percentage_points": PAIRED_CHANGE_SPREAD_WARNING_PP,
              "threshold_scope": "measurement reproducibility warning; NOT product PASS or safety threshold"}
    if a.plan_only:
        print(json.dumps({"method": method, "schedule": plan}, indent=2))
        return
    if a.output is None:
        parser.error("--output is required unless --plan-only is specified")
    require(os.environ.get("COMPOSE_PROJECT_NAME"), PROJECT, "COMPOSE_PROJECT_NAME")
    require(call(["git", "status", "--porcelain"]).stdout, "", "clean checkout")
    commit = call(["git", "rev-parse", "HEAD"]).stdout.strip()
    output = a.output.resolve()
    if output == ROOT or ROOT in output.parents or output.exists():
        raise RuntimeError("--output must be a new directory outside the checkout")
    output.mkdir(parents=True)
    global CONTAINER
    meta = None
    try:
        compose("up", "-d", "--build", "--wait")
        CONTAINER = compose("ps", "-q", "postgres").stdout.strip()
        if not CONTAINER:
            raise RuntimeError("Compose postgres container missing")
        digests = json.loads(docker("image", "inspect", "postgres:16.4-alpine@" + DIGEST,
                                   "--format", "{{json .RepoDigests}}").stdout)
        require("postgres@" + DIGEST in digests, True, "pinned upstream digest")
        install()
        host_config = json.loads(docker("inspect", CONTAINER, "--format", "{{json .HostConfig}}").stdout)
        limits = {key: host_config.get(key) for key in
                  ("NanoCpus", "CpuQuota", "CpuPeriod", "CpusetCpus", "Memory", "MemorySwap", "PidsLimit")}
        meta = {"tested_commit": commit, "status": "incomplete", "run": a.run_id,
                "method": method, "schedule": plan, "base_digest": DIGEST,
                "postgres_version": scalar("SHOW server_version;"),
                "pgbench_version": docker("exec", CONTAINER, "pgbench", "--version").stdout.strip(),
                "compose_project": PROJECT, "container_resource_limits": limits,
                "docker_engine": docker("version", "--format", "{{.Server.Version}}").stdout.strip(),
                "host_uname": call(["uname", "-a"]).stdout.strip(),
                "host_cpu_count": os.cpu_count(),
                "host_meminfo": Path("/proc/meminfo").read_text().splitlines()[:3],
                "host_cpu_model": next((s for s in Path("/proc/cpuinfo").read_text().splitlines() if s.startswith("model name")), "unknown"),
                "container_resources": docker("exec", CONTAINER, "sh", "-c", "for f in /sys/fs/cgroup/cpu.max /sys/fs/cgroup/memory.max /sys/fs/cgroup/cpuset.cpus.effective; do test ! -r \"$f\" || { echo \"$f\"; sed -n '1p' \"$f\"; }; done").stdout.strip(),
                "rows_per_table": ROWS, "warmup_tx_per_round_arm": WARMUP,
                "measured_tx_per_round_arm": TX, "rounds": ROUNDS,
                "denied_trials_per_case": DENIED, "clients": 1, "jobs": 1,
                "query_mode": "prepared", "isolation": "read committed",
                "fixture_source": "experiments/native_tx_state/setup.sql (verbatim) + benchmarks/phase0/fixture.sql",
                "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
        (output / "metadata.json").write_text(json.dumps(meta, indent=2) + "\n")
        with (output / "raw.csv").open("w", newline="") as f:
            csv.DictWriter(f, FIELDS).writeheader()
        for r in range(1, ROUNDS + 1):
            snapshot(output, a.run_id, r, "round_start")
            for block in (item for item in plan if item["round"] == r):
                case, pair = block["case"], block["pair"]
                for position, arm in enumerate(block["arms"], 1):
                    reset(arm)
                    bench(arm, case, r, pair, "warmup", WARMUP // PAIRS,
                          block["warmup_seed"], output)
                    snapshot(output, a.run_id, r, "before_measured", case=case, pair=pair, arm=arm)
                    latencies, per_tx, tps, elapsed = bench(
                        arm, case, r, pair, "measured", TX // PAIRS,
                        block["measured_seed"], output)
                    snapshot(output, a.run_id, r, "after_measured", case=case, pair=pair, arm=arm)
                    durable(arm, case, (WARMUP + TX) // PAIRS)
                    rows = [{"kind": "accepted", "run": a.run_id, "round": r, "pair": pair,
                             "order": position, "arm": arm, "phase": "measured", "case": case,
                             "trial": i, "latency_us": latency, "rows_per_tx": per_tx,
                             "tps": tps, "elapsed_s": elapsed, "durable_check": "state_verified"}
                            for i, latency in enumerate(latencies, 1)]
                    with (output / "raw.csv").open("a", newline="") as f:
                        csv.DictWriter(f, FIELDS).writerows(rows)
                    print(f"run {a.run_id} round {r} pair {pair} {case} {arm}: "
                          f"{len(rows)} accepted; {tps:.2f} TPS; durable state verified", flush=True)
            snapshot(output, a.run_id, r, "round_end")
        reset("protected")
        snapshot(output, a.run_id, ROUNDS, "denied_start")
        for case in ("row", "transition", "numeric"):
            rows = [{"kind": "denied", "run": a.run_id, "round": "", "pair": "", "order": "",
                     "arm": "protected", "phase": "measured", "case": case, "trial": i,
                     "latency_us": update_us, "commit_us": commit_us, "durable_check": "verified"}
                    for i, (update_us, commit_us) in enumerate(denied_case(case, output, a.run_id), 1)]
            with (output / "raw.csv").open("a", newline="") as f:
                csv.DictWriter(f, FIELDS).writerows(rows)
            print(f"denied {case}: {DENIED} trials, SQLSTATE and fresh-admin baseline verified", flush=True)
        snapshot(output, a.run_id, ROUNDS, "denied_end")
        with (output / "raw.csv").open(newline="") as f:
            raw = list(csv.DictReader(f))
        # CSV round/pair fields are text; summary needs numeric grouping.
        for row in raw:
            if row["kind"] == "accepted":
                row["round"] = int(row["round"])
                row["pair"] = int(row["pair"])
                row["order"] = int(row["order"])
        result = summarize(raw)
        meta["finished_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        meta["status"] = "complete_with_reproducibility_warning" if result["interpretation_warning"] else "complete_without_triggered_warnings"
        (output / "summary.json").write_text(json.dumps(result, indent=2) + "\n")
        (output / "metadata.json").write_text(json.dumps(meta, indent=2) + "\n")
        print(f"complete at {commit}: {output}")
    except BaseException as exc:
        (output / "failure.json").write_text(json.dumps(
            {"utc": utc(), "implementation_sha": commit,
             "failure_type": type(exc).__name__, "message": str(exc)}, indent=2) + "\n")
        if meta is not None:
            meta["status"] = "interrupted"
            meta["failure_utc"] = utc()
            (output / "metadata.json").write_text(json.dumps(meta, indent=2) + "\n")
        raise
    finally:
        compose("down", "-v", "--remove-orphans")


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, ValueError) as exc:
        sys.exit(f"benchmark FAILED: {exc}")
