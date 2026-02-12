#!/usr/bin/env python3
"""No-copy Lightning Whisper MLX transcription + duplication metrics.

Run with uv:
  uv run --with lightning-whisper-mlx python /Users/matt/bin/transcribe/lightning_whisper_test.py \
    --audio /path/to/audio.m4a --offset-ms 2230000 --duration-ms 90000
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import tempfile
from collections import Counter
from pathlib import Path

from lightning_whisper_mlx import LightningWhisperMLX


def run_cmd(cmd: list[str]) -> None:
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(
            f"command failed ({proc.returncode}): {' '.join(cmd)}\n"
            f"stdout:\n{proc.stdout}\n"
            f"stderr:\n{proc.stderr}"
        )


def clip_audio(input_audio: str, offset_ms: int, duration_ms: int, out_wav: str) -> None:
    cmd = [
        "ffmpeg",
        "-y",
        "-ss",
        f"{offset_ms / 1000:.3f}",
        "-t",
        f"{duration_ms / 1000:.3f}",
        "-i",
        input_audio,
        "-ar",
        "16000",
        "-ac",
        "1",
        "-c:a",
        "pcm_s16le",
        out_wav,
    ]
    run_cmd(cmd)


def normalize_text(text: str) -> str:
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    text = re.sub(r"\s+", " ", text).strip()
    return text


def repeated_lines(text: str, min_len: int = 8) -> list[tuple[int, str]]:
    candidates = [line.strip() for line in text.split(".")]
    candidates = [c for c in candidates if len(c) >= min_len]
    counts = Counter(candidates)
    return sorted(((n, line) for line, n in counts.items() if n > 1), reverse=True)


def phrase_count(text: str, phrase: str) -> int:
    return len(re.findall(re.escape(phrase.lower()), text.lower()))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--audio", required=True)
    parser.add_argument("--model", default="distil-large-v3")
    parser.add_argument("--batch-size", type=int, default=12)
    parser.add_argument("--language", default="en")
    parser.add_argument("--offset-ms", type=int, default=0)
    parser.add_argument("--duration-ms", type=int, default=0)
    parser.add_argument("--out-prefix", default="/tmp/jan22-lightning")
    args = parser.parse_args()

    src_audio = args.audio
    tmp_wav: str | None = None

    if args.offset_ms > 0 and args.duration_ms > 0:
        tmp = tempfile.NamedTemporaryFile(suffix=".wav", delete=False)
        tmp_wav = tmp.name
        tmp.close()
        clip_audio(src_audio, args.offset_ms, args.duration_ms, tmp_wav)
        src_audio = tmp_wav

    whisper = LightningWhisperMLX(model=args.model, batch_size=args.batch_size, quant=None)
    result = whisper.transcribe(src_audio, language=args.language)

    text = normalize_text(result.get("text", ""))
    out_txt = Path(f"{args.out_prefix}.txt")
    out_json = Path(f"{args.out_prefix}.metrics.json")
    out_txt.write_text(text + "\n", encoding="utf-8")

    repeats = repeated_lines(text)
    metrics = {
        "audio": args.audio,
        "effective_audio": src_audio,
        "model": args.model,
        "batch_size": args.batch_size,
        "language": result.get("language"),
        "offset_ms": args.offset_ms,
        "duration_ms": args.duration_ms,
        "words": len(text.split()),
        "monster_count": phrase_count(text, "i want to show you a monster"),
        "smoke_anymore_count": phrase_count(text, "i don't want to smoke it anymore"),
        "you_know_count": phrase_count(text, "you know what's happening"),
        "top_repeats": repeats[:20],
        "out_txt": str(out_txt),
    }
    out_json.write_text(json.dumps(metrics, indent=2), encoding="utf-8")
    print(json.dumps(metrics, indent=2))


if __name__ == "__main__":
    main()
