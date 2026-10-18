import subprocess,time,json,datetime,os,shutil
from pathlib import Path
out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime')
root='/tmp/writeleash-round7-integration'
receipts=[]
for suite,number in [('durable',108),('jobs',109),('undo',110)]:
    for engine,client,sock in [('mysql','/tmp/wlruntime/mysql/bin/mysql','mysql'),('mariadb','/tmp/wlruntime/mariadb/bin/mariadb','mariadb')]:
        proc=subprocess.run([client,'--no-defaults','--socket=/tmp/wlruntime/sock/'+sock+'.sock','-uroot','-pdisposable_root_password','-e',"DROP DATABASE IF EXISTS wp_test; CREATE DATABASE wp_test; GRANT ALL PRIVILEGES ON wp_test.* TO 'wp_test'@'%'; FLUSH PRIVILEGES;"],capture_output=True,text=True)
        if proc.returncode: raise RuntimeError(proc.stderr)
        shutil.rmtree('/tmp/wl'+str(number)+'-'+engine,ignore_errors=True)
    env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash'
    cmd=['/tmp/wlruntime/ns.sh',root,'sh','/tmp/writeleash-round7-runners/'+suite+'.sh']
    start=datetime.datetime.now(datetime.timezone.utc).isoformat();t=time.monotonic()
    with (out/(suite+'.log')).open('w') as f:
        f.write('FRESH ROUND 7 AUDIT\nCOMMAND '+repr(cmd)+'\nSOURCE '+root+'\nSTART '+start+'\n');f.flush()
        proc=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env)
        f.write('\nACTUAL_EXIT_STATUS='+str(proc.returncode)+'\n')
    rec={'suite':suite,'command':cmd,'source_commit':'3b4ab5e8895fb60dbcb00d26d5c597f129b9ed8f','started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':proc.returncode,'seconds':round(time.monotonic()-t,2)}
    receipts.append(rec);(out/'suite-receipts.json').write_text(json.dumps(receipts,indent=2)+'\n');print(json.dumps(rec),flush=True)
