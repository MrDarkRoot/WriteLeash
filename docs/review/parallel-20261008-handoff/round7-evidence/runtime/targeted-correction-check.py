from pathlib import Path
import subprocess,os,shutil,json,datetime
root='/tmp/writeleash-round7-pr220';site='/tmp/writeleash-round7-cache';out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash';base=['/tmp/wlruntime/ns.sh',root];shutil.rmtree(Path(site)/'wp-content/plugins/writeleash')
with (out/'corrected-pr220-stage.log').open('w') as f:
 proc=subprocess.run(base+['sh','/opt/tests/stage-plugin.sh',site],stdout=f,stderr=subprocess.STDOUT,env=env)
if proc.returncode:raise RuntimeError('stage failed')
subprocess.run(base+['wp','--path='+site,'option','update','woocommerce_feature_product_instance_caching_enabled','yes'],capture_output=True,check=True,env=env)
receipts=[]
for label,cache in [('corrected-pr220-default','default'),('corrected-pr220-wrong-cache-negative','persistent')]:
 env['WL209_CACHE']=cache;cmd=base+['wp','--path='+site,'eval-file','/opt/tests/durable/cache-public-integration.php'];start=datetime.datetime.now(datetime.timezone.utc).isoformat()
 with (out/(label+'.log')).open('w') as f:
  f.write('FRESH ROUND7; exact PR220 c2e3f616 plus only cache bool fixture correction\nCOMMAND '+repr(cmd)+'\n');f.flush();p=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
 r={'label':label,'command':cmd,'started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':p.returncode};receipts.append(r);print(r,flush=True);(out/'corrected-pr220-receipts.json').write_text(json.dumps(receipts,indent=2)+'\n')
