#!/usr/bin/env python3
"""Generate the checked-in deterministic FreeSWITCH reference tone."""

from __future__ import annotations

import base64
import io
import math
import struct
import sys
import wave
from pathlib import Path

SAMPLE_RATE = 8_000
DURATION_SECONDS = 5
FREQUENCY_HZ = 440
AMPLITUDE = 0.45
SAMPLE_COUNT = SAMPLE_RATE * DURATION_SECONDS


def main() -> None:
    destination = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(
        "infrastructure/docker/media/reference-tone.wav.base64"
    )
    samples = b"".join(
        struct.pack("<h", int(AMPLITUDE * 32767 * math.sin(2 * math.pi * FREQUENCY_HZ * index / SAMPLE_RATE)))
        for index in range(SAMPLE_COUNT)
    )
    raw = bytearray()
    with io.BytesIO() as buffer:
        with wave.open(buffer, "wb") as wav:
            wav.setnchannels(1)
            wav.setsampwidth(2)
            wav.setframerate(SAMPLE_RATE)
            wav.writeframes(samples)
        raw.extend(buffer.getvalue())
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(base64.b64encode(raw).decode("ascii") + "\n")
    print(f"wrote {destination}: {len(raw)} bytes, {SAMPLE_COUNT} samples")


if __name__ == "__main__":
    main()
