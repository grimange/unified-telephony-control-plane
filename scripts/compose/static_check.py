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
    "reverb",
    "telephony-command-worker",
    "telephony-event-normalizer",
    "telephony-reconciler",
    "web",
    "gateway",
    "kamailio",
    "kamailio-registration-observer",
    "sip-gateway",
}
allowed_t1_services = {"kamailio", "kamailio-registration-observer", "sip-gateway"}
forbidden_terms = {"asterisk", "freeswitch", "rtpengine", "rtp", "pbx"}


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    sys.exit(1)


if set(services) != expected_services:
    fail(f"unexpected compose services: {sorted(services)}")

for name in services:
    lower_name = name.lower()
    if any(term == lower_name for term in forbidden_terms):
        fail(f"telephony/media service is out of scope for disposable T1-F compose: {name}")

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
    "reverb": {"platform", "data"},
    "telephony-command-worker": {"data"},
    "telephony-event-normalizer": {"data"},
    "telephony-reconciler": {"data"},
    "kamailio-registration-observer": {"data"},
    "kamailio": {"platform", "data"},
    "sip-gateway": {"edge", "platform"},
    "migrate": {"data"},
    "postgres": {"data"},
    "redis": {"data"},
}

for service, expected in expected_networks.items():
    actual = set(services[service].get("networks", {}).keys())
    if actual != expected:
        fail(f"{service} networks mismatch: expected {sorted(expected)}, got {sorted(actual)}")

for service in ("postgres", "redis", "api", "reverb", "web", "gateway"):
    if "healthcheck" not in services[service]:
        fail(f"{service} is missing a healthcheck")

if services["postgres"].get("ports"):
    fail("postgres must not publish host ports")

if services["redis"].get("ports"):
    fail("redis must not publish host ports")

if services["reverb"].get("ports"):
    fail("reverb must not publish host ports")

if services["kamailio"].get("ports"):
    fail("kamailio must not publish host ports")

if services["kamailio-registration-observer"].get("ports"):
    fail("kamailio registration observer must not publish host ports")

gateway_ports = services["gateway"].get("ports", [])
if len(gateway_ports) != 1:
    fail("gateway must publish exactly one host port")

sip_gateway_ports = services["sip-gateway"].get("ports", [])
if len(sip_gateway_ports) != 1:
    fail("sip-gateway must publish exactly one WSS host port")
for port in sip_gateway_ports:
    published = str(port.get("published", ""))
    target = str(port.get("target", ""))
    if published in {"80", "443", "5060", "5061"}:
        fail(f"sip-gateway must not publish reserved edge or SIP ports: {published}")
    if target != "8443":
        fail(f"sip-gateway must terminate WSS on internal 8443, got {target}")

for service in (
    "api",
    "worker",
    "scheduler",
    "control-plane-outbox-dispatcher",
    "reverb",
    "telephony-command-worker",
    "telephony-event-normalizer",
    "telephony-reconciler",
    "kamailio-registration-observer",
    "migrate",
):
    if services[service].get("image") != "utcp-api:dev":
        fail(f"{service} must use the utcp-api image")

expected_commands = {
    "api": ["api"],
    "worker": ["worker"],
    "scheduler": ["scheduler"],
    "control-plane-outbox-dispatcher": ["control-plane-outbox-dispatcher"],
    "reverb": ["reverb"],
    "telephony-command-worker": ["telephony-command-worker"],
    "telephony-event-normalizer": ["telephony-event-normalizer"],
    "telephony-reconciler": ["telephony-reconciler"],
    "kamailio-registration-observer": ["kamailio-registration-observer"],
    "migrate": ["migrate"],
}
for service, expected in expected_commands.items():
    if services[service].get("command") != expected:
        fail(f"{service} command mismatch")

if services["web"].get("image") != "utcp-web:dev":
    fail("web must use the utcp-web image")

if services["gateway"].get("image") != "utcp-gateway:dev":
    fail("gateway must use the utcp-gateway image")

if services["kamailio"].get("image") != "ghcr.io/kamailio/kamailio:5.8.6-bookworm":
    fail("kamailio must use the pinned repository version")

if services["sip-gateway"].get("image") != "nginxinc/nginx-unprivileged:1.29.4-alpine":
    fail("sip-gateway must use the pinned unprivileged nginx runtime")

kamailio_text = json.dumps(services["kamailio"]).lower()
for required in ("calculate_ha1", "max_expires", "auth_db", "usrloc", "registrar"):
    if required not in kamailio_text:
        fail(f"kamailio configuration must preserve {required}")
for forbidden in ("asterisk", "rtpengine", "rtp", "invite", "freeswitch"):
    if forbidden in kamailio_text and forbidden != "invite":
        fail(f"kamailio compose configuration includes forbidden scope: {forbidden}")

for service in ("api", "worker", "scheduler"):
    environment = services[service].get("environment", {})
    readiness = environment.get("UTCP_READINESS_REQUIRED_DEPENDENCIES")
    if readiness != "postgres,redis":
        fail(f"{service} readiness dependencies must require postgres and redis")
    if environment.get("QUEUE_CONNECTION") != "redis":
        fail(f"{service} queue connection must use redis")
    if environment.get("CACHE_STORE") != "redis":
        fail(f"{service} cache store must use redis")

for service in (
    "api",
    "worker",
    "control-plane-outbox-dispatcher",
    "telephony-command-worker",
    "telephony-event-normalizer",
    "telephony-reconciler",
    "reverb",
):
    environment = services[service].get("environment", {})
    if environment.get("BROADCAST_CONNECTION") != "reverb":
        fail(f"{service} broadcast connection must use reverb")

reverb_environment = services["reverb"].get("environment", {})
for key in ("REVERB_APP_ID", "REVERB_APP_KEY", "REVERB_APP_SECRET"):
    if not reverb_environment.get(key):
        fail(f"reverb is missing {key}")

for service_name, service in services.items():
    if service.get("network_mode") == "host":
        fail(f"{service_name} must not use host networking")
    if service.get("privileged"):
        fail(f"{service_name} must not run privileged")

    text = json.dumps(service).lower()
    for term in forbidden_terms:
        if term in text:
            fail(f"{service_name} contains out-of-scope telephony/media coupling: {term}")

print("compose static checks passed")
