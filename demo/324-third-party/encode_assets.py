#!/usr/bin/env python3
"""Turn encoded-video frames into a looping GIF and a poster still."""
import json
import sys
from pathlib import Path

from PIL import Image

# Thirty frames at 500 ms is 15.0 s. Weights follow the visible story so the
# loop is not thirty copies of the longest hold.
WEIGHTS = {
    "meet": 2,
    "ask": 4,
    "notice": 2,
    "command": 4,
    "preview": 3,
    "confirm": 3,
    "denied": 5,
    "products": 3,
    "board": 4,
}


def luminance(image: Image.Image) -> float:
    small = image.convert("RGB").resize((64, 40))
    pixels = list(small.get_flattened_data() if hasattr(small, "get_flattened_data") else small.getdata())
    total = 0.0
    for red, green, blue in pixels:
        total += red * 0.2126 + green * 0.7152 + blue * 0.0722
    return total / len(pixels)


def pick_even(indexes: list[int], want: int) -> list[int]:
    if want <= 0 or not indexes:
        return []
    if len(indexes) <= want:
        return list(indexes)
    picked: list[int] = []
    for step in range(want):
        pos = min(len(indexes) - 1, (step * len(indexes)) // want)
        while pos < len(indexes) and indexes[pos] in picked:
            pos += 1
        if pos >= len(indexes):
            break
        picked.append(indexes[pos])
    return picked


def frames_in_cue(total: int, cue: dict, fps: float, last: bool) -> list[int]:
    start = float(cue["start_s"])
    end = float(cue["end_s"])
    chosen = []
    for index in range(total):
        stamp = index / fps
        if last:
            inside = start <= stamp <= end
        else:
            inside = start <= stamp < end
        if inside:
            chosen.append(index)
    return chosen


def sample_weighted(frames: list[Path], sync: dict, count: int = 30) -> list[Path]:
    fps = float(sync.get("fps") or 2)
    cues = list(sync["cues"])
    if sum(WEIGHTS.values()) != count:
        raise SystemExit("GIF weights must sum to the frame count")
    pools: dict[str, list[int]] = {}
    selected: list[int] = []
    shortfall = 0
    for cue in cues:
        pools[str(cue["id"])] = frames_in_cue(len(frames), cue, fps, cue is cues[-1])
    for cue in cues:
        want = WEIGHTS[str(cue["id"])]
        picked = pick_even(pools[str(cue["id"])], want)
        selected.extend(picked)
        shortfall += want - len(picked)
    used = set(selected)
    for donor in ("denied", "ask", "board", "preview", "command", "confirm", "products", "notice", "meet"):
        if shortfall <= 0:
            break
        extra = [index for index in pools.get(donor, []) if index not in used]
        picked = pick_even(extra, min(shortfall, len(extra)))
        selected.extend(picked)
        used.update(picked)
        shortfall -= len(picked)
    ordered = sorted(used)
    if len(ordered) > count:
        ordered = pick_even(ordered, count)
    if len(ordered) < 8:
        raise SystemExit(f"need at least 8 GIF frames, found {len(ordered)}")
    return [frames[index] for index in ordered]


def sample_uniform(frames: list[Path], count: int = 30) -> list[Path]:
    total = len(frames)
    if total <= count:
        return frames
    return [frames[min(total - 1, (index * total) // count)] for index in range(count)]


def gif(frames: list[Path], dest: Path, width: int = 960) -> int:
    if len(frames) < 8:
        raise SystemExit(f"need at least 8 GIF frames, found {len(frames)}")
    images = []
    for path in frames:
        image = Image.open(path).convert("RGB")
        if luminance(image) < 8:
            raise SystemExit(f"black frame in the GIF sample: {path.name}")
        height = max(1, round(image.height * width / image.width))
        image = image.resize((width, height), Image.Resampling.LANCZOS)
        images.append(image.quantize(colors=48, method=Image.Quantize.MEDIANCUT))
    images[0].save(
        dest,
        save_all=True,
        append_images=images[1:],
        duration=500,
        loop=0,
        optimize=False,
        disposal=2,
    )
    return len(images)


def poster(src: Path, dest: Path) -> None:
    image = Image.open(src).convert("RGB")
    if luminance(image) < 8:
        raise SystemExit(f"poster source is black: {src}")
    image.save(dest, format="PNG", optimize=True)


def main() -> None:
    if len(sys.argv) not in (5, 6):
        raise SystemExit("usage: encode_assets.py frames poster_src gif_dest poster_dest [caption-sync.json]")
    frame_dir, poster_src, gif_dest, poster_dest = map(Path, sys.argv[1:5])
    available = sorted(frame_dir.glob("frame-*.png"))
    if len(sys.argv) == 6:
        sync = json.loads(Path(sys.argv[5]).read_text())
        chosen = sample_weighted(available, sync)
    else:
        chosen = sample_uniform(available)
    kept = gif(chosen, gif_dest)
    poster(poster_src, poster_dest)
    gif_image = Image.open(gif_dest)
    frames = int(getattr(gif_image, "n_frames", 1))
    delays = []
    for index in range(frames):
        gif_image.seek(index)
        delays.append(int(gif_image.info.get("duration") or 0))
    report = {
        "gif_bytes": gif_dest.stat().st_size,
        "poster_bytes": poster_dest.stat().st_size,
        "gif_size": list(gif_image.size),
        "poster_size": list(Image.open(poster_dest).size),
        "gif_frames": frames,
        "gif_frames_selected": kept,
        "gif_delays_ms": delays,
        "gif_duration_s": round(sum(delays) / 1000, 3),
        "gif_loop": gif_image.info.get("loop"),
        "gif_sample": "scene-weighted" if len(sys.argv) == 6 else "uniform",
    }
    print(json.dumps(report))


if __name__ == "__main__":
    main()
