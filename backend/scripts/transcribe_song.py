#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

from faster_whisper import WhisperModel


def format_timestamp(seconds: float) -> str:
    total = max(0.0, float(seconds))
    minutes = int(total // 60)
    remain = total - minutes * 60
    return f"[{minutes:02d}:{remain:05.2f}]"


def main() -> int:
    if len(sys.argv) != 3:
        print(json.dumps({"ok": False, "error": "usage: transcribe_song.py <audio_path> <output_lrc_path>"}, ensure_ascii=False))
        return 1

    audio_path = Path(sys.argv[1])
    output_lrc_path = Path(sys.argv[2])

    if not audio_path.exists():
        print(json.dumps({"ok": False, "error": "audio file not found"}, ensure_ascii=False))
        return 1

    output_lrc_path.parent.mkdir(parents=True, exist_ok=True)

    model = WhisperModel("small", device="cpu", compute_type="int8")
    segments, info = model.transcribe(str(audio_path), beam_size=5, vad_filter=True, language="zh")

    lyrics_lines = []
    lrc_lines = []
    timeline = []
    for segment in segments:
        text = (segment.text or "").strip()
        if not text:
            continue
        lyrics_lines.append(text)
        lrc_lines.append(f"{format_timestamp(segment.start)}{text}")
        timeline.append({
            "start": float(segment.start),
            "end": float(segment.end),
            "text": text,
        })

    output_lrc_path.write_text("\n".join(lrc_lines) + ("\n" if lrc_lines else ""), encoding="utf-8")
    payload = {
        "ok": True,
        "lyrics": "\n".join(lyrics_lines),
        "segments": len(lrc_lines),
        "timeline": timeline,
        "language": getattr(info, "language", "unknown"),
        "duration": getattr(info, "duration", None),
    }
    print(json.dumps(payload, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
