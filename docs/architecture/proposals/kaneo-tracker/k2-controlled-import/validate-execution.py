#!/usr/bin/env python3
"""Validate frozen approved payload against durable final MCP read evidence, read-only."""
from pathlib import Path
import collections
import hashlib
import json
import re
import runpy
import subprocess
P=Path(__file__).resolve().parent
R=P.parents[4]
def read(p):return json.loads(p.read_text())
def digest(v):return hashlib.sha256(json.dumps(v,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
p=read(P/'freeze-v2/payload-preview.json');h=read(P/'freeze-v2/payload-hashes.json')
a=read(P/'execution-authorization.json');b=read(P/'execution-baseline.json');j=read(P/'execution-journal.json');s=read(P/'execution-after.json')
root='d093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb'
assert digest(h)==root==a['approved_manifest_digest']==j['approved_manifest_digest']
assert digest(p)==h['payload_packet_sha256']
f=read(P.parent/'k1-mapping-freeze/source-hashes.json')
assert digest(f)==h['source_manifest_sha256']
assert digest(read(P.parent/'k1-mapping-freeze/source-task-mapping.json'))==h['mapping_sha256']
assert digest(read(P.parent/'k1-mapping-freeze/candidate-decisions.json'))==h['candidate_decisions_sha256']
policy='docs/governance/KANEO_CANONICAL_TRACKER_POLICY.md'
assert hashlib.sha256(a['policy_before_content'].encode()).hexdigest()==a['policy_before_sha256']==f['files'][policy]
assert sha(R/policy)==a['policy_after_sha256']
assert '**Activation: MIGRATING.**' in (R/policy).read_text()
for path,expected in f['files'].items():
 if path!=policy:assert sha(R/path)==expected,path
for path,expected in b['files'].items():
 if path!=policy:assert sha(R/path)==expected,path
assert b['files'][policy]==a['policy_before_sha256']
assert len(j['operations'])==19 and all(o['state']=='VERIFIED' and o['attempt']==1 for o in j['operations'])
assert collections.Counter(o['kind'] for o in j['operations'])=={'create_task':10,'attach_label_to_task':4,'create_task_relation':5}
assert len(j['mapping'])==len(set(j['mapping'].values()))==10
for o in j['operations']:assert digest(o['arguments'])==o['arguments_sha256']
page=s['tasks'];assert page['pagination']=={'total':10,'page':1,'pageSize':100,'totalPages':1,'relatedPage':1,'relatedPageSize':100,'relatedTotalPages':1}
assert page['data']['id']==p['project_id'] and page['data']['workspaceId']==p['workspace_id']
assert not page['data']['plannedTasks'] and not page['data']['archivedTasks']
tasks=[t for c in page['data']['columns'] for t in c['tasks']]
assert len(tasks)==10 and len({t['id'] for t in tasks})==10
byid={t['id']:t for t in tasks}
markers=[]
for t in tasks:
 assert not t['descriptionDeferred']
 markers+=re.findall(r'^UTCP-Migration-Key: (.+)$',t['description'],re.M)
assert len(markers)==len(set(markers))==10
for task,row in zip(p['tasks'],h['tasks']):
 key=task['migration_key'];remote=byid[j['mapping'][key]]
 assert digest(task['create_arguments'])==task['payload_sha256']==row['payload_sha256']
 assert digest(task['source_hashes'])==task['source_set_sha256']==row['source_set_sha256']
 assert set(task['create_arguments'])=={'projectId','title','description','status','priority'}
 for field in task['create_arguments']:assert remote[field]==task['create_arguments'][field],(key,field)
 assert remote['userId'] is None and remote['assigneeId'] is None and remote['dueDate'] is None and remote['startDate'] is None
 assert remote['status']!='done' and remote['priority']=='no-priority'
 op=next(o for o in j['operations'] if o['kind']=='create_task' and o['key']==key)
 assert op['returned_id']==remote['id'] and op['read_back']['columnId']==task['column_id']
 expected=[o['returned_id'] for o in j['operations'] if o['kind']=='attach_label_to_task' and o['key']==key]
 assert {l['id'] for l in remote['labels']}==set(expected)
 assert remote['externalLinks']==[]
labels={l['id']:l for l in read(P/'execution-before.json')['labels']}
for expected,op in zip(p['label_attachments'],[o for o in j['operations'] if o['kind']=='attach_label_to_task']):
 assert op['arguments']=={'labelId':expected['label_id'],'taskId':j['mapping'][expected['migration_key']]}
 label=next(l for l in byid[op['arguments']['taskId']]['labels'] if l['id']==op['returned_id'])
 assert label['name']==labels[expected['label_id']]['name'] and label['color']==labels[expected['label_id']]['color']
rels={}
for row in s['relations_by_task']:
 assert row['id']==j['mapping'][row['key']]
 for rel in row['relations']:
  if rel['id'] in rels:assert rels[rel['id']]==rel
  rels[rel['id']]=rel
assert len(rels)==5
for expected,op in zip(p['relationships'],[o for o in j['operations'] if o['kind']=='create_task_relation']):
 args={'relationType':expected['relationType'],'sourceTaskId':j['mapping'][expected['source_key']],'targetTaskId':j['mapping'][expected['target_key']]}
 assert op['arguments']==args
 rel=rels[op['returned_id']]
 for field,value in args.items():assert rel[field]==value
 for endpoint in [args['sourceTaskId'],args['targetTaskId']]:
  assert any(x['id']==rel['id'] for row in s['relations_by_task'] if row['id']==endpoint for x in row['relations'])
assert {o['returned_id'] for o in j['operations'] if o['kind']=='create_task_relation'}==set(rels)
assert subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()==b['head']
assert subprocess.check_output(['git','branch','--show-current'],text=True).strip()==b['branch']
assert not subprocess.check_output(['git','diff','--cached','--name-only'],text=True).strip()
scan=runpy.run_path(str(R/'scripts/ci/secret-scan'))['SECRET_PATTERNS']
new=set(subprocess.check_output(['git','ls-files','--others','--exclude-standard'],text=True).splitlines())-set(x[3:] for x in b['status'].splitlines() if x.startswith('??'))
assert all(x.startswith(str(P.relative_to(R))+'/') for x in new)
for path in [*(R/x for x in new),R/policy]:
 text=path.read_text();assert text.endswith('\n') and all(line==line.rstrip() for line in text.splitlines()),path
 assert not any(pattern.search(line) for line in text.splitlines() for pattern in scan),path
 if path.suffix=='.json':json.loads(text)
 if path.suffix=='.md':
  for target in re.findall(r'\]\(([^)]+)\)',text):
   if '://' not in target and not target.startswith('#'):assert (path.parent/target.split('#')[0]).exists(),target
summary={'verdict':'UTCP_KANEO_K2_INITIAL_IMPORT_VERIFIED','created':10,'reused':0,'tasks_verified':10,'labels_verified':4,'relations_verified':5,'failed':0,'unknown':0,'duplicate':0,'missing':0,'mismatched':0,'unexpected':0,'kaneo_writes':19,'governance':'MIGRATING','preserved_entry_files':len(b['files'])-1,'authorized_changed_entry_files':1,'frozen_source_hashes_current':len(f['files'])-1,'frozen_policy_snapshot_preserved':True,'approved_manifest_digest':root}
print(json.dumps(summary,indent=2))
