from pathlib import Path
import subprocess,os,json,datetime,shutil
out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');site=Path('/tmp/writeleash-round7-cache');receipts=[]
for label,root in [('prefixed','/tmp/writeleash-round7-prefixed'),('fixed','/tmp/writeleash-round7-fixed'),('combined','/tmp/writeleash-round7-integration')]:
    shutil.rmtree(site/'wp-content/plugins/writeleash')
    env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash';env['WRITELEASH_STAGE_MANIFEST']=root+'/wordpress/release/writeleash-distribution-files.txt'
    cmd=['/tmp/wlruntime/ns.sh','/tmp/writeleash-round7-integration','sh','/opt/tests/stage-plugin.sh',str(site)]
    with (out/(label+'-stage.log')).open('w') as f:
        stage=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env)
    if stage.returncode: raise RuntimeError('STAGING_FAILED '+label)
    for mode in ['transient','persistent']:
        env['ROUND7_MODE']=mode;env['ROUND7_SOURCE_LABEL']=label
        cmd=['/tmp/wlruntime/ns.sh','/tmp/writeleash-round7-integration','wp','--path='+str(site),'eval-file',str(out/'cache-repair-probe.php')]
        start=datetime.datetime.now(datetime.timezone.utc).isoformat()
        with (out/(label+'-'+mode+'.log')).open('w') as f:
            f.write('FRESH ROUND7 AUDIT '+start+'\nCOMMAND '+repr(cmd)+'\n');f.flush();proc=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(proc.returncode)+'\n')
        rec={'source_label':label,'mode':mode,'exit_status':proc.returncode,'started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat()};receipts.append(rec);print(rec,flush=True);(out/'probe-receipts.json').write_text(json.dumps(receipts,indent=2)+'\n')
