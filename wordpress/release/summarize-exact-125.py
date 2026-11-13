#!/usr/bin/env python3
"""Summarize newly executed exact-ZIP rows; missing required rows fail closed."""
import argparse
import hashlib
import json
from pathlib import Path
import sys
sys.dont_write_bytecode=True
HERE=Path(__file__).resolve().parent
SHA='7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e'
TREE='4d3fa1f34b2435a364bc62f92db75a7c417bedb23086b02eace2cf974b6cb5c4'
PROFILES=[('7.1.2','8.2.34','default'),('7.1.2','8.2.34','redis'),('7.0.1','8.2.34','default'),('7.1.2','7.4.33','redis'),('7.1.2','8.0.30','redis'),('7.1.2','8.1.34','redis')]
PHASES={'100','101','journey','auth','retention','dependency','lifecycle'}

def collect(folder, partial=False):
 rows=[];observed=set()
 for p in sorted(folder.glob('*-baseline.json')):
  b=json.loads(p.read_text());label=b['CONFIGURATION'];env=b['ENVIRONMENT']
  complete=set(b['PHASES'])>=PHASES
  if not complete:
   if partial:continue
   raise ValueError('NOT_TESTED: incomplete configuration '+label)
  assert all(b['PHASES'][phase]==('REFUSED' if phase=='101' else 'PASS') for phase in PHASES), 'failed required phase'
  assert ('8.0.44' in env['DB']) or ('10.11.15' in env['DB']), 'database version drift'
  for key in ['INSTALLED_IDENTITY_BEFORE_BEHAVIOR','CHECKSUM_END']:
   assert b[key]['ZIP_SHA256']==SHA and b[key]['ZIP_SIZE']==396314 and b[key]['INSTALLED_TREE_EQUALS_ZIP']=='PASS' and b[key]['TREE_HASH']==TREE
  envkey=(env['WP'],env['PHP'],env['CACHE'],'mysql' if '8.0.44' in env['DB'] else 'mariadb')
  assert envkey not in observed;observed.add(envkey)
  details={}
  for kind in ['journey','retention','dependency','lifecycle']:
   details[kind]=json.loads((folder/(label+'-'+kind+'.json')).read_text())
  assert details['journey']['outcome']=='PASS' and set(details['journey']['operations'])=={'SET','INCREASE_FIXED','DECREASE_FIXED','INCREASE_PERCENT','DECREASE_PERCENT'}
  assert all(v=='PASS' for v in details['journey']['checks'].values())
  assert details['retention']['classification']==details['lifecycle']['classification']=='PASS'
  scale={}
  for size in [100,101]:
   data=json.loads((folder/(label+'-scale-'+str(size)+'.json')).read_text())
   assert data['outcome']==('PASS' if size==100 else 'REFUSED_BEFORE_JOURNAL')
   scale[str(size)]={k:data[k] for k in ['actual_job_size','final_counts','cache_lookup_journal_parity']}
   scale[str(size)]['classification']='PASS' if size==100 else 'REFUSED'
   if size==100:scale[str(size)]['one_apply_save_per_frozen_product']=data['exactly_one_apply_save_per_frozen_product']
   else:
    assert data['owned_evidence_before']==data['owned_evidence_after']
    scale[str(size)]['owned_rows_before']=data['owned_evidence_before'];scale[str(size)]['owned_rows_after']=data['owned_evidence_after'];scale[str(size)]['validation']='PASS: refused before job/journal seed with zero Woo saves'
  metrics=folder/(label+'-requests.jsonl');version_rows=[]
  for line in metrics.read_text().splitlines():
   entry=json.loads(line)
   if 'php' in entry and entry.get('woo')=='11.1.2':
    assert entry['php']==env['PHP'] and entry['wp']==env['WP'] and entry['configuration']==label
    version_rows.append(entry)
  rows.append({'CONFIGURATION':label,'ENVIRONMENT':env,'INSTALLED_IDENTITY_BEFORE_BEHAVIOR':b['INSTALLED_IDENTITY_BEFORE_BEHAVIOR'],'CHECKSUM_END':b['CHECKSUM_END'],'NORMAL_INSTALL':b['NORMAL_INSTALL'],'NORMAL_ACTIVATION':b['NORMAL_ACTIVATION'],'PRODUCT_ENTRY':b['PRODUCT_ENTRY'],'PHASES':b['PHASES'],'SCALE':scale,'JOURNEY':details['journey'],'RETENTION':details['retention'],'DEPENDENCY':details['dependency'],'LIFECYCLE':details['lifecycle'],'HTTP_VERSION_OBSERVATIONS':len(version_rows),'REQUEST_OBSERVATION_SHA256':hashlib.sha256(metrics.read_bytes()).hexdigest()})
 required={(wp,php,cache,db) for wp,php,cache in PROFILES for db in ['mysql','mariadb']}
 if not partial:assert observed==required, 'NOT_TESTED: advertised matrix incomplete'
 return rows

if __name__=='__main__':
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('evidence',type=Path);p.add_argument('--partial',action='store_true');p.add_argument('--output',type=Path)
 a=p.parse_args();rows=collect(a.evidence,a.partial)
 if a.output:a.output.write_text(json.dumps(rows,indent=2)+'\n')
 print('New exact-artifact completed configurations: '+str(len(rows))+' / 12')
