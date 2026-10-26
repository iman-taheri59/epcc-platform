#!/usr/bin/env python3
"""Isolated PG protocol proof, NOT an application adapter or migration."""
import os,sys,json,threading,time
from pathlib import Path
import psycopg
DB='demand_atomic_proof'
SOCKET=os.environ.get('DEMAND_PROOF_SOCKET','')
if os.environ.get('DEMAND_PROOF_RESET')!=DB or not SOCKET.startswith('/tmp/epcc-demand-atomic-sock-'):
    sys.exit('Refusing: dedicated reset guard and private proof socket required')
DSN=dict(host=SOCKET,port=5547,dbname=DB)
def admin(q,args=()):
    with psycopg.connect(**DSN,user='user')as c:
        c.execute('SET search_path=demand_proof,pg_catalog')
        r=c.execute(q,args)
        return r.fetchall()if r.description else None
def conn(project='P1',buyer='BUY1',actor='C'):
    c=psycopg.connect(**DSN,user='demand_atomic_actor')
    c.execute('SET search_path=demand_proof,pg_catalog')
    for k,v in [('project',project),('buyer',buyer),('actor',actor)]:c.execute('SELECT set_config(%s,%s,true)',('proof.'+k,v))
    c.execute("SET LOCAL statement_timeout='8s'")
    return c
def call(c,mr='M1',version='MV1',basis='B1',evidence='E1',request='REQ1',fault=''):
    return c.execute('SELECT approve_and_reserve(%s,%s,%s,%s,%s,%s)',(mr,version,basis,evidence,request,fault)).fetchone()[0]
def invoke(**kw):
    context={k:kw.pop(k)for k in ['project','buyer','actor']if k in kw}
    with conn(**context)as c:return call(c,**kw)
def reset():
    admin('TRUNCATE events,mrs,nominations,sources,projects CASCADE')
    admin("INSERT INTO projects VALUES('P1','BUY1',false),('P2','BUY2',false)")
    admin("INSERT INTO sources VALUES('P1','BUY1','S1','B1','E1',true,true,10000,0),('P2','BUY2','S2','B2','E2',true,true,10000,0)")
    admin("INSERT INTO nominations VALUES('P1','BUY1','C','S1',true,true,true,true)")
    for mid,qty in [('M1',6000),('M2',6000)]:
        admin("INSERT INTO mrs VALUES('P1','BUY1',%s,'MV1','S1',%s,'TECHNICAL_APPROVED','P','T','P','C','MV1','B1','E1',NULL)",(mid,qty))
def snapshot():return admin("SELECT (SELECT used FROM sources WHERE project='P1'),(SELECT count(*) FROM events),(SELECT count(*) FROM mrs WHERE state='APPROVED')")[0]
def expect(code,fn):
    try:fn()
    except psycopg.Error as e:
        assert code in str(e),(code,str(e));return
    raise AssertionError('Expected '+code)
n=0
def test(name,fn):
    global n
    reset();fn();n+=1;print('PASS',n,name,flush=True)
with psycopg.connect(**DSN,user='user',autocommit=True)as c:
    c.execute('DROP SCHEMA IF EXISTS demand_proof CASCADE')
    c.execute(Path(__file__).with_name('schema.sql').read_text())

def success():
    assert invoke()=='REQ1';assert snapshot()==(6000,1,1)
test('approval and one reservation committed together',success)
def late(fault):
    expect('INJECTED_',lambda:invoke(fault=fault));assert snapshot()==(0,0,0)
test('failure after MR update rolls approval back',lambda:late('after_approval'))
test('failure after event rolls entire transaction back',lambda:late('after_event'))
def retry():
    invoke();invoke();assert snapshot()==(6000,1,1)
test('same request same content reserves once',retry)
def conflicting():
    invoke();expect('RETRY_PAYLOAD_CONFLICT',lambda:invoke(mr='M2'));assert snapshot()==(6000,1,1)
test('same request other MR conflicts',conflicting)
def second():
    invoke();expect('INVALID_MR_STATE',lambda:invoke(request='REQ2'));assert snapshot()==(6000,1,1)
test('new request cannot approve same MR twice',second)
for name,kw,code in [('stale MR',{'version':'OLD'},'MR_VERSION_CHANGED'),('stale basis',{'basis':'OLD'},'SOURCE_HEAD_CHANGED'),('stale evidence',{'evidence':'OLD'},'SOURCE_HEAD_CHANGED'),('wrong project',{'project':'P2'},'PROJECT_UNAVAILABLE'),('wrong buyer',{'buyer':'BUY2'},'PROJECT_UNAVAILABLE'),('not nominated',{'actor':'X'},'CURRENT_AUTHORITY_REQUIRED')]:
    def negative(kw=kw,code=code):expect(code,lambda:invoke(**kw));assert snapshot()==(0,0,0)
    test(name+' has no partial approval',negative)
for field in ['active','can_read','can_apply','can_commercial']:
    def revoked(field=field):
        admin('UPDATE nominations SET '+field+'=false');expect('CURRENT_AUTHORITY_REQUIRED',invoke);assert snapshot()==(0,0,0)
    test('fresh '+field+' required',revoked)
for field in ['admitted','inventory_complete']:
    def unresolved(field=field):
        admin('UPDATE sources SET '+field+'=false');expect('DEMAND_UNRESOLVED',invoke);assert snapshot()==(0,0,0)
    test(field+' is not inferred from approval',unresolved)
def archive():
    admin('UPDATE projects SET archived=true');expect('ARCHIVED',invoke);assert snapshot()==(0,0,0)
test('archive blocks new reservation',archive)
def sod():
    admin("UPDATE mrs SET technical_by='C'");expect('MR_SOD',invoke);assert snapshot()==(0,0,0)
test('technical and commercial person separation retained',sod)
def review():
    admin("UPDATE mrs SET proposed_by='C'");expect('REVIEW_REQUIRED',invoke);assert snapshot()==(0,0,0)
test('self-reviewed demand proposal rejected',review)
def reviewed_evidence():
    admin("UPDATE sources SET evidence='E2'");expect('REVIEW_REQUIRED',lambda:invoke(evidence='E2'));assert snapshot()==(0,0,0)
test('changed evidence with unchanged quantity needs fresh review',reviewed_evidence)
def race_same_request():
    barrier=threading.Barrier(2);out=[]
    def worker():
        try:
            with conn()as c:
                barrier.wait(timeout=5);out.append(call(c))
        except Exception as e:out.append(str(e))
    threads=[threading.Thread(target=worker)for _ in range(2)]
    for t in threads:t.start()
    for t in threads:t.join(12);assert not t.is_alive()
    assert out==['REQ1','REQ1'],out;assert snapshot()==(6000,1,1)
test('concurrent identical retries commit once',race_same_request)
def race_capacity():
    barrier=threading.Barrier(2);out=[]
    def worker(mid):
        try:
            with conn()as c:
                barrier.wait(timeout=5);call(c,mr=mid,request='REQ-'+mid)
            out.append('OK')
        except psycopg.Error as e:out.append('CAPACITY'if 'DEMAND_EXHAUSTED'in str(e)else str(e))
    ts=[threading.Thread(target=worker,args=(m,))for m in ['M1','M2']]
    for t in ts:t.start()
    for t in ts:t.join(12);assert not t.is_alive()
    assert sorted(out)==['CAPACITY','OK'],out;assert snapshot()==(6000,1,1)
test('concurrent two MRs cannot overconsume one source',race_capacity)
def blocked_after_change(kind):
    locked=threading.Event();started=threading.Event();out=[]
    def worker():
        try:
            with conn()as c:
                started.set();call(c)
            out.append('UNEXPECTED_SUCCESS')
        except psycopg.Error as e:out.append(str(e))
    with psycopg.connect(**DSN,user='user')as c:
        c.execute('SET search_path=demand_proof,pg_catalog');c.execute("SELECT id FROM projects WHERE id='P1' FOR UPDATE")
        if kind=='basis':c.execute("UPDATE sources SET basis='B2'")
        elif kind=='archive':c.execute('UPDATE projects SET archived=true')
        else:c.execute('UPDATE nominations SET active=false')
        t=threading.Thread(target=worker);t.start();assert started.wait(3)
        # Confirm the actual actor backend waits on a PostgreSQL lock, not a timing guess.
        with psycopg.connect(**DSN,user='user')as monitor:
            deadline=time.monotonic()+4
            while time.monotonic()<deadline:
                if monitor.execute("SELECT count(*) FROM pg_stat_activity WHERE usename='demand_atomic_actor' AND wait_event_type='Lock'").fetchone()[0]>0:break
                monitor.execute('SELECT pg_stat_clear_snapshot()');time.sleep(.02)
            else:raise AssertionError('actor did not block')
        assert t.is_alive()
    t.join(10);assert not t.is_alive()
    assert len(out)==1 and {'basis':'SOURCE_HEAD_CHANGED','archive':'ARCHIVED','grant':'CURRENT_AUTHORITY_REQUIRED'}[kind]in out[0],out
    assert snapshot()==(0,0,0)
for kind in ['basis','archive','grant']:test('blocked caller sees committed '+kind+' change',lambda kind=kind:blocked_after_change(kind))
def rls():
    with conn()as c:
        assert c.execute("SELECT count(*) FROM sources WHERE project='P2'").fetchone()[0]==0
        expect('row-level security',lambda:c.execute("INSERT INTO events VALUES('P2','BUY2','BAD','hash','M1','S2',1,'B2','E2','C')"))
test('ordinary actor cannot read or write foreign context',rls)
def immutable():
    invoke();expect('IMMUTABLE_EVENT',lambda:admin("UPDATE events SET qty=1"));assert snapshot()==(6000,1,1)
test('reservation event immutable even through fixture administrator',immutable)
print('RESULT',n,'isolated PostgreSQL protocol cases PASS; not Laravel/application tests')
print(json.dumps(admin("SELECT rolname,rolsuper,rolbypassrls FROM pg_roles WHERE rolname='demand_atomic_actor'")))
print(json.dumps(admin("SELECT relname,relrowsecurity,relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='demand_proof' AND c.relkind='r' ORDER BY relname")))
