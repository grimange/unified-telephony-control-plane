#!/usr/bin/env python3
"""Render the frozen audit plan from reviewed rules and source inventory; no writes/network."""
import collections
import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent

def read(name):
    return json.loads((ROOT / name).read_text())

def render():
    records = read('inventory-records.json')
    rules = read('migration-rules.json')
    snapshot = read('kaneo-read-snapshot.json')
    preview = dict(rules['metadata'])
    preview.update(workspace=snapshot['workspace'], project=snapshot['project'], columns=snapshot['columns'])
    units = []
    for policy in rules['unit_policy']:
        unit = dict(policy)
        rows = [r for r in records if r['target_key'] == unit['migration_key']]
        unit['source_ids'] = [r['source_id'] for r in rows]
        unit['dispositions'] = sorted({r['disposition'] for r in rows})
        unit['source_paths'] = sorted({p for r in rows for p in r['source_paths']})
        unit['source_hashes'] = {p: next(r['source_hashes'][p] for r in rows if p in r['source_hashes']) for p in unit['source_paths']}
        unit['status_dimensions'] = [{k:r[k] for k in ['source_id','source_status','evidence_classification','independent_review','operator_acceptance','production_state']} for r in rows]
        unit['engineering_objective'] = '; '.join(r['description'] for r in rows)
        units.append(unit)
    preview['units'] = sorted(units, key=lambda u:u['migration_key'])
    preview['excluded_from_remote_creation'] = [r['migration_key'] for r in records if r['target_key'] is None]
    preview['counts'] = dict(records=len(records), sources=len(read('source-catalog.json')), dispositions=dict(collections.Counter(r['disposition'] for r in records)), task_units=len(units), initial_task_units=sum(u['initial_import_candidate'] for u in units), record_types=dict(collections.Counter(r['type'] for r in records)), workstreams=dict(collections.Counter(r['workstream'] for r in records)), evidence=dict(collections.Counter(r['evidence_classification'] for r in records)), source_statuses=dict(collections.Counter(r['source_status'] for r in records)))
    return preview

if __name__ == '__main__':
    result = render()
    if sys.argv[1:] == ['--check']:
        assert result == read('migration-preview.json'), 'Preview differs from frozen rules/source inventory'
        print('PASS: deterministic preview matches frozen rules and source inventory')
    elif not sys.argv[1:]:
        print(json.dumps(result, indent=2, ensure_ascii=False, sort_keys=True))
    else:
        raise SystemExit('Usage: reproduce-preview.py [--check]')
