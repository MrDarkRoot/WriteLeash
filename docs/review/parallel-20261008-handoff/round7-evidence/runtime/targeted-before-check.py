from pathlib import Path
import subprocess,os,shutil,json,datetime
root='/tmp/writeleash-round7-pr220';source='/tmp/writeleash-round7-prefixed';site='/tmp/writeleash-round7-cache';out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=source+'/wordpress/writeleash';env['WRITELEASH_STAGE_MANIFEST']=source+'/wordpress/release/writeleash-distribution-files.txt';base=['/tmp/wlruntime/ns.sh',root];shutil.rmtree(Path(site)/'wp-content/plugins/writeleash')
with (out/'targeted-prefixed-stage.log').open('w') as f:
 proc=subprocess.run(base+['sh','/opt/tests/stage-plugin.sh',site],stdout=f,stderr=subprocess.STDOUT,env=env)
if proc.returncode:raise RuntimeError('stage failed')
env['WL209_CACHE']='default';cmd=base+['wp','--path='+site,'eval-file','/opt/tests/durable/cache-public-integration.php'];start=datetime.datetime.now(datetime.timezone.utc).isoformat()
with (out/'targeted-prefixed-regression.log').open('w') as f:
 f.write('FRESH ROUND7; exact prefixed production029a08040da0fab06bfea2a8d174aeba3d72f2d4 + current corrected regression64 fixture\nCOMMAND '+repr(cmd)+'\n');f.flush();p=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
r={'command':cmd,'production_source':'029a08040da0fab06bfea2a8d174aeba3d72f2d4','started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':p.returncode};(out/'targeted-prefixed-receipt.json').write_text(json.dumps(r,indent=2)+'\n');print(r,flush=True)
