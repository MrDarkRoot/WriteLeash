#!/usr/bin/env python3
"""Disposable PG16.4 Phase 0 benchmark; see README.md for measurement scope."""

import argparse
import csv
import json
import math
import os
from pathlib import Path
import re
import statistics
import subprocess
import sys
import time


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
DENIED = 30
FIELDS = ["kind", "round", "arm", "phase", "case", "trial", "latency_us", "commit_us", "rows_per_tx", "tps", "durable_check"]


def call(args, *, input_text=None, check=True):
    p = subprocess.run(args, cwd=ROOT, input=input_text, text=True,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
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


def bench(arm, case, round_no, phase, count, outdir):
    text, per_tx = script(arm, case)
    local_script = outdir / "workload.sql"
    local_script.write_text(text)
    docker("cp", str(local_script), f"{CONTAINER}:/tmp/workload.sql")
    name = f"cc_{round_no}_{arm}_{case}_{phase}"
    argv = ["exec", "-e", "PGPASSWORD=" + PASSWORDS[WRITER], CONTAINER,
            "pgbench", "-h", "127.0.0.1", "-U", WRITER, "-d", "commitcap_native",
            "-n", "-M", "prepared", "-c", "1", "-j", "1", "-t", str(count),
            "--random-seed=20260923", "-f", "/tmp/workload.sql"]
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
    return rows, per_tx, float(tps.group(1))


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


def denied_case(case):
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
    input_text = "\\timing on\n\\set VERBOSITY verbose\n"
    for i in range(DENIED):
        input_text += f"\\echo START_{i}\nBEGIN;\nSAVEPOINT deny;\n{sql}\n\\echo AFTER_UPDATE_{i} :SQLSTATE\nROLLBACK TO SAVEPOINT deny;\nCOMMIT;\n\\echo AFTER_COMMIT_{i} :SQLSTATE\n"
    # Merge stdout/stderr to preserve timing and error order for each trial.
    p = subprocess.run(["docker", "exec", "-i", "-e", "PGPASSWORD=" + PASSWORDS[WRITER],
                        CONTAINER, "psql", "-X", "-h", "127.0.0.1", "-U", WRITER,
                        "-d", "commitcap_native", "-A", "-t", "-v", "ON_ERROR_STOP=0"],
                       cwd=ROOT, input=input_text, text=True, stdout=subprocess.PIPE,
                       stderr=subprocess.STDOUT, check=True)
    lines = p.stdout.splitlines()
    results = []
    for i in range(DENIED):
        start = lines.index(f"START_{i}")
        end = lines.index(f"START_{i+1}") if i+1 < DENIED else len(lines)
        part = lines[start:end]
        require(any(expected in x for x in part), True, f"{case} denial message trial {i}")
        require(any(x == f"AFTER_UPDATE_{i} 54000" for x in part), True, f"{case} UPDATE SQLSTATE trial {i}")
        require(any(x == f"AFTER_COMMIT_{i} 54000" for x in part), True, f"{case} sticky COMMIT SQLSTATE trial {i}")
        timings = [float(x.group(1))*1000 for line in part if (x := re.match(r"Time: ([\d.]+) ms", line))]
        # BEGIN, SAVEPOINT, UPDATE, ROLLBACK TO, COMMIT each have a psql timing.
        require(len(timings), 5, f"{case} timings trial {i}: {part}")
        results.append((timings[2], timings[4]))
    for table, column, value in (("subscriptions", "status", "baseline"),
                                 ("users", "role", "member"), ("refunds", "amount", "0.00")):
        require(scalar(f"SELECT count(*) FROM public.{table} WHERE {column} <> '{value}';"),
                0, f"{case} denied durable {table}")
    require(scalar("SELECT count(*) FROM public.unprotected_audit;"), 0, "denied audit baseline")
    return results


def pct(values, percent):
    values = sorted(values)
    return values[math.ceil(len(values)*percent/100)-1]


def summarize(raw):
    cells = []
    for case in ("1", "5", "100", "users", "refunds", "mixed"):
        per_arm = {}
        for arm in ("baseline", "protected"):
            entries = [x for x in raw if x["kind"] == "accepted" and x["phase"] == "measured" and x["arm"] == arm and x["case"] == case]
            latencies = [float(x["latency_us"]) for x in entries]
            runs = []
            for r in range(1, ROUNDS+1):
                one = [x for x in entries if x["round"] == r]
                throughput = float(one[0]["tps"])
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
        cells.append({"case": case, "baseline": b, "protected": p,
                      "protected_p50_latency_overhead_pct": 100*(p["p50_us"]/b["p50_us"]-1),
                      "protected_mean_tps_change_pct": 100*(p["mean_tps"]/b["mean_tps"]-1),
                      "paired_round_tps_change_pct": [100*(p["rounds"][i]["tps"]/b["rounds"][i]["tps"]-1) for i in range(ROUNDS)]})
    denied = {}
    for case in ("row", "transition", "numeric"):
        entries = [x for x in raw if x["kind"] == "denied" and x["case"] == case]
        denied[case] = {field: {"p50_us": pct([float(x[field]) for x in entries], 50),
                                "p95_us": pct([float(x[field]) for x in entries], 95),
                                "p99_us": pct([float(x[field]) for x in entries], 99)}
                        for field in ("latency_us", "commit_us")}
    return {"accepted": cells, "denied_protected_only": denied,
            "percentile_method": "nearest rank across all measured transactions; per-round values retained",
            "throughput_method": "pgbench TPS without initial connection; per-round arithmetic mean and sample SD"}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True, help="new output directory outside checkout")
    a = parser.parse_args()
    require(os.environ.get("COMPOSE_PROJECT_NAME"), PROJECT, "COMPOSE_PROJECT_NAME")
    require(call(["git", "status", "--porcelain"]).stdout, "", "clean checkout")
    commit = call(["git", "rev-parse", "HEAD"]).stdout.strip()
    output = a.output.resolve()
    if output == ROOT or ROOT in output.parents or output.exists():
        raise RuntimeError("--output must be a new directory outside the checkout")
    output.mkdir(parents=True)
    global CONTAINER
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
        meta = {"tested_commit": commit, "base_digest": DIGEST,
                "postgres_version": scalar("SHOW server_version;"),
                "pgbench_version": docker("exec", CONTAINER, "pgbench", "--version").stdout.strip(),
                "compose_project": PROJECT, "container_resource_limits": limits,
                "docker_engine": docker("version", "--format", "{{.Server.Version}}").stdout.strip(),
                "host_uname": call(["uname", "-a"]).stdout.strip(),
                "host_cpu_count": os.cpu_count(),
                "host_meminfo": Path("/proc/meminfo").read_text().splitlines()[:3],
                "host_cpu_model": next((s for s in Path("/proc/cpuinfo").read_text().splitlines() if s.startswith("model name")), "unknown"),
                "container_resources": docker("exec", CONTAINER, "sh", "-c", "for f in /sys/fs/cgroup/cpu.max /sys/fs/cgroup/memory.max /sys/fs/cgroup/cpuset.cpus.effective; do test ! -r \"$f\" || { echo \"$f\"; sed -n '1p' \"$f\"; }; done").stdout.strip(),
                "rows_per_table": ROWS, "warmup_tx_per_cell": WARMUP,
                "measured_tx_per_cell": TX, "rounds": ROUNDS,
                "denied_trials_per_case": DENIED, "clients": 1, "jobs": 1,
                "query_mode": "prepared", "seed": 20260923, "isolation": "read committed",
                "fixture_source": "experiments/native_tx_state/setup.sql (verbatim) + benchmarks/phase0/fixture.sql",
                "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
        raw = []
        for r in range(1, ROUNDS+1):
            for case in ("1", "5", "100", "users", "refunds", "mixed"):
                arms = ("baseline", "protected") if r % 2 else ("protected", "baseline")
                for arm in arms:
                    reset(arm)
                    for phase, count in (("warmup", WARMUP), ("measured", TX)):
                        latencies, per_tx, tps = bench(arm, case, r, phase, count, output)
                        if phase == "measured":
                            for i, latency in enumerate(latencies, 1):
                                raw.append({"kind": "accepted", "round": r, "arm": arm, "phase": phase,
                                            "case": case, "trial": i, "latency_us": latency,
                                            "rows_per_tx": per_tx, "tps": tps, "durable_check": "state_verified"})
                    durable(arm, case, WARMUP+TX)
                    print(f"round {r} {case} {arm}: {TX} accepted; {tps:.2f} TPS; durable effects verified", flush=True)
        reset("protected")
        for case in ("row", "transition", "numeric"):
            for i, (update_us, commit_us) in enumerate(denied_case(case), 1):
                raw.append({"kind": "denied", "round": "", "arm": "protected", "phase": "measured",
                            "case": case, "trial": i, "latency_us": update_us, "commit_us": commit_us,
                            "durable_check": "verified"})
            print(f"denied {case}: {DENIED} trials, SQLSTATE and fresh-admin baseline verified", flush=True)
        meta["finished_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        with (output / "raw.csv").open("w", newline="") as f:
            w = csv.DictWriter(f, FIELDS)
            w.writeheader()
            w.writerows(raw)
        (output / "summary.json").write_text(json.dumps(summarize(raw), indent=2) + "\n")
        (output / "metadata.json").write_text(json.dumps(meta, indent=2) + "\n")
        print(f"complete at {commit}: {output}")
    finally:
        compose("down", "-v", "--remove-orphans")


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, ValueError) as exc:
        sys.exit(f"benchmark FAILED: {exc}")
