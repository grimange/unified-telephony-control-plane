#!/usr/bin/env python3
"""Read-only K2 preflight validator; no network, writes or automatic re-freeze."""
import collections
import hashlib
import json
import pathlib
import re
import runpy
import subprocess

HERE = pathlib.Path(__file__).resolve().parent
REPO = HERE.parents[4]
K1 = HERE.parent / 'k1-mapping-freeze'
def read(path):
    return json.loads(path.read_text())
def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()
def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()
def git(*args):
    return subprocess.check_output(['git', '-C', str(REPO), *args]).decode()
# Preserve every original check. Extend only its historical new-file scope to
# include this separately authorized K2 directory; never edit the frozen script.
source = (K1 / 'validate-packet.py').read_text()
old = "assert all(x.startswith(str(HERE.relative_to(REPO)) + '/') for x in after_untracked - before_untracked)"
new = "assert all(x.startswith(str(HERE.relative_to(REPO)) + '/') or x.startswith('docs/architecture/proposals/kaneo-tracker/k2-controlled-import/') for x in after_untracked - before_untracked)"
assert source.count(old) == 1
exec(compile(source.replace(old, new), str(K1 / 'validate-packet.py'), 'exec'), {'__file__': str(K1 / 'validate-packet.py'), '__name__': 'k1_scoped_validation'})
original = read(K1 / 'payload-preview.json')
packet = read(HERE / 'freeze-v2/payload-preview.json')
hashes = read(HERE / 'freeze-v2/payload-hashes.json')
assert digest(read(K1 / 'payload-hashes.json')) == 'ee7432eb43ab8380532c4a33ef9d1f8a91e12fce6a71ae7664fdcf1e0c721a05'
assert digest(hashes) == (HERE / 'freeze-v2/FREEZE_SHA256').read_text().strip() == 'd093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb'
assert digest(packet) == hashes['payload_packet_sha256']
for key in ['source_manifest_sha256', 'mapping_sha256', 'candidate_decisions_sha256']:
    assert hashes[key] == read(K1 / 'payload-hashes.json')[key]
assert {k:v for k,v in original.items() if k != 'tasks'} == {k:v for k,v in packet.items() if k != 'tasks'}
assert collections.Counter(t['create_arguments']['status'] for t in packet['tasks']) == {'to-do':7, 'in-progress':2, 'in-review':1}
for old, task, row in zip(original['tasks'], packet['tasks'], hashes['tasks']):
    if task['migration_key'] != 'utcp:go-native':
        assert task == old
    else:
        differences = {k for k in task if task[k] != old[k]}
        assert differences == {'column_id', 'status_dimensions', 'create_arguments', 'payload_sha256'}
        assert task['create_arguments']['status'] == 'in-progress'
        assert task['column_id'] == 'e35qmx2pn4pftmlhxr203ew4'
        assert {k:v for k,v in task['create_arguments'].items() if k not in {'description','status'}} == {k:v for k,v in old['create_arguments'].items() if k not in {'description','status'}}
        before = 'backlog / proposed; execution not granted by import'
        after = 'in progress: isolated GO-CP1 E1–E4 implemented and developer-verified; workstream incomplete; further execution not granted by import'
        assert task['create_arguments']['description'] == old['create_arguments']['description'].replace(before, after)
        assert task['status_dimensions'] == dict(old['status_dimensions'], operational_execution=after)
    assert digest(task['create_arguments']) == task['payload_sha256'] == row['payload_sha256']
    assert digest(task['source_hashes']) == task['source_set_sha256'] == row['source_set_sha256']
    assert set(task['create_arguments']) == {'projectId','title','description','status','priority'}
    assert task['create_arguments']['priority'] == 'no-priority'
for name in ['kaneo-before.json','kaneo-after.json']:
    snap = read(HERE / name)
    assert snap['tasks']['data']['id'] == packet['project_id']
    assert snap['tasks']['data']['workspaceId'] == packet['workspace_id']
    assert snap['tasks']['pagination']['total'] == 0
    assert snap['tasks']['pagination']['totalPages'] == 1
    assert snap['tasks']['data']['plannedTasks'] == snap['tasks']['data']['archivedTasks'] == []
    assert all(c['tasks'] == [] for c in snap['tasks']['data']['columns'])
journal = read(HERE / 'import-journal.json')
assert journal['writes'] == 0 and journal['approved_root_sha256'] is None
assert len(journal['operations']) == 19
assert all(x['attempt_number'] == 0 and x['invocation_result'] == 'NOT_INVOKED' and x.get('returned_task_id') is None for x in journal['operations'])
baseline = read(HERE / 'repository-baseline.json')
for path, expected in baseline['files'].items():
    assert sha(REPO / path) == expected, 'Preservation: ' + path
assert git('rev-parse','HEAD').strip() == baseline['head']
assert git('branch','--show-current').strip() == baseline['branch']
assert not git('diff','--cached','--name-only').strip()
status = git('status','--porcelain=v1','--untracked-files=all').splitlines()
previous = baseline['status'].splitlines()
assert [x for x in status if not x.startswith('??')] == [x for x in previous if not x.startswith('??')]
old_untracked = {x[3:] for x in previous if x.startswith('??')}
new_untracked = {x[3:] for x in status if x.startswith('??')}
assert old_untracked <= new_untracked
assert all(x.startswith(str(HERE.relative_to(REPO))+'/') for x in new_untracked-old_untracked)
inventory = read(HERE / 'publication-inventory.json')['records']
assert len(inventory) == 166 and len({x['path'] for x in inventory}) == 166
assert {x['path'] for x in inventory} == {x[3:] for x in previous}
plan = read(HERE / 'preservation-plan.json')
assert set(plan['existing_pending_allowlist']) == {x['path'] for x in inventory}
for record in inventory:
    assert sha(REPO / record['path']) == record['sha256'] == plan['existing_pending_allowlist'][record['path']]
    assert not record['secret_privacy_review']['repository_scanner_line_findings']
all_paths = set((HERE / 'approved-scope-candidate-paths.txt').read_text().splitlines())
foundation = set((HERE / 'foundation-candidate-paths.txt').read_text().splitlines())
go = set((HERE / 'go-tracker-candidate-paths.txt').read_text().splitlines())
assert len(foundation) == 17 and len(go) == 149 and not foundation & go
assert foundation | go == all_paths == set(plan['existing_pending_allowlist'])
scan = runpy.run_path(str(REPO / 'scripts/ci/secret-scan'))['SECRET_PATTERNS']
files = sorted(x for x in HERE.rglob('*') if x.is_file())
links = 0
for file in files:
    data = file.read_text()
    assert data.endswith('\n') and all(line == line.rstrip() for line in data.splitlines()), 'Whitespace: ' + str(file)
    if file.suffix == '.json':
        json.loads(data)
    assert not any(pattern.search(line) for line in data.splitlines() for pattern in scan), 'Secret: ' + str(file)
    if file.suffix == '.md':
        for target in re.findall(r'\]\(([^)]+)\)', data):
            if '://' not in target and not target.startswith('#'):
                assert (file.parent / target.split('#')[0]).exists(), (file.name, target)
                links += 1
changed = read(HERE / 'changed-files.json')
assert {x['path'] for x in changed['files']} == {str(f.relative_to(REPO)) for f in files}
for row in changed['files']:
    if row['sha256'] is not None:
        assert sha(REPO / row['path']) == row['sha256'], row['path']
    else:
        assert row['path'].endswith('/changed-files.json')
assert '**Activation: SELECTED_NOT_ACTIVE.**' in (REPO / 'docs/governance/KANEO_CANONICAL_TRACKER_POLICY.md').read_text()
print(f'PASS: successor root {digest(hashes)}; targeted parent-status correction; original freeze unchanged')
print(f'PASS: {len(baseline["files"])} entry files preserved, 166 publication records, 17+149 scoped candidates; no staging')
print(f'PASS: {len(files)} K2 files inspected, {links} local links, JSON/whitespace/secret patterns; remote empty, 19 unattempted operations, zero writes')
