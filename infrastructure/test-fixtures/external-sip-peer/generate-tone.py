#!/usr/bin/env python3
import math
import struct
import sys
import wave

duration_seconds = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
sample_count = int(8000 * duration_seconds)

with wave.open(sys.argv[1], "wb") as output:
    output.setnchannels(1)
    output.setsampwidth(2)
    output.setframerate(8000)
    samples = (struct.pack("<h", int(12000 * math.sin(2 * math.pi * 440 * i / 8000))) for i in range(sample_count))
    output.writeframes(b"".join(samples))
