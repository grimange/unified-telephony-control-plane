#!/usr/bin/env python3
import json
import sys


data = json.load(sys.stdin)
services = data.get("services", {})
networks = data.get("networks", {})
volumes = data.get("volumes", {})

expected_services = {
    "postgres",
    "redis",
    "migrate",
    "api",
    "worker",
    "scheduler",
    "control-plane-outbox-dispatcher",
    "telephony-command-worker",
    "telephony-event-normalizer",
    "telephony-reconciler",
    "web",
    "gateway",
}
telephony_terms = {"asterisk", "freeswitch", "kamailio", "rtpengine", "sip", "rtp", "pbx"}


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    sys.exit(1)


if set(services) != expected_services:
    fail(f"unexpected compose services: {sorted(services)}")

for name in services:
    lower_name = name.lower()
    if any(term == lower_name for term in telephony_terms):
        fail(f"telephony service is out of scope for F3: {name}")

for name in ("edge", "platform", "data"):
    if name not in networks:
        fail(f"missing network: {name}")

for name in ("postgres-data", "redis-data"):
    if name not in volumes:
        fail(f"missing named volume: {name}")

expected_networks = {
    "gateway": {"edge", "platform"},
    "web": {"platform"},
    "api": {"platform", "data"},
    "worker": {"data"},
    "scheduler": {"data"},
    "control-plane-outbox-dispatcher": {"data"},
    "telephony-command-worker": {"data"},
    "telephony-event-normalizer": {"data"},
    "telephony-reconciler": {"data"},
    "migrate": {"data"},
    "postgres": {"data"},
    "redis": {"data"},
}

for service, expected in expected_networks.items():
    actual = set(services[service].get("networks", {}).keys())
    if actual != expected:
        fail(f"{service} networks mismatch: expected {sorted(expected)}, got {sorted(actual)}")

for service in ("postgres", "redis", "api", "web", "gateway"):
    if "healthcheck" not in services[service]:
        fail(f"{service} is missing a healthcheck")

if services["postgres"].get("ports"):
    fail("postgres must not publish host ports")

if services["redis"].get("ports"):
    fail("redis must not publish host ports")

gateway_ports = services["gateway"].get("ports", [])
if len(gateway_ports) != 1:
    fail("gateway must publish exactly one host port")

for service in (
    "api",
    "worker",
    "scheduler",
    "control-plane-outbox-dispatcher",
    "telephony-command-worker",
    "telephony-event-normalizer",
    "telephony-reconciler",
    "migrate",
):
    if services[service].get("image") != "utcp-api:dev":
        fail(f"{service} must use the utcp-api image")

expected_commands = {
    "api": ["api"],
    "worker": ["worker"],
    "scheduler": ["scheduler"],
    "control-plane-outbox-dispatcher": ["control-plane-outbox-dispatcher"],
    "telephony-command-worker": ["telephony-command-worker"],
    "telephony-event-normalizer": ["telephony-event-normalizer"],
    "telephony-reconciler": ["telephony-reconciler"],
    "migrate": ["migrate"],
}
for service, expected in expected_commands.items():
    if services[service].get("command") != expected:
        fail(f"{service} command mismatch")

if services["web"].get("image") != "utcp-web:dev":
    fail("web must use the utcp-web image")

if services["gateway"].get("image") != "utcp-gateway:dev":
    fail("gateway must use the utcp-gateway image")

for service in ("api", "worker", "scheduler"):
    environment = services[service].get("environment", {})
    readiness = environment.get("UTCP_READINESS_REQUIRED_DEPENDENCIES")
    if readiness != "postgres,redis":
        fail(f"{service} readiness dependencies must require postgres and redis")
    if environment.get("QUEUE_CONNECTION") != "redis":
        fail(f"{service} queue connection must use redis")
    if environment.get("CACHE_STORE") != "redis":
        fail(f"{service} cache store must use redis")

for service_name, service in services.items():
    if service.get("network_mode") == "host":
        fail(f"{service_name} must not use host networking")
    if service.get("privileged"):
        fail(f"{service_name} must not run privileged")

    text = json.dumps(service).lower()
    for term in telephony_terms:
        if term in text:
            fail(f"{service_name} contains out-of-scope telephony coupling: {term}")

print("compose static checks passed")
