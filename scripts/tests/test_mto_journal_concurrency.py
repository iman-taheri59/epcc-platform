#!/usr/bin/env python3
"""Real Laravel workers, isolated epcc_test only. Never run alongside PHPUnit."""
import os, sys, json, time, select, subprocess
from pathlib import Path
import psycopg
ROOT=Path(__file__).resolve().parents[2]
SOCK=os.environ.get('EPCC_APP_JOURNAL_SOCKET','')
if os.environ.get('EPCC_APP_JOURNAL_TEST')!='isolated' or not Path(SOCK).name.startswith('epcc-app-journal-sock-'):
    sys.exit('Explicit isolated application journal environment required')
DB=dict(host=SOCK,port=int(os.environ.get('EPCC_APP_JOURNAL_PORT','5545')),dbname='epcc_test',user='postgres')
WORKER=str(ROOT/'scripts/tests/mto_journal_worker.php')
ENV=os.environ.copy()

def admin(q,args=()):
    with psycopg.connect(**DB) as c:return c.execute(q,args).fetchall()
assert Path(admin('SHOW data_directory')[0][0]).name.startswith('epcc-app-journal-pg-')
assert admin('SHOW listen_addresses')[0][0]==''

def command(mode):
    p=subprocess.run(['php',WORKER,mode],cwd=ROOT,env=ENV,capture_output=True,text=True,timeout=12)
    assert p.returncode==0,p.stderr
    return json.loads(p.stdout)

def read(w):
    if not select.select([w.stdout],[],[],10)[0]:raise AssertionError('worker response timeout')
    line=w.stdout.readline()
    if not line:raise AssertionError('worker exited: '+w.stderr.read())
    return json.loads(line)

def send(w,s):w.stdin.write(s+'\n');w.stdin.flush()

def worker(mode,rev=''):
    w=subprocess.Popen(['php',WORKER,mode,rev],cwd=ROOT,env=ENV,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,bufsize=1)
    x=read(w);assert x['event']=='connected',x
    return w,x['pid']

def finish(w):
    w.stdin.close();w.wait(timeout=10)
    assert w.returncode==0,w.stderr.read()

def state(p):
    return admin('''SELECT COALESCE((SELECT last_ordinal FROM mto_acceptance_clocks WHERE project_id=%s),0),
     (SELECT count(*) FROM accepted_mto_batches WHERE project_id=%s),
     (SELECT count(*) FROM accepted_mto_rows WHERE project_id=%s),
     (SELECT count(*) FROM mto_acceptance_journal WHERE project_id=%s)''',(p,p,p,p))[0]

results=[]
for name,mode1,mode2,rollback,same_revision in [
 ('two actual acceptances commit','accept','accept',False,False),
 ('first actual acceptance rolls back','accept','accept',True,False),
 ('same actual revision accepted once','accept','accept',False,True),
 ('actual archive wins before acceptance','archive','accept',False,False),
 ('actual acceptance precedes archive','accept','archive',False,False)]:
    f=command('prepare');p=f['project'];a,b=f['revisions'];before=state(p)
    one=two=None
    try:
        one,pid1=worker(mode1,a);send(one,'GO');first=read(one);assert first['event']=='operation_done',first
        two,pid2=worker(mode2,a if same_revision else b);send(two,'GO')
        blocked=False;deadline=time.monotonic()+5
        while time.monotonic()<deadline:
            if pid1 in admin('SELECT pg_blocking_pids(%s)',(pid2,))[0][0]:blocked=True;break
            time.sleep(.02)
        assert blocked,'no actual application-worker contention observed'
        send(one,'ROLLBACK' if rollback else 'COMMIT');done1=read(one)
        assert done1['event']==('rolled_back' if rollback else 'committed'),done1
        finish(one)
        second=read(two)
        if same_revision or mode1=='archive':
            assert second['event']=='rejected',second
            assert ('revision' if same_revision else 'project') in second['fields'],second
        else:
            assert second['event']=='operation_done',second
            send(two,'COMMIT');assert read(two)['event']=='committed'
        finish(two)
        added=0 if mode1=='archive' else 1 if rollback or same_revision or mode2=='archive' else 2
        after=state(p);assert after==tuple(n+added for n in before),(before,after,added)
        assert admin("SELECT count(*) FROM mto_acceptance_journal WHERE selection_state<>'UNRESOLVED'")[0][0]==0
        assert admin('SELECT count(*) FROM accepted_mto_batches b LEFT JOIN mto_acceptance_journal j ON j.accepted_mto_batch_id=b.id WHERE j.id IS NULL')[0][0]==0
        assert admin("SELECT count(*) FROM mto_scope_observations WHERE admission_state<>'UNADMITTED'")[0][0]==0
        assert admin('SELECT count(*) FROM accepted_mto_batches b LEFT JOIN mto_scope_observations o ON o.accepted_mto_batch_id=b.id WHERE o.id IS NULL')[0][0]==0
        assert admin("SELECT count(*) FROM mto_scope_observations WHERE source_rows_hash<>encode(sha256(convert_to(epcc_mto_observed_source_rows(accepted_mto_batch_id,project_id)::text,'UTF8')),'hex')")[0][0]==0
        if rollback:
            assert admin('SELECT state FROM mto_revisions WHERE id=%s',(a,))[0][0]=='DRAFT'
        if mode1=='archive' or mode2=='archive':
            assert admin('SELECT state FROM projects WHERE id=%s',(p,))[0][0]=='ARCHIVED'
            assert command('reopen')['status']=='reopened'
        results.append({'scenario':name,'passed':True,'verified_blocking':True,'first':done1['event'],'second':second['event'],'committed_new_acceptances':added})
        print('PASS '+name,flush=True)
    finally:
        for w in [one,two]:
            if w is not None and w.poll() is None:w.kill();w.wait(timeout=5)
summary={'status':'PASS_REAL_LARAVEL_SERVICE_CONTENTION','scenarios':len(results),'passed':len(results),'failed':0,
 'service':'MtoAcceptanceService / ProjectArchiveService; separate PHP application kernels',
 'database':'epcc_test','synthetic_seed_only':True,'selection_state':'UNRESOLVED','g01_effective_selection_fixed':False,'scope_observations':'UNADMITTED; no missing batch observations or row-set digest drift','results':results}
if os.environ.get('EPCC_APP_JOURNAL_EVIDENCE'):Path(os.environ['EPCC_APP_JOURNAL_EVIDENCE']).write_text(json.dumps(summary,indent=2)+'\n')
print(json.dumps(summary,indent=2))
