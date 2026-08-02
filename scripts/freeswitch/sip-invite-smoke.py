#!/usr/bin/env python3
"""Send one unauthenticated SIP INVITE to the local FreeSWITCH fixture."""

from __future__ import annotations

import re
import socket
import sys
import time
import uuid
import os
from typing import NoReturn


def fail(message: str) -> NoReturn:
    print(f"FreeSWITCH SIP smoke test failed: {message}", file=sys.stderr)
    raise SystemExit(1)


def parse_message(data: bytes) -> tuple[str, dict[str, str], str]:
    text = data.decode("utf-8", errors="replace")
    head, _, body = text.partition("\r\n\r\n")
    lines = head.split("\r\n")
    headers: dict[str, str] = {}
    for line in lines[1:]:
        if ":" in line:
            name, value = line.split(":", 1)
            headers[name.lower()] = value.strip()
    return lines[0], headers, body


def header(headers: dict[str, str], name: str) -> str:
    value = headers.get(name.lower())
    if not value:
        fail(f"SIP response is missing {name}")
    return value


def contact_uri(value: str) -> str:
    match = re.search(r"<([^>]+)>", value)
    uri = match.group(1) if match else value.split(";", 1)[0].strip()
    if not uri.startswith("sip:"):
        fail(f"SIP Contact is not a SIP URI: {value}")
    return uri


def send(sock: socket.socket, address: tuple[str, int], request: str) -> None:
    sock.sendto(request.encode("utf-8"), address)


def main() -> None:
    if len(sys.argv) != 2:
        fail("usage: sip-invite-smoke.py <container-ip>")
    target_ip = sys.argv[1]
    target = (target_ip, 5060)
    call_id = f"utcp-freeswitch-smoke-{uuid.uuid4().hex}@utcp.local"
    branch = f"z9hG4bK-utcp-{uuid.uuid4().hex}"
    from_tag = f"utcp-{uuid.uuid4().hex[:12]}"
    from_uri = "sip:smoke@sip.utcp.local.test"
    to_uri = f"sip:9900@{target_ip}:5060"
    contact = f"<sip:smoke@127.0.0.1:5060>"
    sdp = (
        "v=0\r\n"
        "o=- 1 1 IN IP4 127.0.0.1\r\n"
        "s=UTCP FreeSWITCH smoke\r\n"
        "c=IN IP4 127.0.0.1\r\n"
        "t=0 0\r\n"
        "m=audio 40000 RTP/AVP 0\r\n"
        "a=rtpmap:0 PCMU/8000\r\n"
    )
    invite = (
        f"INVITE {to_uri} SIP/2.0\r\n"
        f"Via: SIP/2.0/UDP 127.0.0.1:5060;branch={branch};rport\r\n"
        "Max-Forwards: 70\r\n"
        f"From: <{from_uri}>;tag={from_tag}\r\n"
        f"To: <{to_uri}>\r\n"
        f"Call-ID: {call_id}\r\n"
        "CSeq: 1 INVITE\r\n"
        f"Contact: {contact}\r\n"
        "Content-Type: application/sdp\r\n"
        f"Content-Length: {len(sdp.encode('utf-8'))}\r\n"
        "\r\n"
        f"{sdp}"
    )

    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.settimeout(1.0)
        send(sock, target, invite)
        response_start = ""
        response_headers: dict[str, str] = {}
        response_body = ""
        deadline = time.monotonic() + 12
        while time.monotonic() < deadline:
            try:
                packet, _ = sock.recvfrom(65535)
            except socket.timeout:
                continue
            start, headers, body = parse_message(packet)
            if headers.get("call-id") != call_id:
                continue
            if start.startswith("SIP/2.0 401") or start.startswith("SIP/2.0 407") or start.startswith("SIP/2.0 403"):
                fail(f"plain INVITE received prohibited response: {start}")
            if start.startswith("SIP/2.0 200"):
                response_start, response_headers, response_body = start, headers, body
                break
            if not re.match(r"SIP/2.0 1(?:00|80|83)", start):
                fail(f"plain INVITE received unexpected response: {start}")
        if not response_start:
            fail("plain INVITE did not receive 200 OK")
        if response_headers.get("content-type", "").lower() != "application/sdp":
            fail("200 OK does not contain application/sdp")
        if not response_body.strip():
            fail("200 OK does not contain an SDP answer")

        remote_target = contact_uri(header(response_headers, "Contact"))
        to_header = header(response_headers, "To")
        route_headers = response_headers.get("record-route")
        routes = ""
        if route_headers:
            routes = "".join(f"Route: {route}\r\n" for route in reversed(route_headers.split(",")))
        ack_branch = f"z9hG4bK-utcp-ack-{uuid.uuid4().hex}"
        ack = (
            f"ACK {remote_target} SIP/2.0\r\n"
            f"Via: SIP/2.0/UDP 127.0.0.1:5060;branch={ack_branch};rport\r\n"
            "Max-Forwards: 70\r\n"
            f"From: <{from_uri}>;tag={from_tag}\r\n"
            f"To: {to_header}\r\n"
            f"Call-ID: {call_id}\r\n"
            "CSeq: 1 ACK\r\n"
            f"{routes}Content-Length: 0\r\n\r\n"
        )
        send(sock, target, ack)
        time.sleep(float(os.environ.get("UTCP_SIP_SMOKE_HOLD_SECONDS", "0.25")))
        bye_branch = f"z9hG4bK-utcp-bye-{uuid.uuid4().hex}"
        bye = (
            f"BYE {remote_target} SIP/2.0\r\n"
            f"Via: SIP/2.0/UDP 127.0.0.1:5060;branch={bye_branch};rport\r\n"
            "Max-Forwards: 70\r\n"
            f"From: <{from_uri}>;tag={from_tag}\r\n"
            f"To: {to_header}\r\n"
            f"Call-ID: {call_id}\r\n"
            "CSeq: 2 BYE\r\n"
            f"{routes}Content-Length: 0\r\n\r\n"
        )
        send(sock, target, bye)
        bye_deadline = time.monotonic() + 8
        while time.monotonic() < bye_deadline:
            try:
                packet, _ = sock.recvfrom(65535)
            except socket.timeout:
                continue
            start, headers, _ = parse_message(packet)
            if headers.get("call-id") == call_id and start.startswith("SIP/2.0 200"):
                print("FreeSWITCH SIP INVITE smoke test passed")
                return
        fail("BYE did not receive 200 OK")


if __name__ == "__main__":
    main()
