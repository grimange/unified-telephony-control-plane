#!/usr/bin/env python3
import os
import socket
import struct
import time

target = (os.environ["RTP_TARGET_IP"], int(os.environ["RTP_TARGET_PORT"]))
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
source_port = int(os.environ.get("RTP_SOURCE_PORT", "0"))
if source_port:
    sock.bind(("0.0.0.0", source_port))
sequence = 0
timestamp = 0
ssrc = 0x55544350
payload = bytes([0xFF]) * 160
deadline = time.monotonic() + float(os.environ.get("RTP_DURATION", "60"))
while time.monotonic() < deadline:
    header = struct.pack("!BBHII", 0x80, 0x00, sequence & 0xFFFF, timestamp, ssrc)
    sock.sendto(header + payload, target)
    sequence += 1
    timestamp += 160
    time.sleep(0.02)
