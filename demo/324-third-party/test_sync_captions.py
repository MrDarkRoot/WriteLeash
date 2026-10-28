#!/usr/bin/env python3
"""Synthetic-frame tests for caption sync. No recorded video is used as a clock."""
import json
import tempfile
import unittest
from pathlib import Path

from PIL import Image

import sync_captions as sync


def evidence(actor: int = 2, product: int = 11) -> dict:
    return {
        "actor": {"id": actor},
        "on_camera": {
            "audit": {
                "actor": actor,
                "object_id": product,
                "where": "product_save",
                "code": "writeleash_perimeter_denied",
            }
        },
    }


def blank() -> Image.Image:
    return Image.new("RGB", (1440, 900), (29, 35, 39))


def labeled_sequence() -> list[str | None]:
    """A timeline whose boundaries are not the times from any committed recording."""
    parts: list[str | None] = [None, None]
    spans = {
        "meet": 8,
        "ask": 10,
        "notice": 6,
        "command": 8,
        "preview": 8,
        "confirm": 8,
        "denied": 12,
        "products": 8,
        "board": 10,
    }
    for scene in sync.SCENE_ORDER:
        parts.extend([scene] * spans[scene])
    parts.append(None)
    return parts


class CaptionSyncTest(unittest.TestCase):
    def test_shifted_markers_shift_cues(self) -> None:
        early = labeled_sequence()
        late = [None, None, None, None] + early[2:]
        fps = 2.0
        duration = len(early) / fps + 0.2
        early_cues = sync.build_cues(early, fps, duration, 2, 11)
        late_cues = sync.build_cues(late, fps, duration + 1, 2, 11)
        early_ask = next(cue for cue in early_cues if cue["id"] == "ask")
        late_ask = next(cue for cue in late_cues if cue["id"] == "ask")
        self.assertNotEqual(early_ask["start_ms"], late_ask["start_ms"])
        self.assertGreater(int(late_ask["start_ms"]), int(early_ask["start_ms"]))

    def test_track_rules_and_disclaimer(self) -> None:
        labels = labeled_sequence()
        fps = 2.0
        duration = (len(labels) / fps) + 0.25
        cues = sync.build_cues(labels, fps, duration, 4, 18)
        sync.assert_track(cues, duration, labels, fps)
        self.assertEqual(cues[-1]["text"].count("actor 4"), 1)
        self.assertIn("product 18", str(cues[-1]["text"]))
        self.assertIn("not the WordPress.org 0.2.2 package", str(cues[-1]["text"]))
        self.assertLessEqual(int(cues[-1]["end_ms"]), int(round(duration * 1000)))
        self.assertEqual(int(cues[0]["start_ms"]), 1000)
        self.assertGreater(int(cues[0]["start_ms"]), 0)

    def test_short_gap_stays_with_the_previous_scene(self) -> None:
        labels: list[str | None] = ["meet", "meet", None, None, "ask", "ask", "ask", "ask"]
        # The rest of the required scenes are long enough to pass the other rules.
        for scene in sync.SCENE_ORDER[2:]:
            labels.extend([scene] * 4)
        fps = 2.0
        duration = len(labels) / fps
        cues = sync.build_cues(labels, fps, duration, 2, 11)
        meet = cues[0]
        ask = cues[1]
        self.assertEqual(meet["end_ms"], ask["start_ms"])
        self.assertEqual(meet["end_ms"], 2000)

    def test_long_gap_is_not_given_a_hardcoded_time(self) -> None:
        labels = ["meet"] * 4 + [None] * 8 + ["ask"] * 4
        for scene in sync.SCENE_ORDER[2:]:
            labels.extend([scene] * 4)
        fps = 2.0
        duration = len(labels) / fps
        cues = sync.build_cues(labels, fps, duration, 2, 11)
        self.assertLess(int(cues[0]["end_ms"]), int(cues[1]["start_ms"]))

    def test_rejects_cue_past_duration_overlap_and_missing_disclaimer(self) -> None:
        labels = labeled_sequence()
        fps = 2.0
        duration = len(labels) / fps
        cues = sync.build_cues(labels, fps, duration, 2, 11)
        overdue = [dict(cue) for cue in cues]
        overdue[-1] = dict(overdue[-1], end_ms=int(overdue[-1]["end_ms"]) + 500)
        with self.assertRaises(sync.SyncError):
            sync.assert_track(overdue, duration, labels, fps)
        overlap = [dict(cue) for cue in cues]
        overlap[1] = dict(overlap[1], start_ms=int(overlap[0]["end_ms"]) - 50)
        with self.assertRaises(sync.SyncError):
            sync.assert_track(overlap, duration, labels, fps)
        bare = [dict(cue) for cue in cues]
        bare[-1] = dict(bare[-1], text="Denial recorded for actor 2 on product 11.")
        with self.assertRaises(sync.SyncError):
            sync.assert_track(bare, duration, labels, fps)

    def test_files_round_trip_and_reject_a_stale_vtt(self) -> None:
        labels = labeled_sequence()
        fps = 2.0
        duration = (len(labels) / fps) + 0.1
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            frames = root / "frames"
            frames.mkdir()
            for index, scene in enumerate(labels):
                image = blank()
                if scene:
                    sync.paint_marker(image, scene)
                image.save(frames / f"frame-{index:05d}.png")
            evidence_path = root / "evidence.json"
            evidence_path.write_text(json.dumps(evidence()))
            vtt_path = root / "captions.en.vtt"
            json_path = root / "caption-sync.json"
            report = sync.sync_files(frames, duration, evidence_path, vtt_path, json_path, fps)
            saved = json.loads(json_path.read_text())
            self.assertEqual(saved["clock"], "encoded-video-frames")
            self.assertEqual(saved["cues"][0]["id"], "meet")
            self.assertIn("poster_frame", report)
            original = vtt_path.read_text()
            start = sync.format_timestamp(int(report["cues"][0]["start_ms"]))
            stale = original.replace(start, "00:00:09.000", 1)
            self.assertNotEqual(stale, original)
            vtt_path.write_text(stale)
            fresh = sync.derive(frames, duration, evidence(), fps)
            fresh.pop("vtt", None)
            with self.assertRaises(sync.SyncError):
                sync.validate_vtt_matches(fresh, vtt_path.read_text())

    def test_compressed_orange_still_reads_as_denied(self) -> None:
        image = blank()
        sync.paint_marker(image, "denied")
        shifted = image.copy()
        pixels = shifted.load()
        box = sync.sample_box(shifted.height)
        for y in range(box[1], box[3]):
            for x in range(box[0], box[2]):
                red, green, blue = pixels[x, y]
                pixels[x, y] = (max(0, red - 18), max(0, green - 20), min(255, blue + 12))
        self.assertEqual(sync.detect_image(shifted), "denied")
        self.assertIsNone(sync.detect_image(blank()))


if __name__ == "__main__":
    unittest.main()
