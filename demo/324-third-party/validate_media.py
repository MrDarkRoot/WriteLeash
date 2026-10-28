#!/usr/bin/env python3
"""Check the regenerated demo files against each other and the encoded frames."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from PIL import Image

import sync_captions as sync

PINNED_ADDSYNAPSE_SHA = "8f2d15e1d58e4efb0b34522fb47f05f5f6ad46bbfefc77b6e77084d3a0fe49f2"
EXPIRY_RE = __import__("re").compile(r"\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\Z")


def utc_ms(value: str) -> int:
    text = value.strip()
    if len(text) == 19 and text[10] == " ":
        text = text.replace(" ", "T") + "Z"
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    # YYYY-MM-DDTHH:MM:SS+00:00
    date, time = text[:10], text[11:19]
    hours, minutes, seconds = (int(part) for part in time.split(":"))
    year, month, day = (int(part) for part in date.split("-"))
    # Compare by ordering the UTC fields. The demo only needs expiry after the run.
    return (((year * 12 + month) * 31 + day) * 24 + hours) * 3600 + minutes * 60 + seconds


def luminance(path: Path) -> float:
    image = Image.open(path).convert("RGB").resize((64, 40))
    pixels = list(image.get_flattened_data() if hasattr(image, "get_flattened_data") else image.getdata())
    total = 0.0
    for red, green, blue in pixels:
        total += red * 0.2126 + green * 0.7152 + blue * 0.0722
    return total / len(pixels)


def add(results: list[dict[str, str]], name: str, ok: bool, detail: str) -> None:
    results.append({"check": name, "result": "PASS" if ok else "FAIL", "detail": detail})


def changeset(evidence: dict, key: str) -> dict:
    block = evidence[key]
    body = json.loads(block["approve_body"])
    return {
        "http": block.get("approve_http"),
        "status": body.get("status"),
        "applied": (body.get("counts") or {}).get("applied"),
        "failed": (body.get("counts") or {}).get("failed"),
        "user_ids": [row.get("user_id") for row in body.get("log") or [] if isinstance(row, dict)],
    }


def validate(out: Path, frames: Path) -> dict:
    results: list[dict[str, str]] = []
    evidence = json.loads((out / "evidence.json").read_text())
    versions = json.loads((out / "versions.json").read_text())
    video = json.loads((out / "video-report.json").read_text())
    image = json.loads((out / "image-report.json").read_text())
    caption_sync = json.loads((out / "caption-sync.json").read_text())
    vtt = (out / "captions.en.vtt").read_text()

    mp4 = out / "writeleash-addsynapse-deny.mp4"
    webm = out / "writeleash-addsynapse-deny.webm"
    gif = out / "writeleash-addsynapse-deny.gif"
    poster = out / "writeleash-addsynapse-deny-poster.png"
    for path in (mp4, webm, gif, poster):
        add(results, f"file exists {path.name}", path.is_file() and path.stat().st_size > 0, str(path.stat().st_size if path.is_file() else 0))

    mp4_meta = video["mp4"]
    webm_meta = video["webm"]
    add(results, "mp4 bytes match the file", mp4_meta["bytes"] == mp4.stat().st_size, str(mp4_meta["bytes"]))
    add(results, "webm bytes match the file", webm_meta["bytes"] == webm.stat().st_size, str(webm_meta["bytes"]))
    add(results, "gif bytes match the file", image["gif_bytes"] == gif.stat().st_size, str(image["gif_bytes"]))
    add(results, "poster bytes match the file", image["poster_bytes"] == poster.stat().st_size, str(image["poster_bytes"]))
    duration = float(mp4_meta["duration_s"])
    add(results, "mp4 duration is 60 to 75 seconds", 60 <= duration <= 75, f"{duration}s")
    add(
        results,
        "webm duration matches the mp4",
        abs(float(webm_meta["duration_s"]) - duration) <= 0.75,
        f"webm {webm_meta['duration_s']} mp4 {duration}",
    )
    add(results, "picture is 1440x900", mp4_meta["width"] == 1440 and mp4_meta["height"] == 900 and webm_meta["width"] == 1440 and webm_meta["height"] == 900, f"{mp4_meta['width']}x{mp4_meta['height']}")
    add(results, "gif is about 15 seconds", 14.5 <= float(image["gif_duration_s"]) <= 15.5, str(image["gif_duration_s"]))
    add(results, "gif is 960 wide and loops", image["gif_size"][0] == 960 and image["gif_frames"] == 30 and image.get("gif_loop") == 0, json.dumps({"size": image["gif_size"], "frames": image["gif_frames"], "loop": image.get("gif_loop")}))
    add(results, "poster is 1440x900", image["poster_size"] == [1440, 900], str(image["poster_size"]))

    try:
        fresh = sync.derive(frames, duration, evidence, float(caption_sync["fps"]))
        fresh.pop("vtt", None)
        sync.validate_vtt_matches(fresh, vtt)
        paired = list(zip(fresh["cues"], caption_sync["cues"]))
        same_times = [
            left["id"]
            for left, right in paired
            if int(left["start_ms"]) == int(right["start_ms"]) and int(left["end_ms"]) == int(right["end_ms"]) and left["text"] == right["text"]
        ]
        add(
            results,
            "captions match this video's frames",
            len(fresh["cues"]) == len(caption_sync["cues"]) == len(same_times) == 9,
            f"{len(same_times)} of {len(fresh['cues'])} cues",
        )
    except sync.SyncError as error:
        add(results, "captions match this video's frames", False, str(error))

    poster_image = Image.open(poster).convert("RGB")
    add(results, "poster shows the denial marker", sync.detect_image(poster_image) == "denied", str(sync.detect_image(poster_image)))

    dark = []
    frame_paths = sorted(frames.glob("frame-*.png"))
    for path in frame_paths:
        if luminance(path) < 8:
            dark.append(path.name)
    add(results, "no black story frames", not dark, ", ".join(dark[:8]) or f"{len(frame_paths)} frames")

    cap = changeset(evidence, "on_camera")
    named = changeset(evidence, "named_product")
    control = changeset(evidence, "positive_control")
    add(results, "AddSynapse 1.3.5 from the pinned zip", evidence["plugin"]["version"] == "1.3.5" and evidence["plugin"]["zip_sha256"] == PINNED_ADDSYNAPSE_SHA and versions["third_party_version"] == "1.3.5" and versions["third_party_zip_sha256"] == PINNED_ADDSYNAPSE_SHA, evidence["plugin"]["version"])
    riley = int(evidence["actor"]["id"])
    add(results, "Riley is the approve user and the save actor", cap["http"] == 200 and all(int(user_id) == riley for user_id in cap["user_ids"]) and int(evidence["on_camera"]["audit"]["actor"]) == riley and int(evidence["named_product"]["audit"]["actor"]) == riley, json.dumps({"cap_users": cap["user_ids"], "actor": riley}))
    add(results, "both Riley confirms returned a failed changeset", cap["status"] == "failed" and cap["applied"] == 0 and cap["failed"] == 1 and named["http"] == 200 and named["status"] == "failed" and named["applied"] == 0 and named["failed"] == 1, json.dumps({"cap": cap, "named": named}))
    add(results, "Harborline Wool Cap stayed 40.00", evidence["prices"]["cap_before"] == "40.00" and evidence["prices"]["cap_after"] == "40.00", evidence["prices"]["cap_after"])
    add(results, "Northwind Canvas Tote stayed 100.00", evidence["prices"]["tote_before"] == "100.00" and evidence["prices"]["tote_after"] == "100.00", evidence["prices"]["tote_after"])
    add(results, "unrestricted administrator control applied", control["http"] == 200 and control["status"] == "applied" and int(control["applied"]) >= 1 and control["failed"] == 0 and evidence["prices"]["lantern_after"] == "55.00", json.dumps(control))
    hashes = evidence["sha256"]
    add(results, "product-row hashes match across the denials", evidence["rows_unchanged"] is True and hashes["after_control"] == hashes["after_named_deny"] == hashes["after_cap_deny"], hashes["after_cap_deny"])
    audit = evidence["on_camera"]["audit"]
    add(results, "audit records actor, product, surface, and code", audit["where"] == "product_save" and audit["code"] == "writeleash_perimeter_denied" and audit["kind"] == "price" and int(audit["object_id"]) == int(evidence["on_camera"]["audit"]["object_id"]), json.dumps(audit))
    expires = evidence["grant"]["expires_at"]
    proof = evidence.get("grant_proof") or {}
    add(results, "grant expiry is one future UTC timestamp", bool(EXPIRY_RE.fullmatch(expires)) and expires == versions.get("grant_expires_at") and expires == proof.get("expires_at") and proof.get("status") == "active" and proof.get("restricted") is True and proof.get("expires_source") == "utc_now_plus_30_days", json.dumps({"expires": expires, "now": proof.get("now"), "status": proof.get("status")}))
    expiry_ms = utc_ms(expires)
    audit_times = [row.get("timestamp", "") for row in evidence.get("audit_lines") or []]
    later = all(utc_ms(stamp) < expiry_ms for stamp in audit_times if stamp)
    add(results, "expiry is after the recording and the audit lines", bool(audit_times) and later and utc_ms(str(proof.get("now"))) < expiry_ms, json.dumps({"now": proof.get("now"), "audits": audit_times, "expires": expires}))
    add(results, "no model was called and cost is zero", evidence["model_called"] is False and versions["model_called"] is False and evidence["provider_cost_usd"] == 0 and versions["provider_cost_usd"] == 0, "0")
    db_names = ("db-after-control.json", "db-after-named-deny.json", "db-after-cap-deny.json")
    db_shas = []
    for name in db_names:
        payload = json.loads((out / name).read_text())
        db_shas.append(payload.get("sha256"))
    add(results, "database snapshots share the evidence hash", len(set(db_shas)) == 1 and db_shas[0] == hashes["after_cap_deny"], str(db_shas[0]))

    failed = [row for row in results if row["result"] != "PASS"]
    return {"results": results, "failed": len(failed)}


def main() -> None:
    parser = argparse.ArgumentParser(description="Validate demo media and evidence")
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--frames", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    report = validate(args.out, args.frames)
    args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({"failed": report["failed"], "checks": len(report["results"])}))
    if report["failed"]:
        for row in report["results"]:
            if row["result"] != "PASS":
                print(f"FAIL {row['check']}: {row['detail']}", file=sys.stderr)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
