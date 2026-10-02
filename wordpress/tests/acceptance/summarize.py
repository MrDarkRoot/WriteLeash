#!/usr/bin/env python3
"""Summarize preserved acceptance artifacts without inventing missing metrics."""
import json
import math
import pathlib
import sys


def quantiles(values):
    values = sorted(values)
    if not values:
        return None
    return {f"p{p}": values[math.ceil(len(values) * p / 100) - 1]
            for p in (50, 95, 99)}


def allocated(snapshot, needle=None):
    if snapshot is None:
        return None
    return sum(int(row["DATA_LENGTH"] or 0) + int(row["INDEX_LENGTH"] or 0)
               for row in snapshot if needle is None or needle in row["TABLE_NAME"])


def summarize(path):
    data = json.loads(path.read_text())
    engine = path.name.split("-")[0]
    requests_path = path.parent / f"{engine}-requests.jsonl"
    requests = ([json.loads(line) for line in requests_path.read_text().splitlines()]
                if requests_path.exists() else [])
    requests = requests[data.get("request_start_line", 0):data.get("request_end_line", 0)]
    row = {key: data.get(key) for key in (
        "git_sha", "wp", "woo", "php", "db", "cache", "catalog_size", "fixture_product_count",
        "actual_job_size", "requested_job_size", "outcome", "error",
        "classification_reason", "fixture_seconds", "fixture_queries",
        "fixture_peak_php_bytes", "plan_post_seconds", "first_plan_journey_seconds",
        "preview_first_page_seconds", "approval_seconds", "execution_seconds",
        "items_per_second_including_progress_http", "manual_resume_first_chunk_seconds",
        "scheduler_lag_lower_bound_seconds", "approval_durable_state", "undo_seconds",
        "final_counts", "cache_lookup_journal_parity",
        "exactly_one_apply_save_per_frozen_product")}
    row["preview_later_quantiles_seconds"] = quantiles(data.get("preview_later_page_seconds", []))
    row["apply_batch_quantiles_seconds"] = quantiles(data.get("apply_batch_seconds", []))
    row["undo_batch_quantiles_seconds"] = quantiles(data.get("undo_batch_seconds", []))
    row["peak_admin_php_bytes"] = max((r["peak_php_bytes"] for r in requests), default=None)
    row["admin_queries_after_mu_plugin_load"] = (sum(r["queries_all_wpdb_connections"] for r in requests)
                                                  if requests else None)
    row["php_request_limits"] = sorted({(r["memory_limit"], r["max_execution_time"]) for r in requests})
    for stage in ("fixture", "apply", "undo"):
        snap = data.get(f"db_after_{stage}")
        row[f"db_allocated_bytes_after_{stage}"] = allocated(snap)
        row[f"journal_allocated_bytes_after_{stage}"] = allocated(snap, "writeleash_price_items")
        row[f"job_tables_allocated_bytes_after_{stage}"] = allocated(snap, "writeleash_job")
        row[f"undo_tables_allocated_bytes_after_{stage}"] = allocated(snap, "writeleash_undo")
    for stage in ("plan", "apply", "undo"):
        row[f"evidence_payload_after_{stage}"] = data.get(f"evidence_payload_after_{stage}")
    row["table_bytes_caveat"] = "Engine information_schema allocation estimates; snapshots may lag allocation/statistics updates. Not logical payload bytes."
    return row


root = pathlib.Path(sys.argv[1])
rows = []
for path in sorted(root.rglob("*.json")):
    # Shell redirection creates this output file before this script starts.
    # Also allow repeat summarization of an already-downloaded artifact.
    if path.name == "summary.json":
        continue
    data = json.loads(path.read_text())
    if "catalog_size" in data:
        rows.append(summarize(path))
print(json.dumps(rows, indent=2))
