#!/usr/bin/env python3
"""Read-only local frozen-packet validation. No network, remote writes or file output."""
import collections
import hashlib
import json
import pathlib
import re
import runpy
import subprocess
import sys

HERE = pathlib.Path(__file__).resolve().parent
REPO = HERE.parents[4]
PROJECT = 'hrilr4371g33s0iw6lth3o7v'
WORKSPACE = '30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il'

def read(name):
    return json.loads((HERE / name).read_text())

def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode('utf-8')

def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def unique(values):
    assert len(values) == len(set(values)), 'Duplicate identity'

def git(*args):
    return subprocess.check_output(['git', '-C', str(REPO), *args]).decode()

def acyclic(edges):
    graph = collections.defaultdict(list)
    for a, b in edges:
        graph[a].append(b)
    active, done = set(), set()
    def visit(node):
        assert node not in active, f'Dependency cycle: {node}'
        if node in done:
            return
        active.add(node)
        for child in graph[node]:
            visit(child)
        active.remove(node)
        done.add(node)
    for node in list(graph):
        visit(node)

p = read('payload-preview.json')
if sys.argv[1:] == ['--canonical-payload']:
    sys.stdout.buffer.write(canonical(p))
    raise SystemExit(0)
assert not sys.argv[1:], 'Usage: validate-packet.py [--canonical-payload]'
h = read('payload-hashes.json')
b = read('repository-baseline.json')
s = read('kaneo-read-snapshot.json')
m = read('source-task-mapping.json')
f = read('source-hashes.json')
decisions = read('candidate-decisions.json')
refresh = read('source-refresh.json')
assert digest(h) == (HERE / 'FREEZE_SHA256').read_text().strip()
assert digest(p) == h['payload_packet_sha256']
assert digest(f) == h['source_manifest_sha256']
assert digest(m) == h['mapping_sha256']
assert digest(decisions) == h['candidate_decisions_sha256']
assert digest(refresh['catalog_sources']) == f['catalog_refresh_sha256']
assert p['activation'] == 'SELECTED_NOT_ACTIVE' and p['kaneo_writes'] == 0 and p['dry_run']
assert p['project_id'] == PROJECT == s['project']['id'] == m['project_id'] == h['project_id']
assert p['workspace_id'] == WORKSPACE == s['project']['workspaceId'] == s['workspace']['id']
assert s['project']['slug'] == 'UTCP' and not s['project']['isPublic'] and not s['project']['archivedAt']
assert s['writes'] == 0 and len(s['read_calls']) == 7
assert s['search']['totalCount'] == 0
assert s['tasks']['pagination'] == {'total': 0, 'page': 1, 'pageSize': 100, 'totalPages': 1, 'relatedPage': 1, 'relatedPageSize': 100, 'relatedTotalPages': 1}
remote = s['tasks']['data']
assert remote['plannedTasks'] == remote['archivedTasks'] == []
assert all(c['tasks'] == [] for c in remote['columns'])
assert not remote['descriptionDeferred']
cols = {c['slug']: c['id'] for c in s['columns']}
assert set(cols) == {'to-do', 'in-progress', 'in-review', 'done'}
labels = {x['id'] for x in s['labels'] if x['taskId'] is None and not x['deletionStartedAt']}
assert len(labels) == 4
assert len(decisions) == 13
assert collections.Counter(d['decision'] for d in decisions) == {'INCLUDE': 10, 'MERGE INTO PARENT': 2, 'DEFER': 1}
assert len(m['records']) == 152
unique([r['source_id'] for r in m['records']])
unique([r['migration_key'] for r in m['records']])
unique(m['reserved_remote_keys'])
assert len(m['reserved_remote_keys']) == 26
ids = {r['source_id'] for r in m['records']}
old = json.loads((HERE.parent / 'inventory-records.json').read_text())
assert {r['source_id'] for r in old}.issubset(ids)
assert len(refresh['catalog_sources']) == 499
assert collections.Counter(x['result'] for x in refresh['catalog_sources']) == {'UNCHANGED': 495, 'AUTHORIZED_GOVERNANCE_E1_CHANGE': 4}
assert len(f['files']) == 505
for path, expected in f['files'].items():
    assert sha(REPO / path) == expected, f'Stale source: {path}'
for row in refresh['catalog_sources']:
    assert f['files'][row['path']] == row['current_sha256']
units = p['tasks']
assert len(units) == len(h['tasks']) == 10
keys = {u['migration_key'] for u in units}
unique([u['migration_key'] for u in units])
assert [u['import_order'] for u in units] == list(range(1, 11))
assert collections.Counter(u['create_arguments']['status'] for u in units) == {'to-do': 8, 'in-progress': 1, 'in-review': 1}
assert not p['existing_task_updates'] and not p['label_creates']
# Verify payload field contract against the captured actual declaration.
schemas = {x['name']:x['description'] for x in s['tools']}
assert 'priority: "no-priority"' in schemas['mcp__kaneo_local__create_task']
assert 'status: string' in schemas['mcp__kaneo_local__create_task']
seen = set()
for u, row in zip(units, h['tasks']):
    k = u['migration_key']
    assert k == row['migration_key']
    assert u['parent_key'] is None or u['parent_key'] in seen
    seen.add(k)
    assert u['project_id'] == PROJECT and u['column_id'] == cols[u['create_arguments']['status']]
    assert u['existing_task_id'] is None and u['proposed_action'] == 'CREATE'
    args = u['create_arguments']
    assert set(args) == {'description', 'priority', 'projectId', 'status', 'title'}
    assert args['projectId'] == PROJECT and args['priority'] == 'no-priority'
    assert args['description'].splitlines()[0] == 'UTCP-Migration-Key: ' + k
    assert len(re.findall(r'^UTCP-Migration-Key: ', args['description'], re.M)) == 1
    assert digest(args) == u['payload_sha256'] == row['payload_sha256']
    assert digest(u['source_hashes']) == u['source_set_sha256'] == row['source_set_sha256'] == f['unit_source_sets'][k]
    for path, expected in u['source_hashes'].items():
        assert f['files'][path] == expected
    assert set(u['source_ids']) == {r['source_id'] for r in m['records'] if r['target_key'] == k}
    assert set(u['label_ids']).issubset(labels)
    for reference in u['source_references']:
        assert reference['sha256'] == u['source_hashes'][reference['path']]
        if reference['publication_required']:
            assert reference['immutable_url'] is None
        else:
            content = subprocess.check_output(['git', '-C', str(REPO), 'show', b['head'] + ':' + reference['path']])
            assert hashlib.sha256(content).hexdigest() == reference['sha256']
    dimensions = u['status_dimensions']
    assert {'operational_execution', 'implementation', 'developer_verification', 'independent_review', 'operator_acceptance', 'architecture_adoption', 'production_deployment', 'formal_phase_release'}.issubset(dimensions)
    assert dimensions['independent_review'] == dimensions['operator_acceptance'] == 'not established for this task scope'
    assert 'execution not granted' in dimensions['operational_execution'] or k in {'utcp:rma', 'utcp:go-cp1'}
    for edge in u['dependencies']:
        assert edge['source_id'] in ids and edge['target_id'] in ids
for r in m['records']:
    assert r['proposed_action'] in {'CREATE', 'DEFER', 'LINK_ONLY', 'NO_REMOTE_ACTION'}
    assert r['existing_task_id'] is None
    assert r['target_key'] is None or r['target_key'] in keys
    for path, expected in r['source_hashes'].items():
        assert f['files'][path] == expected
    for dep in r.get('dependencies', []):
        assert dep in ids
    for path in r.get('evidence_links', []):
        assert (REPO / path).is_file(), path
attachments = p['label_attachments']
assert len(attachments) == 4
unique([(x['migration_key'], x['label_id']) for x in attachments])
for x in attachments:
    assert x['migration_key'] in keys and x['label_id'] in labels
    assert x['argument_template'] == {'labelId': x['label_id'], 'taskId': {'resolve_verified_task_id': x['migration_key']}}
relations = p['relationships']
assert len(relations) == 5 and collections.Counter(r['relationType'] for r in relations) == {'subtask': 4, 'related': 1}
unique([(x['relationType'], x['source_key'], x['target_key']) for x in relations])
for r in relations:
    assert r['source_key'] in keys and r['target_key'] in keys and r['source_key'] != r['target_key']
    assert r['argument_template'] == {'relationType':r['relationType'], 'sourceTaskId':{'resolve_verified_task_id':r['source_key']}, 'targetTaskId':{'resolve_verified_task_id':r['target_key']}}
acyclic([(r['source_key'], r['target_key']) for r in relations if r['relationType'] in {'subtask', 'blocks'}])
for u in units:
    parents = [r['source_key'] for r in relations if r['relationType'] == 'subtask' and r['target_key'] == u['migration_key']]
    assert parents == ([u['parent_key']] if u['parent_key'] else [])
preview = json.loads((HERE.parent / 'migration-preview.json').read_text())
acyclic([(x['source_id'], x['target_id']) for x in preview['dependencies'] if x['type'] in {'hard-prerequisite', 'future-prerequisite', 'implementation-baseline'}])
for path, expected in b['files'].items():
    assert sha(REPO / path) == expected, f'Preservation failure: {path}'
assert git('branch', '--show-current').strip() == b['branch']
assert git('rev-parse', 'HEAD').strip() == b['head']
assert not git('diff', '--cached', '--name-only').strip()
status = git('status', '--porcelain=v1', '--untracked-files=all')
assert [x for x in status.splitlines() if not x.startswith('??')] == [x for x in b['status'].splitlines() if not x.startswith('??')]
before_untracked = {x[3:] for x in b['status'].splitlines() if x.startswith('??')}
after_untracked = {x[3:] for x in status.splitlines() if x.startswith('??')}
assert before_untracked <= after_untracked
assert all(x.startswith(str(HERE.relative_to(REPO)) + '/') for x in after_untracked - before_untracked)
# Validate all newly created files, not just tracked Git diff.
scan = runpy.run_path(str(REPO / 'scripts/ci/secret-scan'))['SECRET_PATTERNS']
links = 0
for file in sorted(HERE.iterdir()):
    assert file.is_file()
    data = file.read_text()
    assert data.endswith('\n') and all(line == line.rstrip() for line in data.splitlines()), f'Whitespace: {file.name}'
    if file.suffix == '.json':
        json.loads(data)
    assert not any(pattern.search(line) for line in data.splitlines() for pattern in scan), f'Secret scan: {file.name}'
    if file.suffix == '.md':
        for target in re.findall(r'\]\(([^)]+)\)', data):
            if '://' not in target and not target.startswith('#'):
                assert (file.parent / target.split('#')[0]).exists(), (file.name, target)
                links += 1
policy = (REPO / 'docs/governance/KANEO_CANONICAL_TRACKER_POLICY.md').read_text()
assert '**Activation: SELECTED_NOT_ACTIVE.**' in policy
assert 'RMA_E_S3_COMPATIBLE_ARCHIVE_ADAPTER_AND_DETERMINISTIC_MINIO_PROOF_IMPLEMENTATION_DESIGN' in (REPO / 'docs/roadmap/phase-status.md').read_text()
print(f'PASS: 10 task payloads, 4 label attachments, 5 relations; hashes and canonical serialization verified; root {digest(h)}')
print('PASS: 152 unique source identities, 26 reserved remote keys, 505 current source hashes; 499 catalog artifacts reconciled; hard graph acyclic')
print(f'PASS: {len(b["files"])} baseline files and all 143 starting untracked artifacts preserved; {len(list(HERE.iterdir()))} K1 files; {links} document links; no staged changes')
print('PASS: exact empty Kaneo pagination snapshot; no remote writes; SELECTED_NOT_ACTIVE; no fabricated owners/dates/acceptance/Done; new-file secret scan clean')
