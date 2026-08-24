#!/usr/bin/env python3
"""Validate managed-runtime image projections in rendered application ConfigMaps."""

from __future__ import annotations

import re
import sys
from pathlib import Path
from typing import Any

import yaml


def fail(message: str) -> None:
    print(f"Kubernetes config check failed: {message}", file=sys.stderr)
    raise SystemExit(1)


def objects(path: str) -> dict[str, Any]:
    rendered = {}
    for document in yaml.safe_load_all(Path(path).read_text(encoding="utf-8")):
        if not isinstance(document, dict):
            continue
        metadata = document.get("metadata") or {}
        key = f"{document.get('kind')}/{metadata.get('namespace')}/{metadata.get('name')}"
        rendered[key] = document
    return rendered


def application_config(label: str, rendered_objects: dict[str, Any]) -> dict[str, Any]:
    config = rendered_objects.get("ConfigMap/utcp-platform/utcp-application-config")
    if not config:
        fail(f"{label} overlay must render the platform application ConfigMap")
    return config.get("data") or {}


def validate_image(label: str, key: str, config_data: dict[str, Any]) -> str:
    image = config_data.get(key)
    if not image:
        fail(f"{label} application ConfigMap must define {key}")
    if any(character.isspace() for character in image):
        fail(f"{label} {key} must not contain whitespace")
    if "/" not in image or (":" not in image.rsplit("/", 1)[-1] and "@sha256:" not in image):
        fail(f"{label} {key} must be a qualified image with an explicit tag or digest")
    return image


local = application_config("local", objects(sys.argv[1]))
platform = application_config("platform", objects(sys.argv[2]))

for key in ("UTCP_MANAGED_ASTERISK_IMAGE", "UTCP_MANAGED_FREESWITCH_IMAGE"):
    local_image = validate_image("local", key, local)
    platform_image = validate_image("platform", key, platform)
    if local_image != platform_image:
        fail(f"{key} differs between local and platform application projections")

freeswitch_image = local["UTCP_MANAGED_FREESWITCH_IMAGE"]
if not re.fullmatch(r"[^/]+/utcp/freeswitch@sha256:[0-9a-f]{64}", freeswitch_image):
    fail("UTCP_MANAGED_FREESWITCH_IMAGE must be an immutable utcp/freeswitch sha256 reference")

print("Kubernetes managed image projection check passed")
