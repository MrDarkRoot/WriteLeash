#!/usr/bin/env python3
"""Turn the Playwright frame sequence into a looping GIF and check outputs."""
import json
import sys
from pathlib import Path

from PIL import Image


def sample(frames: list[Path], count: int = 30) -> list[Path]:
    """Keep the loop near 15 seconds. Pillow's optimize flag drops duplicate
    frames without extending the survivors, which made a 4-second GIF."""
    total = len(frames)
    if total <= count:
        return frames
    return [frames[min(total - 1, (i * total) // count)] for i in range(count)]


def gif(frame_dir: Path, dest: Path, width: int = 960) -> int:
    frames = sample(sorted(frame_dir.glob("frame-*.png")))
    if len(frames) < 8:
        raise SystemExit(f"need at least 8 GIF frames, found {len(frames)}")
    images = []
    for path in frames:
        image = Image.open(path).convert("RGB")
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
    image.save(dest, format="PNG", optimize=True)


def main() -> None:
    frame_dir, poster_src, gif_dest, poster_dest = map(Path, sys.argv[1:5])
    kept = gif(frame_dir, gif_dest)
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
    }
    print(json.dumps(report))


if __name__ == "__main__":
    main()
