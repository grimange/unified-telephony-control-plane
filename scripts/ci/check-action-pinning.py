#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path


WORKFLOW_ROOT = Path(".github/workflows")
PINNED_REF = re.compile(r"^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+@[a-f0-9]{40}$")
LOCAL_ACTION = re.compile(r"^\./")
FORBIDDEN_FLOATING = {"main", "master", "latest"}


def previous_non_empty(lines: list[str], index: int) -> str:
    for previous in range(index - 1, -1, -1):
        text = lines[previous].strip()
        if text:
            return text
    return ""


def main() -> int:
    failures: list[str] = []
    workflows = sorted(WORKFLOW_ROOT.glob("*.yml")) + sorted(WORKFLOW_ROOT.glob("*.yaml"))
    if not workflows:
        failures.append("no GitHub Actions workflows found")

    for path in workflows:
        lines = path.read_text(encoding="utf-8").splitlines()
        for index, line in enumerate(lines):
            match = re.search(r"\buses:\s*([^#\s]+)", line)
            if not match:
                continue

            reference = match.group(1).strip("\"'")
            if LOCAL_ACTION.match(reference):
                continue

            if "@" not in reference:
                failures.append(f"{path}:{index + 1}: action reference has no @ ref: {reference}")
                continue

            ref = reference.rsplit("@", 1)[1]
            if ref in FORBIDDEN_FLOATING or ref.startswith("v") or not PINNED_REF.match(reference):
                failures.append(f"{path}:{index + 1}: action must be pinned to a full commit SHA: {reference}")

            comment = previous_non_empty(lines, index)
            if not comment.startswith("#") or "release:" not in comment.lower():
                failures.append(f"{path}:{index + 1}: pinned action must be preceded by a release comment")

    if failures:
        for failure in failures:
            print(failure, file=sys.stderr)
        return 1

    print("GitHub Actions references are pinned to immutable commit SHAs")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
