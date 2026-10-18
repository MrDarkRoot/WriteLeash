from pathlib import Path
import subprocess,os,shutil,json,datetime
out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');root='/tmp/writeleash-round7-browser-rebuild';site='/tmp/writeleash-round7-cache';env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash';env['WRITELEASH_STAGE_MANIFEST']=root+'/wordpress/release/writeleash-distribution-files.txt';base=['/tmp/wlruntime/ns.sh',root];shutil.rmtree(Path(site)/'wp-content/plugins/writeleash')
with (out/'latest-repair-stage.log').open('w') as f:
 p=subprocess.run(base+['sh','/opt/tests/stage-plugin.sh',site],stdout=f,stderr=subprocess.STDOUT,env=env)
if p.returncode:raise RuntimeError('stage failed')
results=[]
for mode in ['transient','persistent']:
 env['ROUND7_SOURCE_LABEL']='ad6377eaf6b68548221d3e4fae0b522b0e19f7d3';env['ROUND7_MODE']=mode;cmd=base+['wp','--path='+site,'eval-file',str(out/'cache-repair-probe.php')];start=datetime.datetime.now(datetime.timezone.utc).isoformat()
 with (out/('latest-combined-'+mode+'.log')).open('w') as f:
  f.write('FRESH ROUND7 latest exact combinedad6377e\nCOMMAND '+repr(cmd)+'\n');f.flush();p=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
 rec={'mode':mode,'source_commit':'ad6377eaf6b68548221d3e4fae0b522b0e19f7d3','started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':p.returncode};results.append(rec);print(rec,flush=True);(out/'latest-repair-receipts.json').write_text(json.dumps(results,indent=2)+'\n')
