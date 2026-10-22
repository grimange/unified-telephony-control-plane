#!/usr/bin/env python3
"""Read-only K5 control-scope, preservation, evidence and documentation validation."""
from pathlib import Path
import collections
import hashlib
import json
import re
import runpy
import subprocess

P = Path(__file__).resolve().parent
R = P.parents[4]


def read(path):
    return json.loads(path.read_text())


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()


a = read(P / 'activation-record.json')
assert a['assignment'] == 'UTCP_KANEO_K5_CANONICAL_TRACKER_ACTIVATION'
assert a['operator_authorization'] == a['assignment']
assert a['transitions'] == [
    ['MIGRATING', 'RECONCILED_PENDING_ACTIVATION'],
    ['RECONCILED_PENDING_ACTIVATION', 'ACTIVE'],
]
assert a['final_state'] == 'ACTIVE' and a['kaneo_writes'] == 0
assert subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip() == a['baseline']['head']
assert subprocess.check_output(['git', 'branch', '--show-current'], text=True).strip() == a['baseline']['branch']
assert not subprocess.check_output(['git', 'diff', '--cached', '--name-only'], text=True).strip()
owned = set(a['control_changes'])
assert owned == {'AGENTS.md', 'CLAUDE.md', 'docs/AGENTS.md', 'docs/roadmap/phase-status.md', 'docs/governance/KANEO_CANONICAL_TRACKER_POLICY.md'}
for path, original in a['baseline']['files'].items():
    if path not in owned:
        assert sha(R / path) == original, path
for path, row in a['control_changes'].items():
    assert hashlib.sha256(row['before_content'].encode()).hexdigest() == row['before_sha256'] == a['baseline']['files'][path]
    assert sha(R / path) == row['after_sha256'], path
    assert '**ACTIVE**' in (R / path).read_text() or '**Activation: ACTIVE.**' in (R / path).read_text()
policy = 'docs/governance/KANEO_CANONICAL_TRACKER_POLICY.md'
before = a['control_changes'][policy]['before_content']
current = (R / policy).read_text()
assert '**Activation: MIGRATING.**' in before
assert hashlib.sha256(a['intermediate_policy_content'].encode()).hexdigest() == a['intermediate_policy_sha256']
assert '**Activation: RECONCILED_PENDING_ACTIVATION.**' in a['intermediate_policy_content']
assert current.index('### 2026-10-22 — accepted K4') < current.index('### 2026-10-22 — operator-authorized K5')
assert 'K6 is not\na prerequisite' in current
for path, start, end in [
    ('AGENTS.md', '## Canonical operational tracker', '## Architecture invariants'),
    ('CLAUDE.md', '## Canonical task tracker', '## Mission'),
    ('docs/AGENTS.md', 'The [canonical tracker policy]', 'Resolve conflicts'),
    ('docs/roadmap/phase-status.md', 'Tracker governance:', '**T1 Kamailio'),
]:
    old = a['control_changes'][path]['before_content']
    new = (R / path).read_text()
    assert old.split(start)[0] == new.split(start)[0]
    assert old.split(end, 1)[1] == new.split(end, 1)[1], path
    assert 'SELECTED_NOT_ACTIVE' not in new.split(start, 1)[1].split(end, 1)[0]
q = P.parent / 'k4-reconciliation'
for path, expected in a['accepted_evidence'].items():
    assert sha(R / path) == expected, path
k4 = read(q / 'reconciliation.json')
assert k4['verdict'] == 'UTCP_KANEO_K4_RECONCILIATION_VERIFIED_READY_FOR_ACTIVATION'
f = a['fresh_identity_check']
assert f['project']['id'] == 'hrilr4371g33s0iw6lth3o7v' and f['project']['workspaceId'] == '30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il'
assert f['project']['slug'] == 'UTCP' and f['workspaces'][0]['name'] == 'Personal Projects'
assert f['pagination']['totalPages'] == f['pagination']['relatedTotalPages'] == 1
assert len(f['tasks']) == len({t['key'] for t in f['tasks']}) == 10
assert {t['key']: (t['id'], t['ticket'], t['status']) for t in f['tasks']} == {t['migration_key']: (t['task_id'], t['ticket'], t['status']) for t in k4['tasks']}
assert f['planned_count'] == f['archived_count'] == f['writes'] == 0
k2 = P.parent / 'k2-controlled-import'
h = read(k2 / 'freeze-v2/payload-hashes.json')
assert digest(h) == a['approved_import_digest'] == 'd093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb'
payload = read(k2 / 'freeze-v2/payload-preview.json')
assert digest(payload) == h['payload_packet_sha256']
sources = read(P.parent / 'k1-mapping-freeze/source-hashes.json')
assert digest(sources) == h['source_manifest_sha256']
for path, expected in sources['files'].items():
    if path == policy:
        auth = read(k2 / 'execution-authorization.json')
        assert hashlib.sha256(auth['policy_before_content'].encode()).hexdigest() == expected
        assert auth['policy_after_sha256'] == a['control_changes'][policy]['before_sha256']
    elif path in owned:
        assert a['control_changes'][path]['before_sha256'] == expected
    else:
        assert sha(R / path) == expected, path
j = read(k2 / 'execution-journal.json')
assert len(j['operations']) == 19 and all(o['state'] == 'VERIFIED' and digest(o['arguments']) == o['arguments_sha256'] for o in j['operations'])
scan = runpy.run_path(str(R / 'scripts/ci/secret-scan'))['SECRET_PATTERNS']
new = set(subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard'], text=True).splitlines()) - {x[3:] for x in a['baseline']['status'].splitlines() if x.startswith('??')}
assert new == set(a['new_files'])
for name in [*owned, *new]:
    path = R / name
    text = path.read_text()
    assert text.endswith('\n') and all(line == line.rstrip() for line in text.splitlines()), name
    assert not any(pattern.search(line) for line in text.splitlines() for pattern in scan), name
    if path.suffix == '.json':
        json.loads(text)
    if path.suffix == '.md':
        for target in re.findall(r'\]\(([^)]+)\)', text):
            if '://' not in target and not target.startswith('#'):
                assert (path.parent / target.split('#')[0]).exists(), (name, target)
for path, expected in a['new_files'].items():
    if expected is not None:
        assert sha(R / path) == expected, path
print(json.dumps({'verdict': 'UTCP_KANEO_K5_CANONICAL_TRACKER_ACTIVE', 'preserved_entry_files': len(a['baseline']['files']) - len(owned), 'authorized_control_changes': len(owned), 'new_files': len(new), 'task_identities': 10, 'governance': 'ACTIVE', 'kaneo_writes': 0, 'phase_classifications_unchanged': True}, indent=2))
