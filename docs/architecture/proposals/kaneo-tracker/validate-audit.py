#!/usr/bin/env python3
"""Read-only audit integrity checks. No infrastructure or remote operations."""
import hashlib
import importlib.util
import json
import pathlib
import re
import subprocess
import urllib.parse

HERE = pathlib.Path(__file__).resolve().parent
REPO = HERE.parents[3]

def read(name):
    return json.loads((HERE / name).read_text())

def unique(values, message):
    assert len(values) == len(set(values)), message

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

records = read('inventory-records.json')
sources = read('source-catalog.json')
preview = read('migration-preview.json')
baseline = read('repository-baseline.json')
ids = {r['source_id'] for r in records}
unique([r['source_id'] for r in records], 'duplicate source ID')
unique([r['migration_key'] for r in records], 'duplicate source migration key')
unique([s['path'] for s in sources], 'duplicate source path')
units = {u['migration_key']:u for u in preview['units']}
assert len(units) == len(preview['units']), 'duplicate remote key'
assert preview['dry_run'] and preview['kaneo_writes'] == 0
assert preview['existing_task_count'] == 0 and preview['existing_matches'] == []
assert preview['project']['id'] == 'hrilr4371g33s0iw6lth3o7v'
assert preview['workspace']['id'] == '30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il'
for path, expected in baseline['hashes'].items():
    assert sha(REPO / path) == expected, f'baseline changed: {path}'
for source in sources:
    assert sha(REPO / source['path']) == source['sha256'], f'source changed: {source["path"]}'
for record in records:
    assert record['disposition'] in {'M1','M2','M3','M4','M5','M6'}
    assert all(d in ids for d in record['dependencies'])
    assert record['target_key'] is None or record['target_key'] in units
    for path in record['evidence_links']:
        assert (REPO / path).exists(), f'missing evidence: {path}'
    for path, expected in record['source_hashes'].items():
        assert sha(REPO / path) == expected
columns = {c['slug'] for c in preview['columns']}
for unit in units.values():
    assert unit['status'] in columns
    assert unit['existing_task_id'] is None and unit['operator_approval_required']
    assert unit['priority'] == 'no-priority' and unit['assignee'] is None and unit['due_date'] is None
    assert unit['parent_key'] is None or unit['parent_key'] in units
    assert all(s in ids for s in unit['source_ids'])
    for key in ['source_status','evidence_classification','independent_review','operator_acceptance','production_state']:
        assert all(key in dimension for dimension in unit['status_dimensions'])
# Only hard implementation edges form the implementation DAG; acceptance/context are typed separately.
graph = {i:[] for i in ids}
for edge in preview['dependencies']:
    assert edge['source_id'] in ids and edge['target_id'] in ids
    if edge['type'] in {'hard-prerequisite','implementation-baseline','future-prerequisite'}:
        graph[edge['source_id']].append(edge['target_id'])
active, done = set(), set()
def visit(node):
    assert node not in active, f'hard dependency cycle: {node}'
    if node in done:
        return
    active.add(node)
    for child in graph[node]:
        visit(child)
    active.remove(node)
    done.add(node)
for node in graph:
    visit(node)
# Local Markdown path checks apply only to this packet, not immutable older evidence.
files = list(HERE.glob('*.md')) + [HERE.parent / 'UTCP_KANEO_TRACKER_MIGRATION_READINESS_AUDIT_E1.md']
links = 0
for file in files:
    data = file.read_text()
    assert not any(line.rstrip() != line for line in data.splitlines()), f'whitespace: {file}'
    for target in re.findall(r'\]\(([^)]+)\)', data):
        target = target.strip('<>')
        if re.match(r'[a-zA-Z]+://|#',target):
            continue
        target = urllib.parse.unquote(target.split('#')[0])
        assert (file.parent / target).exists(), f'broken link {file}: {target}'
        links += 1
# Parse every JSON and Python without writing pycache files.
for file in HERE.glob('*.json'):
    json.loads(file.read_text())
for file in HERE.glob('*.py'):
    compile(file.read_text(), str(file), 'exec')
namespace = {'__file__': str(HERE/'reproduce-preview.py'), '__name__':'audit_renderer'}
exec(compile((HERE/'reproduce-preview.py').read_text(), 'reproduce-preview.py', 'exec'),namespace)
assert namespace['render']() == preview, 'non-reproducible preview'
status = subprocess.check_output(['git','status','--porcelain=v1','-uall'], cwd=REPO, text=True).splitlines()
assert all(line.startswith('?? ') for line in status), 'tracked/staged modification'
original = {line[3:] for line in baseline['status'].splitlines()}
current = {line[3:] for line in status}
assert original <= current, 'missing pre-existing untracked file'
for added in current - original:
    assert added.startswith('docs/architecture/proposals/kaneo-tracker/') or added == 'docs/architecture/proposals/UTCP_KANEO_TRACKER_MIGRATION_READINESS_AUDIT_E1.md', f'out-of-scope addition: {added}'
assert subprocess.check_output(['git','rev-parse','HEAD'],cwd=REPO,text=True).strip() == baseline['head']
assert subprocess.check_output(['git','branch','--show-current'],cwd=REPO,text=True).strip() == baseline['branch']
# Use repository scanner patterns on exact new payload artifacts, with no broad suppression.
scanner = {'__name__':'audit_scanner', '__file__':str(REPO/'scripts/ci/secret-scan')}
exec(compile((REPO/'scripts/ci/secret-scan').read_text(),'secret-scan','exec'),scanner)
for file in list(HERE.iterdir()) + [files[-1]]:
    if file.is_file() and file.suffix in {'.md','.json','.py'}:
        for line_no,line in enumerate(file.read_text().splitlines(),1):
            assert not any(pattern.search(line) for pattern in scanner['SECRET_PATTERNS']), f'payload secret pattern: {file.name}:{line_no}'
print(f'PASS: {len(records)} unique records, {len(units)} unique units, {len(sources)} source hashes, {len(baseline["hashes"])} preserved baseline files, {links} local links; JSON/Python/whitespace/secret patterns/status dimensions/dependency DAG/preview reproducibility checked')
print(f'Working tree: {len(status)} untracked files; no tracked/staged changes; branch and HEAD preserved')
