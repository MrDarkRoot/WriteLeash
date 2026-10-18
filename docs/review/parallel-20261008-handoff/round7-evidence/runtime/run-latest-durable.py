import subprocess,datetime,json,os
from pathlib import Path
out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');root='/tmp/writeleash-round7-browser-rebuild';cmd=['/tmp/wlruntime/ns.sh',root,'sh',str(out/'latest-durable-runner.sh')];env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash';start=datetime.datetime.now(datetime.timezone.utc).isoformat()
with (out/'latest-durable.log').open('w') as f:
 f.write('FRESH ROUND7 LATEST EXACT SOURCE ad6377eaf6b68548221d3e4fae0b522b0e19f7d3\nTREE 9278c28cdb961c0fc404202ec1ea551882b4620a\nCOMMAND '+repr(cmd)+'\nSTART '+start+'\n');f.flush();p=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
r={'command':cmd,'source_commit':'ad6377eaf6b68548221d3e4fae0b522b0e19f7d3','source_tree':'9278c28cdb961c0fc404202ec1ea551882b4620a','started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':p.returncode};(out/'latest-durable-receipt.json').write_text(json.dumps(r,indent=2)+'\n');print(r,flush=True)
