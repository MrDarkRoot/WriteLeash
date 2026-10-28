#!/usr/bin/env python3
"""Build WebVTT cues from a fiducial painted into the encoded frames.

The recorder paints a 48px square at left 12px, bottom 56px, with a 4px white
border. Each scene has its own fill. This module reads those pixels back from
the extracted frames, so cue times follow the video clock. A later run that
paints the same scene later gets a later cue. Nothing here is a timestamp
copied from one recording.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

from PIL import Image, ImageDraw

FPS_DEFAULT = 2.0
MAX_CUE_CHARS = 180
MAX_CHARS_PER_SECOND = 40
MIN_SCENE_S = 0.4
GAP_KEEP_S = 1.5
MATCH_DISTANCE = 60
MIN_MATCH_PIXELS = 200

# CSS geometry of #wl-scene-marker. The sample box is the inner 24x24, clear of
# the white border, so the detector is not fitting one video's timestamps.
MARKER_LEFT = 12
MARKER_BOTTOM = 56
MARKER_SIZE = 48
MARKER_BORDER = 4
SAMPLE_INSET_X = 10
SAMPLE_INSET_Y = 10
SAMPLE_SIZE = 24

SCENE_ORDER = (
    "meet",
    "ask",
    "notice",
    "command",
    "preview",
    "confirm",
    "denied",
    "products",
    "board",
)

PALETTE = {
    "meet": (255, 0, 0),
    "ask": (0, 255, 0),
    "notice": (0, 0, 255),
    "command": (255, 255, 0),
    "preview": (255, 0, 255),
    "confirm": (0, 255, 255),
    "denied": (255, 136, 0),
    "products": (136, 0, 255),
    "board": (0, 136, 136),
}

TEMPLATES = {
    "meet": "Riley, a restricted shop manager, opens AddSynapse. No AI key is configured.",
    "ask": "Riley types a request to change only the Northwind Canvas Tote from $100.00 to $80.00.",
    "notice": "AddSynapse shows its own notice: the AI is not set up. No model ran. Cost is $0.",
    "command": "Negative control, not a model proposal. Riley types /price for Harborline Wool Cap.",
    "preview": "Local preview: regular price $40.00 to $49.99. Nothing is saved yet.",
    "confirm": "Riley presses Approve. The next button says Yes, apply the changes.",
    "denied": "Riley presses Yes, apply the changes. WriteLeash denies the product save. Zero changes applied.",
    "products": "Harborline Wool Cap still shows $40.00.",
    "board": (
        "Unreleased WriteLeash build, not the WordPress.org 0.2.2 package. "
        "Denial recorded for actor {actor} on product {product}."
    ),
}

VTT_TIME = re.compile(r"(\d{2}):(\d{2}):(\d{2})\.(\d{3})")


class SyncError(SystemExit):
    """A caption track that does not match the frames."""


def sample_box(height: int) -> tuple[int, int, int, int]:
    """Inner sample rectangle for a frame of this height."""
    top = height - MARKER_BOTTOM - MARKER_SIZE
    x0 = MARKER_LEFT + SAMPLE_INSET_X
    y0 = top + SAMPLE_INSET_Y
    return x0, y0, x0 + SAMPLE_SIZE, y0 + SAMPLE_SIZE


def paint_marker(image: Image.Image, scene: str) -> None:
    """Draw the same square the browser paints. Tests and the recorder share this geometry."""
    if scene not in PALETTE:
        raise SyncError(f"unknown scene {scene}")
    draw = ImageDraw.Draw(image)
    top = image.height - MARKER_BOTTOM - MARKER_SIZE
    left = MARKER_LEFT
    draw.rectangle(
        [left, top, left + MARKER_SIZE - 1, top + MARKER_SIZE - 1],
        fill=(255, 255, 255),
    )
    inset = MARKER_BORDER
    draw.rectangle(
        [left + inset, top + inset, left + MARKER_SIZE - 1 - inset, top + MARKER_SIZE - 1 - inset],
        fill=PALETTE[scene],
    )


def _nearest(rgb: tuple[int, int, int]) -> str | None:
    best_name = None
    best_distance = MATCH_DISTANCE + 1
    for name, color in PALETTE.items():
        distance = ((rgb[0] - color[0]) ** 2 + (rgb[1] - color[1]) ** 2 + (rgb[2] - color[2]) ** 2) ** 0.5
        if distance < best_distance:
            best_name = name
            best_distance = distance
    if best_distance <= MATCH_DISTANCE:
        return best_name
    return None


def detect_image(image: Image.Image) -> str | None:
    """Return the scene whose fill dominates the sample box, or None."""
    x0, y0, x1, y1 = sample_box(image.height)
    if x0 < 0 or y0 < 0 or x1 > image.width or y1 > image.height:
        raise SyncError(f"frame {image.size} is too small for the caption marker")
    crop = image.convert("RGB").crop((x0, y0, x1, y1))
    counts = {name: 0 for name in PALETTE}
    pixels = crop.get_flattened_data() if hasattr(crop, "get_flattened_data") else crop.getdata()
    for pixel in pixels:
        name = _nearest(pixel[:3])
        if name:
            counts[name] += 1
    winner = max(counts, key=counts.get)
    if counts[winner] < MIN_MATCH_PIXELS:
        return None
    ordered = sorted(counts.values(), reverse=True)
    if ordered[1] * 2 >= counts[winner]:
        return None
    return winner


def load_labels(frame_dir: Path) -> tuple[list[str | None], tuple[int, int]]:
    frames = sorted(frame_dir.glob("frame-*.png"))
    if not frames:
        raise SyncError(f"no frames in {frame_dir}")
    labels: list[str | None] = []
    size = None
    for path in frames:
        image = Image.open(path)
        if size is None:
            size = image.size
        elif image.size != size:
            raise SyncError(f"{path.name} is {image.size}, expected {size}")
        labels.append(detect_image(image))
    return labels, size or (0, 0)


def _runs(labels: list[str | None]) -> list[list[int | str]]:
    runs: list[list[int | str]] = []
    index = 0
    total = len(labels)
    while index < total:
        scene = labels[index]
        if scene is None:
            index += 1
            continue
        end = index
        while end + 1 < total and labels[end + 1] == scene:
            end += 1
        runs.append([scene, index, end])
        index = end + 1
    return runs


def _merge_runs(runs: list[list[int | str]], fps: float) -> list[list[int | str]]:
    if not runs:
        raise SyncError("no scene marker was detected in the frames")
    merged: list[list[int | str]] = [runs[0]]
    for scene, start, end in runs[1:]:
        previous = merged[-1]
        gap_frames = int(start) - int(previous[2]) - 1
        gap_s = gap_frames / fps
        if gap_frames > 0 and gap_s <= GAP_KEEP_S:
            previous[2] = int(start) - 1
        if previous[0] == scene and (gap_frames <= 0 or gap_s <= GAP_KEEP_S):
            previous[2] = max(int(previous[2]), int(end))
            continue
        merged.append([scene, int(start), int(end)])
    return merged


def _ms(index: int, fps: float) -> int:
    return int(round((index / fps) * 1000))


def cue_text(scene: str, actor: int, product: int) -> str:
    if scene == "board":
        return TEMPLATES["board"].format(actor=actor, product=product)
    return TEMPLATES[scene]


def build_cues(
    labels: list[str | None],
    fps: float,
    duration_s: float,
    actor: int,
    product: int,
) -> list[dict[str, object]]:
    """Turn per-frame labels into cue intervals on the video clock."""
    if fps <= 0:
        raise SyncError("fps must be positive")
    if duration_s <= 0:
        raise SyncError("duration must be positive")
    duration_ms = int(round(duration_s * 1000))
    merged = _merge_runs(_runs(labels), fps)
    cues: list[dict[str, object]] = []
    last_index = len(merged) - 1
    for position, (scene, start, end) in enumerate(merged):
        start_ms = _ms(int(start), fps)
        end_ms = _ms(int(end) + 1, fps)
        # Leave a leading gap unlabeled. Pulling the first cue to 0 would cover
        # the blank frame before the chat is on screen.
        if position == last_index:
            trailing_ms = duration_ms - end_ms
            if 0 <= trailing_ms <= int(GAP_KEEP_S * 1000):
                end_ms = duration_ms
        if end_ms > duration_ms:
            end_ms = duration_ms
        if start_ms < 0:
            start_ms = 0
        text = cue_text(str(scene), actor, product)
        cues.append({"id": scene, "start_ms": start_ms, "end_ms": end_ms, "text": text})
    return cues


def assert_track(cues: list[dict[str, object]], duration_s: float, labels: list[str | None], fps: float) -> None:
    duration_ms = int(round(duration_s * 1000))
    ids = [str(cue["id"]) for cue in cues]
    if tuple(ids) != SCENE_ORDER:
        raise SyncError(f"scene order is {ids}, expected {list(SCENE_ORDER)}")
    previous_end = 0
    for cue in cues:
        start_ms = int(cue["start_ms"])
        end_ms = int(cue["end_ms"])
        text = str(cue["text"])
        if start_ms < 0 or end_ms < 0:
            raise SyncError(f"{cue['id']} has a negative timestamp")
        if start_ms >= end_ms:
            raise SyncError(f"{cue['id']} is empty ({start_ms}..{end_ms})")
        if end_ms > duration_ms:
            raise SyncError(f"{cue['id']} ends at {end_ms} ms, past the {duration_ms} ms video")
        if start_ms < previous_end:
            raise SyncError(f"{cue['id']} overlaps the previous cue")
        previous_end = end_ms
        scene_s = (end_ms - start_ms) / 1000
        if scene_s < MIN_SCENE_S:
            raise SyncError(f"{cue['id']} lasts {scene_s:.3f}s, under {MIN_SCENE_S}s")
        if len(text) > MAX_CUE_CHARS:
            raise SyncError(f"{cue['id']} caption is {len(text)} characters")
        if len(text) / scene_s > MAX_CHARS_PER_SECOND:
            raise SyncError(f"{cue['id']} caption is too dense for {scene_s:.3f}s")
        midpoint = (start_ms + end_ms) / 2
        index = min(len(labels) - 1, max(0, int((midpoint / 1000) * fps)))
        detected = labels[index]
        if detected != cue["id"]:
            raise SyncError(
                f"{cue['id']} midpoint {midpoint / 1000:.3f}s is frame {index}, detected {detected}"
            )
    board = str(cues[-1]["text"])
    lowered = board.lower()
    if "unreleased" not in lowered or "wordpress.org" not in lowered or "0.2.2" not in board:
        raise SyncError("the last caption must identify an unreleased build, not the WordPress.org 0.2.2 package")
    if "not the wordpress.org 0.2.2 package" not in lowered:
        raise SyncError("the last caption must say this is not the WordPress.org 0.2.2 package")


def format_timestamp(total_ms: int) -> str:
    if total_ms < 0:
        raise SyncError("negative timestamp")
    hours, rem = divmod(total_ms, 3_600_000)
    minutes, rem = divmod(rem, 60_000)
    seconds, millis = divmod(rem, 1000)
    return f"{hours:02d}:{minutes:02d}:{seconds:02d}.{millis:03d}"


def render_vtt(cues: list[dict[str, object]]) -> str:
    lines = ["WEBVTT", ""]
    for cue in cues:
        lines.append(f"{format_timestamp(int(cue['start_ms']))} --> {format_timestamp(int(cue['end_ms']))}")
        lines.append(str(cue["text"]))
        lines.append("")
    return "\n".join(lines)


def parse_vtt(text: str) -> list[dict[str, object]]:
    body = text.lstrip("\ufeff").strip()
    if not body.startswith("WEBVTT"):
        raise SyncError("captions are not a WebVTT file")
    blocks = re.split(r"\n\s*\n", body)
    cues: list[dict[str, object]] = []
    for block in blocks[1:]:
        rows = [row.strip() for row in block.splitlines() if row.strip()]
        if not rows:
            continue
        timing = rows[0]
        if "-->" not in timing and len(rows) > 1:
            timing = rows[1]
            caption_rows = rows[2:]
        else:
            caption_rows = rows[1:]
        match = re.search(
            r"(\d{2}:\d{2}:\d{2}\.\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}\.\d{3})",
            timing,
        )
        if not match:
            raise SyncError(f"could not read a cue time from {timing!r}")
        start_ms = _parse_timestamp(match.group(1))
        end_ms = _parse_timestamp(match.group(2))
        cues.append({"start_ms": start_ms, "end_ms": end_ms, "text": " ".join(caption_rows)})
    return cues


def _parse_timestamp(value: str) -> int:
    match = VTT_TIME.fullmatch(value)
    if not match:
        raise SyncError(f"bad VTT time {value}")
    hours, minutes, seconds, millis = (int(part) for part in match.groups())
    return ((hours * 60 + minutes) * 60 + seconds) * 1000 + millis


def actor_and_product(evidence: dict) -> tuple[int, int]:
    actor = int(evidence["actor"]["id"])
    audit = evidence["on_camera"]["audit"]
    product = int(audit["object_id"])
    if int(audit["actor"]) != actor:
        raise SyncError(f"audit actor {audit['actor']} does not match Riley {actor}")
    if audit.get("where") != "product_save" or audit.get("code") != "writeleash_perimeter_denied":
        raise SyncError("on-camera audit is not a product_save perimeter denial")
    return actor, product


def derive(frame_dir: Path, duration_s: float, evidence: dict, fps: float) -> dict[str, object]:
    labels, size = load_labels(frame_dir)
    if size != (1440, 900):
        raise SyncError(f"frames are {size[0]}x{size[1]}, expected 1440x900")
    actor, product = actor_and_product(evidence)
    cues = build_cues(labels, fps, duration_s, actor, product)
    assert_track(cues, duration_s, labels, fps)
    denied = next(cue for cue in cues if cue["id"] == "denied")
    midpoint = (int(denied["start_ms"]) + int(denied["end_ms"])) / 2
    poster_index = min(len(labels) - 1, max(0, int((midpoint / 1000) * fps)))
    counts: dict[str, int] = {scene: 0 for scene in SCENE_ORDER}
    unlabeled = 0
    for label in labels:
        if label is None:
            unlabeled += 1
        else:
            counts[label] += 1
    return {
        "clock": "encoded-video-frames",
        "fps": fps,
        "duration_s": round(duration_s, 3),
        "frame_size": [size[0], size[1]],
        "sample_box": list(sample_box(size[1])),
        "cues": [
            {
                "id": cue["id"],
                "start_s": round(int(cue["start_ms"]) / 1000, 3),
                "end_s": round(int(cue["end_ms"]) / 1000, 3),
                "start_ms": int(cue["start_ms"]),
                "end_ms": int(cue["end_ms"]),
                "text": cue["text"],
            }
            for cue in cues
        ],
        "poster_frame_index": poster_index,
        "poster_frame": f"frame-{poster_index:05d}.png",
        "poster_time_s": round(poster_index / fps, 3),
        "detected_frames": counts,
        "unlabeled_frames": unlabeled,
        "vtt": render_vtt(cues),
    }


def validate_vtt_matches(report: dict[str, object], vtt_text: str) -> None:
    parsed = parse_vtt(vtt_text)
    cues = report["cues"]
    if not isinstance(cues, list) or len(parsed) != len(cues):
        raise SyncError("VTT cue count does not match the frames")
    for found, expected in zip(parsed, cues):
        if int(found["start_ms"]) != int(expected["start_ms"]) or int(found["end_ms"]) != int(expected["end_ms"]):
            raise SyncError(f"{expected['id']} VTT times do not match the frames")
        if str(found["text"]) != str(expected["text"]):
            raise SyncError(f"{expected['id']} VTT text does not match the caption template")


def sync_files(frame_dir: Path, duration_s: float, evidence_path: Path, vtt_path: Path, json_path: Path, fps: float) -> dict:
    evidence = json.loads(evidence_path.read_text())
    report = derive(frame_dir, duration_s, evidence, fps)
    vtt = str(report.pop("vtt"))
    vtt_path.write_text(vtt)
    json_path.write_text(json.dumps(report, indent=2) + "\n")
    validate_vtt_matches(report, vtt_path.read_text())
    return report


def main() -> None:
    parser = argparse.ArgumentParser(description="Synchronize captions to encoded video frames")
    sub = parser.add_subparsers(dest="command", required=True)
    for name in ("sync", "validate"):
        command = sub.add_parser(name)
        command.add_argument("--frames", type=Path, required=True)
        command.add_argument("--duration", type=float, required=True)
        command.add_argument("--evidence", type=Path, required=True)
        command.add_argument("--vtt", type=Path, required=True)
        command.add_argument("--json", type=Path, required=True)
        command.add_argument("--fps", type=float, default=FPS_DEFAULT)
    args = parser.parse_args()
    if args.command == "sync":
        sync_files(args.frames, args.duration, args.evidence, args.vtt, args.json, args.fps)
        print(f"captions synchronized to {args.duration:.3f}s video")
        return
    evidence = json.loads(args.evidence.read_text())
    report = derive(args.frames, args.duration, evidence, args.fps)
    report.pop("vtt", None)
    validate_vtt_matches(report, args.vtt.read_text())
    args.json.write_text(json.dumps(report, indent=2) + "\n")
    print("caption sync validated")


if __name__ == "__main__":
    main()
