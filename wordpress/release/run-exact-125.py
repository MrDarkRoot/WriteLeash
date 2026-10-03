#!/usr/bin/env python3
"""Fresh exact-ZIP baseline runner; OCI images are executed without a daemon."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import signal
import subprocess
import sys
import zipfile

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
spec = importlib.util.spec_from_file_location('oci', HERE / 'oci-runtime-125.py');oci=importlib.util.module_from_spec(spec);spec.loader.exec_module(oci)
spec2=importlib.util.spec_from_file_location('identity',HERE/'identity-125.py');identity=importlib.util.module_from_spec(spec2);spec2.loader.exec_module(identity)

def run(images, work, wp, php, engine, cache, phases, attempt, resume):
    label=f'wp{wp}-{php}-{engine}-{cache}-a{attempt}'
    site=work/label
    assert site.exists() if resume else not site.exists()
    start=identity.audit(work/'writeleash-0.1.0.zip')
    archive=images/'wp701.zip' if wp=='7.0.1' else Path('/tmp/writeleash-123-wp-7.1.2.zip')
    if not resume:
        with zipfile.ZipFile(archive) as z:z.extractall(site)
    root=site/'wordpress';inside='/work/'+label+'/wordpress'
    password=secrets.token_hex(24)
    env={**os.environ,'WP_CLI_CACHE_DIR':'/work/cache','WL125_PASSWORD':password,'WL125_URL':'http://127.0.0.1:'+('18250' if engine=='mysql' else '18251'),
         'WL112_SHA':'5ccc75c1d895a2fb379866ce4cf0e9901d1c276c','WL112_METRICS':'/evidence/'+label+'-requests.jsonl',
         'WL125_LABEL':label,'WL125_RESULT':'/evidence/'+label+'-journey.json'}
    image=images/(php+'-root')
    def cmd(argv):return oci.command(image,work,REPO,argv)
    def cli(*argv):
        r=subprocess.run(cmd(['php','-d','zend.exception_ignore_args=1','/usr/local/bin/wp','--path='+inside,*argv]),env=env,capture_output=True)
        if r.returncode:
            # Preserve failure without recording arguments/secrets or full request bodies.
            (work/'evidence'/f'{label}-failure.txt').write_bytes(r.stdout+r.stderr)
            raise RuntimeError(label+': command failed ('+str(argv[0])+')')
        return r.stdout.decode()
    if resume:
        cli('user','update','admin','--user_pass='+password,'--quiet')
        if subprocess.run(cmd(['php','/usr/local/bin/wp','--path='+inside,'plugin','is-active','woocommerce']),env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode:
            cli('plugin','activate','woocommerce')
    else:
        cli('config','create','--dbname=wl125_'+label.replace('-','_').replace('.','_'),'--dbuser=root','--dbhost=localhost:/work/'+engine+'.sock','--skip-check','--quiet')
        cli('db','create','--quiet')
        cli('core','install','--url='+env['WL125_URL'],'--title=Synthetic exact ZIP acceptance','--admin_user=admin','--admin_password='+password,'--admin_email=acceptance@example.invalid','--skip-email','--quiet')
        for key,value in [('siteurl',env['WL125_URL']),('home',env['WL125_URL'])]:cli('option','update',key,value,'--quiet')
        for key,value,raw in [('DISABLE_WP_CRON','true',True),('WP_HTTP_BLOCK_EXTERNAL','true',True),('WP_ACCESSIBLE_HOSTS','127.0.0.1',False),('WP_MEMORY_LIMIT','256M',False),('WP_MAX_MEMORY_LIMIT','256M',False)]:
            cli('config','set',key,value,*(['--raw'] if raw else []),'--quiet')
        cli('plugin','install','/work/woocommerce.zip','--activate')
        cli('plugin','install','/work/writeleash-0.1.0.zip')
        if cache=='redis':
            cli('plugin','install','/work/redis-cache.zip','--activate')
            for key,value,raw in [('WP_REDIS_HOST','127.0.0.1',False),('WP_REDIS_PORT','16379',True),('WP_REDIS_CLIENT','predis',False),('WP_REDIS_PREFIX',label+':',False)]:cli('config','set',key,value,*(['--raw'] if raw else []),'--quiet')
            cli('redis','enable')
    mu=root/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
    (mu/'wl125-observer.php').write_bytes((HERE/'instrument-125.php').read_bytes())
    installed=identity.audit(work/'writeleash-0.1.0.zip',root/'wp-content/plugins/writeleash')
    serverlog=open(os.devnull,'wb')
    server=subprocess.Popen(cmd(['php','-d','memory_limit=256M','-d','max_execution_time=60','-S',env['WL125_URL'].removeprefix('http://'),'-t',inside]),env=env,stdout=serverlog,stderr=subprocess.STDOUT,start_new_session=True)
    try:
        import time
        time.sleep(1)
        assert server.poll() is None, 'test server failed to start; no behavior may be accepted'
        if not resume: print(label+': '+cli('eval-file','/repo/wordpress/release/install-125.php').strip(),flush=True)
        versions=json.loads(cli('eval','global $wpdb; echo json_encode(array("WP"=>get_bloginfo("version"),"PHP"=>PHP_VERSION,"WOO"=>WC_VERSION,"DB"=>$wpdb->get_var("SELECT VERSION()"),"CACHE"=>wp_using_ext_object_cache()?"redis":"default"));'))
        expected={'php74':'7.4.33','php80':'8.0.30','php81':'8.1.34','php82':'8.2.34'}[php]
        assert versions['WP']==wp and versions['PHP']==expected and versions['WOO']=='11.1.2' and versions['CACHE']==cache
        assert ('8.0.44' if engine=='mysql' else '10.11.15') in versions['DB']
        record={'CONFIGURATION':label,'ENVIRONMENT':versions,'CHECKSUM_START':start,'INSTALLED_IDENTITY_BEFORE_BEHAVIOR':installed,'NORMAL_INSTALL':'PASS','NORMAL_ACTIVATION':'PASS','PRODUCT_ENTRY':'PASS','PHASES':{}}
        record_path=work/'evidence'/f'{label}-baseline.json'
        if resume: record=json.loads(record_path.read_text())
        record_path.write_text(json.dumps(record,indent=2)+'\n')
        for phase in phases:
            if phase in ['100','101']:
                env['WL112_SIZE']=phase
                out=cli('eval-file','/repo/wordpress/release/scale-journey-125.php')
                generated=work/'evidence'/('localhost:/work/'+engine+'.sock-'+phase+'.json')
            elif phase=='journey':out=cli('eval-file','/repo/wordpress/release/journey-125.php')
            elif phase=='supplement':out=cli('eval-file','/repo/wordpress/release/supplement-125.php')
            elif phase=='auth':
                out=cli('eval-file','/repo/wordpress/release/nonce-smoke-124-reaudit.php')
                out+=cli('eval-file','/repo/wordpress/release/compliance-probe-125.php')
            elif phase=='retention':out=cli('eval-file','/repo/wordpress/release/retention-125.php')
            elif phase=='dependency':
                before=json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))
                cli('plugin','deactivate','woocommerce')
                missing=json.loads(cli('eval-file','/repo/wordpress/release/dependency-125.php'))
                assert json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))['product_tree_hash']==before['product_tree_hash']
                cli('plugin','install','/work/woocommerce-11.0.1.zip','--force','--activate')
                wrong=json.loads(cli('eval-file','/repo/wordpress/release/dependency-125.php'))
                assert wrong['reason']=='woocommerce_version_unsupported'
                assert json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))['product_tree_hash']==before['product_tree_hash']
                cli('plugin','install','/work/woocommerce.zip','--force','--activate')
                (work/'evidence'/f'{label}-dependency.json').write_text(json.dumps({'missing':missing,'wrong_version':wrong,'product_preserved':'PASS'},indent=2)+'\n')
                out='Inactive/missing and actual Woo 11.0.1: safe REFUSED, no product/durable mutation; exact 11.1.2 restored'
            elif phase=='lifecycle':
                prep=json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-prepare-125.php'))
                before=json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))
                assert before['runner']=='active'
                counts=[]
                counts.append(cli('plugin','deactivate','writeleash','--require=/repo/wordpress/release/lifecycle-query-observer-124.php'))
                deactivated=json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))
                assert deactivated['product_tree_hash']==before['product_tree_hash'] and deactivated['durable_tree_hash']==before['durable_tree_hash'] and deactivated['runner']=='deactivated'
                assert deactivated['actions']=={'unrelated':'pending','owned':'canceled'}
                cli('eval-file','/repo/wordpress/release/install-125.php')
                reactivated=json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))
                assert reactivated['product_tree_hash']==before['product_tree_hash'] and reactivated['durable_tree_hash']==before['durable_tree_hash'] and reactivated['runner']=='active'
                prefix='/work/tmp/'+label+'-survivor'
                survivor={'job_id':prep['job_id'],'ready':prefix+'.ready','release':prefix+'.release','result':prefix+'.result'}
                spec_path=work/'tmp'/f'{label}-survivor.json';spec_path.write_text(json.dumps(survivor))
                child=subprocess.Popen(cmd(['php','-d','zend.exception_ignore_args=1','/usr/local/bin/wp','--path='+inside,'eval-file','/repo/wordpress/release/survivor-125.php',prefix+'.json']),env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                deadline=time.monotonic()+30
                while not (work/'tmp'/f'{label}-survivor.ready').exists():
                    assert time.monotonic()<deadline;time.sleep(.01)
                counts.append(cli('plugin','deactivate','writeleash','--require=/repo/wordpress/release/lifecycle-query-observer-124.php'))
                counts.append(cli('plugin','uninstall','writeleash','--require=/repo/wordpress/release/lifecycle-query-observer-124.php'))
                (work/'tmp'/f'{label}-survivor.release').write_text('normal Core uninstall complete')
                assert child.wait(timeout=30)==0
                guard=json.loads((work/'tmp'/f'{label}-survivor.result').read_text());assert guard=={'runner_active':False,'woo_saves':0}
                uninstalled=json.loads(cli('eval-file','/repo/wordpress/release/lifecycle-observer-125.php'))
                assert uninstalled['product_tree_hash']==before['product_tree_hash'] and uninstalled['durable_tree_hash']==before['durable_tree_hash'] and uninstalled['runner'] is None and uninstalled['actions']=={'unrelated':'pending','owned':'canceled'}
                assert not (root/'wp-content/plugins/writeleash').exists()
                for output in counts:assert 'LIFECYCLE_QUERY_COUNTS={"DDL_DCL":0,"PRODUCT_DML":0}' in output
                (work/'evidence'/f'{label}-lifecycle.json').write_text(json.dumps({'classification':'PASS','before':before,'deactivated':deactivated,'reactivated':reactivated,'uninstalled':uninstalled,'surviving_loaded_worker':guard,'query_observer':counts},indent=2)+'\n')
                cli('plugin','install','/work/writeleash-0.1.0.zip')
                identity.audit(work/'writeleash-0.1.0.zip',root/'wp-content/plugins/writeleash')
                cli('eval-file','/repo/wordpress/release/install-125.php')
                out='Normal deactivate/reactivate/uninstall: all Woo/durable data preserved, owned-only cleanup, surviving loaded worker refuses, zero DDL/DCL/product DML; exact ZIP reinstalled'
            else:raise ValueError('unknown phase')
            print(label+': '+out.strip(),flush=True)
            record['PHASES'][phase]='REFUSED' if phase=='101' else 'PASS'
            record_path.write_text(json.dumps(record,indent=2)+'\n')
        record['CHECKSUM_END']=identity.audit(work/'writeleash-0.1.0.zip',root/'wp-content/plugins/writeleash')
        record_path.write_text(json.dumps(record,indent=2)+'\n')
    finally:
        os.killpg(server.pid, signal.SIGTERM);server.wait(timeout=20);serverlog.close()

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--images',type=Path,default=Path('/tmp/writeleash-125-images'));p.add_argument('--work',type=Path,default=Path('/tmp/writeleash-125-work'));p.add_argument('--wp',default='7.1.2');p.add_argument('--php',default='php82');p.add_argument('--db',default='mysql');p.add_argument('--cache',default='default');p.add_argument('--phases',nargs='+',default=['100','101','journey','auth','retention','dependency','lifecycle']);p.add_argument('--attempt',default='1');p.add_argument('--resume',action='store_true')
    a=p.parse_args();run(a.images,a.work,a.wp,a.php,a.db,a.cache,a.phases,a.attempt,a.resume)
