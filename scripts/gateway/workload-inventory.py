#!/usr/bin/env python3
"""Classify supported UTCP runtime workloads from Kubernetes JSON."""

import json
import sys


RUNTIME_COMPONENTS = {
    "asterisk-ari": "asterisk-ari",
    "freeswitch-esl": "freeswitch-esl",
}
SUPPORTED_KINDS = {"Deployment", "Pod"}


def resource_identity(resource):
    metadata = resource.get("metadata")
    if not isinstance(metadata, dict):
        return "Unknown/unknown"

    return f"{resource.get('kind', 'Unknown')}/{metadata.get('name', 'unknown')}"


def invalid_reason(resource):
    identity = resource_identity(resource)
    kind = resource.get("kind")
    metadata = resource.get("metadata")
    labels = metadata.get("labels") if isinstance(metadata, dict) else None

    if kind not in SUPPORTED_KINDS:
        return f"{identity}: unsupported workload kind"
    if not isinstance(labels, dict):
        return f"{identity}: missing canonical runtime labels"
    if labels.get("app.kubernetes.io/part-of") != "utcp":
        return f"{identity}: missing app.kubernetes.io/part-of=utcp"

    component = labels.get("app.kubernetes.io/component")
    if component not in RUNTIME_COMPONENTS:
        return f"{identity}: unknown runtime component"
    runtime_node = labels.get("utcp.dev/runtime-node")
    if not isinstance(runtime_node, str) or not runtime_node.strip():
        return f"{identity}: missing utcp.dev/runtime-node"
    if kind == "Pod" and labels.get("utcp.io/network-role") != RUNTIME_COMPONENTS[component]:
        return f"{identity}: runtime component/network-role mismatch"

    return None


def main():
    try:
        document = json.load(sys.stdin)
    except (json.JSONDecodeError, TypeError) as exc:
        print(f"invalid Kubernetes workload JSON: {exc}", file=sys.stderr)
        return 2

    resources = document.get("items") if isinstance(document, dict) else None
    if not isinstance(resources, list):
        print("invalid Kubernetes workload JSON: expected a List with items", file=sys.stderr)
        return 2

    invalid = []
    for resource in resources:
        invalid.append(invalid_reason(resource) if isinstance(resource, dict) else "Unknown/unknown: malformed workload resource")
    invalid = [reason for reason in invalid if reason is not None]
    if invalid:
        print("\n".join(invalid))
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
