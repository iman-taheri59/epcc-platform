#!/usr/bin/env python3
"""Destructive ONLY within a fresh, dedicated /tmp proof cluster. No app adapter."""
import os, sys, json, uuid, time, threading
from pathlib import Path
import psycopg
from psycopg import sql

DB = 'epcc_journal_proof'
SOCKET = os.environ.get('EPCC_PROOF_SOCKET', '')
PORT = int(os.environ.get('EPCC_PROOF_PORT', '5544'))
if os.environ.get('EPCC_PROOF_ALLOW_RESET') != DB or not Path(SOCKET).name.startswith('epcc-journal-sock-'):
    sys.exit('Refusing: require explicit dedicated proof reset guard and epcc-journal-sock-* socket.')
DSN = dict(host=SOCKET, port=PORT, dbname=DB)
uid = lambda: uuid.uuid4()
P1,P2,C1,C2,D1,D2,S1,S2 = [uid() for _ in range(8)]
results=[]

def connect(project=P1, company=C1, role='mto_proof_writer'):
    c=psycopg.connect(**DSN,user=role)
    c.execute('SET search_path=mto_proof,pg_catalog')
    c.execute("SELECT set_config('epcc.project_id',%s,true),set_config('epcc.company_id',%s,true)",(str(project) if project else '',str(company) if company else ''))
    c.execute("SET LOCAL statement_timeout='8s'")
    return c

def query(q,args=(),project=P1,company=C1):
    with connect(project,company) as c:
        cur=c.execute(q,args)
        return cur.fetchall() if cur.description else cur.rowcount

def admin(q,args=()):
    with psycopg.connect(**DSN,user='postgres') as c:
        c.execute('SET search_path=mto_proof,pg_catalog')
        cur=c.execute(q,args)
        return cur.fetchall() if cur.description else cur.rowcount

def append(c,p=P1,d=D1,r=None,s=S1,expected=None):
    return c.execute('SELECT append_acceptance(%s,%s,%s,%s,%s,%s)',(p,d,r or uid(),s,'H1',expected)).fetchone()[0]

def count_state(p=P1):
    return admin('SELECT (SELECT ordinal FROM clocks WHERE project_id=%s),(SELECT count(*) FROM batches WHERE project_id=%s),(SELECT count(*) FROM selection_events WHERE project_id=%s)',(p,p,p))[0]

def test(name,fn):
    fn();results.append({'name':name,'passed':True});print(f'PASS {len(results)} {name}',flush=True)

def expect(code,fn):
    try: fn()
    except psycopg.Error as e:
        assert e.sqlstate==code,(e.sqlstate,str(e));return
    raise AssertionError('Expected SQLSTATE '+code)

def same(a,b): assert a==b,(a,b)

with psycopg.connect(host=SOCKET,port=PORT,dbname='postgres',user='postgres',autocommit=True) as bootstrap:
    datadir=bootstrap.execute('SHOW data_directory').fetchone()[0]
    assert Path(datadir).name.startswith('epcc-journal-pg-') and str(Path(datadir).parent)=='/tmp'
    assert bootstrap.execute('SHOW listen_addresses').fetchone()[0]==''
    bootstrap.execute(sql.SQL('DROP DATABASE IF EXISTS {}').format(sql.Identifier(DB)))
    for role,login in [('mto_proof_owner','NOLOGIN'),('mto_proof_writer','LOGIN')]:
        if not bootstrap.execute('SELECT 1 FROM pg_roles WHERE rolname=%s',(role,)).fetchone():
            bootstrap.execute(sql.SQL('CREATE ROLE {} {} NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT').format(sql.Identifier(role),sql.SQL(login)))
    bootstrap.execute(sql.SQL('CREATE DATABASE {}').format(sql.Identifier(DB)))
with psycopg.connect(**DSN,user='postgres') as c:
    c.execute(Path(__file__).with_name('schema.sql').read_text())
    c.execute('SET search_path=mto_proof,pg_catalog')
    for p,company in [(P1,C1),(P2,C2)]:
        c.execute('INSERT INTO projects(id,project_id) VALUES(%s,%s)',(p,p))
        c.execute('INSERT INTO memberships VALUES(%s,%s)',(p,company))
        c.execute('INSERT INTO clocks VALUES(%s,0)',(p,))

def posture():
    same(query('SELECT rolsuper,rolbypassrls,rolcreatedb,rolcreaterole FROM pg_roles WHERE rolname=current_user')[0],(False,False,False,False))
    for setting in ['fsync','synchronous_commit','full_page_writes']:
        same(admin('SHOW '+setting)[0][0],'on')
    rows=admin("SELECT relname,relrowsecurity,relforcerowsecurity FROM pg_class WHERE relnamespace='mto_proof'::regnamespace AND relkind='r'")
    assert len(rows)==6 and all(x[1] and x[2] for x in rows)
test('restricted actual writer; six tables FORCE RLS; integrity settings ON',posture)
test('missing tenant context sees no projects',lambda:same(query('SELECT count(*) FROM projects',project=None,company=None)[0][0],0))
test('foreign company sees no target project',lambda:same(query('SELECT count(*) FROM projects',company=C2)[0][0],0))
test('project context excludes another participating project',lambda:same(query('SELECT id FROM projects'),[(P1,)]))

def root():
    with connect() as c: append(c)
    same(count_state(),(1,1,1))
test('root source/manifest/event commit together',root)
def successor():
    with connect() as c: append(c)
    same(count_state(),(2,2,2))
    same(query('SELECT e.step FROM selection_events e WHERE NOT EXISTS(SELECT 1 FROM selection_events n WHERE n.previous_id=e.id)'),[(2,)])
test('successor replaces head without mutating history',successor)

def replay():
    rev=query('SELECT revision_id FROM batches ORDER BY ordinal LIMIT 1')[0][0]
    before=count_state()
    def op():
        with connect() as c: append(c,r=rev)
    expect('23505',op);same(count_state(),before)
test('duplicate revision rolls back clock and entire transaction',replay)
def stale():
    before=count_state()
    def op():
        with connect() as c: append(c,expected=0)
    expect('40001',op);same(count_state(),before)
test('stale expected head rejected atomically',stale)
def rollback():
    before=count_state()
    def op():
        with connect() as c:
            append(c);c.execute('SELECT 1/0')
    expect('22012',op);same(count_state(),before)
test('failure after complete insertion rolls back source/manifest/event/clock',rollback)

def partial():
    before=count_state()
    def op():
        with connect() as c:
            n=c.execute('UPDATE clocks SET ordinal=ordinal+1 WHERE project_id=%s RETURNING ordinal',(P1,)).fetchone()[0]
            c.execute('INSERT INTO batches VALUES(%s,%s,%s,%s,%s)',(uid(),P1,D1,uid(),n))
    expect('23514',op);same(count_state(),before)
test('deferred completeness rejects batch without manifest/event at COMMIT',partial)

def cross_project():
    before=count_state(P2)
    def op():
        with connect() as c: append(c,p=P2,d=D2,s=S2)
    expect('55000',op);same(count_state(P2),before)
test('foreign project acceptance rejected without writes',cross_project)
test('RLS update cannot reach foreign project clock',lambda:same(query('UPDATE clocks SET ordinal=ordinal+1 WHERE project_id=%s',(P2,)),0))

def archive():
    before=count_state();admin('UPDATE projects SET archived=true WHERE id=%s',(P1,))
    try:
        def op():
            with connect() as c:append(c)
        expect('55000',op);same(count_state(),before)
        same(query('SELECT count(*) FROM batches')[0][0],2)
    finally:admin('UPDATE projects SET archived=false WHERE id=%s',(P1,))
test('archive denies new acceptance while history remains readable',archive)
def unauthorized_archive():
    expect('42501',lambda:query('UPDATE projects SET archived=false'))
test('writer cannot edit archive flag',unauthorized_archive)

for table in ['batches','manifest_entries','selection_events']:
    def owner_dml(t=table):
        expect('55000',lambda:admin(f'UPDATE {t} SET project_id=project_id'))
        expect('55000',lambda:admin(f'DELETE FROM {t}'))
    test('privileged UPDATE/DELETE rejected by immutable trigger: '+table,owner_dml)
    def rls_dml(t=table):
        admin(sql.SQL('GRANT UPDATE,DELETE ON {} TO mto_proof_writer').format(sql.Identifier(t)))
        try:
            same(query(f'UPDATE {t} SET project_id=project_id'),0)
            same(query(f'DELETE FROM {t}'),0)
        finally:admin(sql.SQL('REVOKE UPDATE,DELETE ON {} FROM mto_proof_writer').format(sql.Identifier(t)))
    test('restrictive RLS denies UPDATE/DELETE even if privilege granted: '+table,rls_dml)

def raw_event(prev,step,scope=S1,h='H1',document=D1,ordinal=None,manifest_scope=None):
    with connect() as c:
        n=c.execute('UPDATE clocks SET ordinal=ordinal+1 WHERE project_id=%s RETURNING ordinal',(P1,)).fetchone()[0]
        bid=uid();c.execute('INSERT INTO batches VALUES(%s,%s,%s,%s,%s)',(bid,P1,document,uid(),ordinal or n))
        c.execute('INSERT INTO manifest_entries VALUES(%s,%s,%s,%s,%s)',(P1,bid,document,manifest_scope or scope,h))
        c.execute('INSERT INTO selection_events VALUES(%s,%s,%s,%s,%s,%s,%s,%s)',(uid(),P1,document,scope,h,bid,prev,step))
root_id=query('SELECT id FROM selection_events WHERE step=1')[0][0]
head_id=query('SELECT id FROM selection_events WHERE step=2')[0][0]
def atomic_failure(code,fn):
    before=count_state();expect(code,fn);same(count_state(),before)
test('second root forbidden',lambda:atomic_failure('23505',lambda:raw_event(None,1)))
test('branching predecessor forbidden',lambda:atomic_failure('23505',lambda:raw_event(root_id,2)))
test('skipped local step forbidden',lambda:atomic_failure('23514',lambda:raw_event(head_id,4)))
test('different scope predecessor forbidden',lambda:atomic_failure('23503',lambda:raw_event(head_id,3,scope=S2)))
test('different document predecessor forbidden',lambda:atomic_failure('23503',lambda:raw_event(head_id,3,document=D2)))
test('exact manifest scope FK enforced',lambda:atomic_failure('23503',lambda:raw_event(None,1,scope=S2,manifest_scope=S1)))
test('future predecessor/cycle cannot be inserted',lambda:atomic_failure('23503',lambda:raw_event(uid(),3)))

def backward():
    # Create a legitimate ordinal gap in this synthetic fixture, then try a
    # NEW source identity with an unused but older ordinal. No duplicate-batch
    # or unique-ordinal constraint can mask a missing chronology guard.
    doc=uid()
    with connect() as c:
        c.execute('UPDATE clocks SET ordinal=ordinal+10 WHERE project_id=%s',(P1,))
        target=append(c,d=doc)
    older=count_state()[0]-5
    atomic_failure('23514',lambda:raw_event(target,2,document=doc,ordinal=older))
test('new source with older unused ordinal cannot succeed newer head',backward)

# Deterministic overlap: T1 keeps project lock after insertion; verify T2 is
# actually blocked in PostgreSQL before allowing T1 to COMMIT.
def concurrency(first_rollback=False,stale_expected=False):
    doc=uid();scope=uid();held=threading.Event();release=threading.Event();second_started=threading.Event();out={};errors=[]
    def t1():
        try:
            with connect() as c:
                out['pid1']=c.info.backend_pid;append(c,d=doc,s=scope);held.set()
                if not release.wait(6):raise RuntimeError('release timeout')
                if first_rollback: raise ValueError('deliberate first transaction rollback')
            out['first']='committed'
        except ValueError:out['first']='rolled_back'
        except BaseException as ex:errors.append(repr(ex));held.set()
    def t2():
        try:
            if not held.wait(6):raise RuntimeError('first did not acquire lock')
            with connect() as c:
                out['pid2']=c.info.backend_pid;second_started.set();append(c,d=doc,s=scope,expected=0 if stale_expected else None)
            out['second']='committed'
        except psycopg.Error as ex:out['second']=ex.sqlstate
        except BaseException as ex:errors.append(repr(ex));second_started.set()
    before=count_state();a=threading.Thread(target=t1);b=threading.Thread(target=t2)
    a.start();b.start()
    try:
        assert second_started.wait(6),'second did not start'
        deadline=time.monotonic()+4;blocked=False
        while time.monotonic()<deadline:
            blockers=admin('SELECT pg_blocking_pids(%s)',(out['pid2'],))[0][0]
            if out.get('pid1') in blockers:blocked=True;break
            time.sleep(0.02)
        assert blocked,'no actual PostgreSQL lock contention observed'
        out['verified_blocking']=True
    finally:
        release.set();a.join(10);b.join(10)
    assert not a.is_alive() and not b.is_alive() and not errors,errors
    if stale_expected:
        same(out['second'],'40001');added=1
    else:
        same(out['second'],'committed');added=1 if first_rollback else 2
    same(count_state(),tuple(x+added for x in before))
    steps=query('SELECT step FROM selection_events WHERE document_id=%s ORDER BY step',(doc,))
    same(steps,[(i,) for i in range(1,added+1)])
    return out
concurrency_evidence=[]
for label,kwargs in [('two commits serialize',{}),('rollback releases lock without orphan',{'first_rollback':True}),('concurrent stale writer loses',{'stale_expected':True})]:
    def run(k=kwargs,label=label):concurrency_evidence.append({'scenario':label,**concurrency(**k)})
    test('real connection contention: '+label,run)

def owner_force():
    with psycopg.connect(**DSN,user='postgres') as c:
        c.execute('SET ROLE mto_proof_owner')
        same(c.execute('SELECT count(*) FROM mto_proof.batches').fetchone()[0],0)
test('FORCE RLS also filters the non-bypass table owner',owner_force)

def archived_direct_clock():
    before=count_state();admin('UPDATE projects SET archived=true WHERE id=%s',(P1,))
    try: expect('55000',lambda:query('UPDATE clocks SET ordinal=ordinal+1 WHERE project_id=%s',(P1,)))
    finally:admin('UPDATE projects SET archived=false WHERE id=%s',(P1,))
    same(count_state(),before)
test('archive write guard also covers direct clock mutation',archived_direct_clock)

def cross_source_fk():
    before1=count_state();before2=count_state(P2)
    def op():
        with psycopg.connect(**DSN,user='postgres') as c:
            c.execute('SET search_path=mto_proof,pg_catalog')
            n=c.execute('UPDATE clocks SET ordinal=ordinal+1 WHERE project_id=%s RETURNING ordinal',(P2,)).fetchone()[0]
            bid=uid();c.execute('INSERT INTO batches VALUES(%s,%s,%s,%s,%s)',(bid,P2,D2,uid(),n))
            c.execute('INSERT INTO manifest_entries VALUES(%s,%s,%s,%s,%s)',(P1,bid,D2,S1,'H1'))
    expect('23503',op);same(count_state(),before1);same(count_state(P2),before2)
test('composite source FK rejects real cross-project parent even as admin',cross_source_fk)

def archive_race(archive_first):
    held=threading.Event();release=threading.Event();started=threading.Event();out={};errors=[]
    before=count_state()
    def first():
        try:
            if archive_first:
                with psycopg.connect(**DSN,user='postgres') as c:
                    out['pid1']=c.info.backend_pid
                    c.execute('UPDATE mto_proof.projects SET archived=true WHERE id=%s',(P1,));held.set()
                    if not release.wait(6):raise RuntimeError('release timeout')
            else:
                with connect() as c:
                    out['pid1']=c.info.backend_pid;append(c,d=uid());held.set()
                    if not release.wait(6):raise RuntimeError('release timeout')
        except BaseException as ex:errors.append(repr(ex));held.set()
    def second():
        try:
            if not held.wait(6):raise RuntimeError('first timeout')
            if archive_first:
                with connect() as c:
                    out['pid2']=c.info.backend_pid;started.set();append(c,d=uid())
                out['second']='accepted'
            else:
                with psycopg.connect(**DSN,user='postgres') as c:
                    out['pid2']=c.info.backend_pid;started.set()
                    c.execute('UPDATE mto_proof.projects SET archived=true WHERE id=%s',(P1,))
                out['second']='archived'
        except psycopg.Error as ex:out['second']=ex.sqlstate
        except BaseException as ex:errors.append(repr(ex));started.set()
    a=threading.Thread(target=first);b=threading.Thread(target=second);a.start();b.start()
    try:
        assert started.wait(6)
        blocked=False;deadline=time.monotonic()+4
        while time.monotonic()<deadline:
            if out['pid1'] in admin('SELECT pg_blocking_pids(%s)',(out['pid2'],))[0][0]:blocked=True;break
            time.sleep(0.02)
        assert blocked,'archive race did not actually overlap'
        out['verified_blocking']=True
    finally:
        release.set();a.join(10);b.join(10)
    try:
        assert not a.is_alive() and not b.is_alive() and not errors,errors
        same(out['second'],'55000' if archive_first else 'archived')
        same(count_state(),before if archive_first else tuple(x+1 for x in before))
        # Once archived, all subsequent new acceptance must fail.
        def op():
            with connect() as c:append(c,d=uid())
        expect('55000',op)
    finally:admin('UPDATE projects SET archived=false WHERE id=%s',(P1,))
    concurrency_evidence.append({'scenario':'archive first' if archive_first else 'acceptance first',**out})
test('archive race: archive holds project lock, waiting acceptance rejected',lambda:archive_race(True))
test('archive race: acceptance commits before waiting archive, later writes rejected',lambda:archive_race(False))

summary={'status':'PASS_ISOLATED_POSTGRES_PERSISTENCE_PROOF_ONLY','tests':len(results),'passed':len(results),'failed':0,
 'postgres_version':admin('SHOW server_version')[0][0], 'database':DB,'transport':'private Unix socket; no TCP listener; local trust test only',
 'fsync':admin('SHOW fsync')[0][0],'synchronous_commit':admin('SHOW synchronous_commit')[0][0],'full_page_writes':admin('SHOW full_page_writes')[0][0],
 'source_model':'synthetic minimal source fixtures, NOT Laravel app schema',
 'production_database_used':False,'production_migration':False,'laravel_integration':False,'g01_production_fixed':False,
 'tests_detail':results,'concurrency':concurrency_evidence}
output=os.environ.get('EPCC_PROOF_EVIDENCE')
if output:Path(output).write_text(json.dumps(summary,indent=2)+'\n')
print(json.dumps({k:v for k,v in summary.items() if k not in ['tests_detail','concurrency']},indent=2))
