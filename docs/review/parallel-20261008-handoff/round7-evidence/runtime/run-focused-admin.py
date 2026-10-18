from pathlib import Path
import subprocess,os,json,datetime
out=Path('/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/runtime');root='/tmp/writeleash-round7-browser-rebuild';results=[];env=os.environ.copy();env['WRITELEASH_STAGE_SOURCE']=root+'/wordpress/writeleash';base=['/tmp/wlruntime/ns.sh',root]
for engine,client in [('mysql','/tmp/wlruntime/mysql/bin/mysql'),('mariadb','/tmp/wlruntime/mariadb/bin/mariadb')]:
 site='/tmp/writeleash-round7-admin-'+engine
 subprocess.run([client,'--no-defaults','--socket=/tmp/wlruntime/sock/'+engine+'.sock','-uroot','-pdisposable_root_password','-e',"CREATE DATABASE round7_admin; GRANT ALL PRIVILEGES ON round7_admin.* TO 'wp_test'@'%'; FLUSH PRIVILEGES;"],check=True,capture_output=True)
 setup=out/('admin-'+engine+'-setup.sh');setup.write_text(f'''#!/bin/sh
set -eu
site={site}
cp -R /opt/wp-core "$site"
sh /opt/tests/stage-plugin.sh "$site"
wp --path="$site" core config --dbname=round7_admin --dbuser=wp_test --dbpass=disposable_wp_password --dbhost={engine}
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" core install --url=http://round7-admin-{engine}.example.test --title=Round7Admin --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" config set DISABLE_WP_CRON true --raw
mkdir -p "$site/wp-content/mu-plugins"
printf '%s\\n' '<?php add_filter("action_scheduler_allow_async_request_runner", "__return_false");' >"$site/wp-content/mu-plugins/wl111-manual-resume.php"
wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
wp --path="$site" plugin activate writeleash
wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
wp --path="$site" config set WP_REDIS_HOST redis
wp --path="$site" config set WP_REDIS_CLIENT predis
wp --path="$site" config set WP_REDIS_PREFIX round7-admin-{engine}:
''')
 with (out/('admin-'+engine+'-setup.log')).open('w') as f:
  p=subprocess.run(base+['sh',str(setup)],stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
 if p.returncode: raise RuntimeError('admin setup failed')
 for cache in ['default','persistent']:
  if cache=='persistent':subprocess.run(base+['wp','--path='+site,'redis','enable'],capture_output=True,check=True,env=env)
  env['WL111_CACHE']=cache
  for probe,file in [('environment',str(out/'environment.php')),('admin-integration','/opt/tests/admin/integration.php'),('progress','/opt/tests/admin/progress-integration.php')]:
   cmd=base+['wp','--path='+site,'eval-file',file];start=datetime.datetime.now(datetime.timezone.utc).isoformat()
   with (out/('admin-'+engine+'-'+cache+'-'+probe+'.log')).open('w') as f:
    f.write('FRESH ROUND7 SOURCE ad6377eaf6b68548221d3e4fae0b522b0e19f7d3\nCOMMAND '+repr(cmd)+'\n');f.flush();p=subprocess.run(cmd,stdout=f,stderr=subprocess.STDOUT,env=env);f.write('\nACTUAL_EXIT_STATUS='+str(p.returncode)+'\n')
   rec={'engine':engine,'cache':cache,'probe':probe,'source_commit':'ad6377eaf6b68548221d3e4fae0b522b0e19f7d3','started':start,'completed':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_status':p.returncode};results.append(rec);print(json.dumps(rec),flush=True);(out/'admin-receipts.json').write_text(json.dumps(results,indent=2)+'\n')
