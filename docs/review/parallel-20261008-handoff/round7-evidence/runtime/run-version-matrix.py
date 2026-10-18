from pathlib import Path
import subprocess,os,json,datetime,shutil,sys
out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');root=sys.argv[1];versions=sys.argv[2:];results=[]
source_sha=subprocess.check_output(['git','-C',root,'rev-parse','HEAD'],text=True).strip();source_tree=subprocess.check_output(['git','-C',root,'rev-parse','HEAD^{tree}'],text=True).strip()
for version in versions:
    label=version.replace('.','');dbname='round7_wc_'+label;site='/tmp/writeleash-round7-wc-'+label
    subprocess.run(['/tmp/wlruntime/mysql/bin/mysql','--no-defaults','--socket=/tmp/wlruntime/sock/mysql.sock','-uroot','-pdisposable_root_password','-e',f"CREATE DATABASE {dbname}; GRANT ALL PRIVILEGES ON {dbname}.* TO 'wp_test'@'%'; FLUSH PRIVILEGES;"],check=True,capture_output=True)
    env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash';base=['/tmp/wlruntime/ns.sh',root]
    setup_script=out/('version-'+version+'-setup.sh');setup_script.write_text(f'''#!/bin/sh
set -eu
site={site}
cp -R /opt/wp-core "$site"
sh /opt/tests/stage-plugin.sh "$site"
wp --path="$site" core config --dbname={dbname} --dbuser=wp_test --dbpass=disposable_wp_password --dbhost=mysql
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" core install --url=http://round7-{label}.example.test --title=Round7Version --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" config set DISABLE_WP_CRON true --raw
wp --path="$site" plugin install /opt/woo-zips/woocommerce.{version}.zip --activate
wp --path="$site" plugin activate writeleash
wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
wp --path="$site" config set WP_REDIS_HOST redis
wp --path="$site" config set WP_REDIS_CLIENT predis
wp --path="$site" config set WP_REDIS_PREFIX round7-{label}:
wp --path="$site" option update woocommerce_feature_product_instance_caching_enabled yes
''')
    with (out/('version-'+version+'-setup.log')).open('w') as f:
        proc=subprocess.run(base+['sh',str(setup_script)],stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(proc.returncode)+'\n')
    if proc.returncode:results.append({'version':version,'stage':'setup','exit_status':proc.returncode});continue
    for cache in ['default','persistent']:
        if cache=='persistent':subprocess.run(base+['wp','--path='+site,'redis','enable'],capture_output=True,check=True,env=env)
        for probe,file in [('environment',str(out/'environment.php')),('cache-public','/opt/tests/durable/cache-public-integration.php'),('repair-transient',str(out/'cache-repair-probe.php')),('repair-persistent',str(out/'cache-repair-probe.php'))]:
            env['WL209_CACHE']=cache;env['ROUND7_MODE']='persistent' if probe=='repair-persistent' else 'transient';env['ROUND7_SOURCE_LABEL']=source_sha
            cmd=base+['wp','--path='+site,'eval-file',file];started=datetime.datetime.now(datetime.timezone.utc).isoformat()
            with (out/('version-'+version+'-'+cache+'-'+probe+'.log')).open('w') as f:
                f.write('FRESH ROUND7 AUDIT '+started+'\nSOURCE '+source_sha+' TREE '+source_tree+'\nCOMMAND '+repr(cmd)+'\n');f.flush();p=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
            rec={'version':version,'cache':cache,'database':'mysql','source_commit':source_sha,'source_tree':source_tree,'probe':probe,'command':cmd,'started':started,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':p.returncode};results.append(rec);print(json.dumps(rec),flush=True);(out/'version-matrix-receipts.json').write_text(json.dumps(results,indent=2)+'\n')
