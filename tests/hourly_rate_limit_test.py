"""Transactional three-per-hour test with concurrency and fixed boundaries."""
import json, os, secrets, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]; PHP=os.environ.get('PHP_BIN',r'C:\xampp\php\php.exe'); DB='pasig_count_test_'+secrets.token_hex(8); F=ROOT/'tests/client_count_fixture.php'; FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0; H='b'*64
def run(a,*x): return subprocess.check_output([PHP,str(F),a,DB,*x],cwd=ROOT,text=True,creationflags=FLAGS).strip()
try:
 run('setup')
 assert json.loads(run('rate_reserve',H,'2026-10-08 10:00:00'))['allowed']
 assert json.loads(run('rate_reserve',H,'2026-10-08 10:01:01'))['allowed']
 workers=[subprocess.Popen([PHP,str(F),'rate_reserve',DB,H,'2026-10-08 10:02:02'],cwd=ROOT,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,creationflags=FLAGS) for _ in range(2)]
 results=[json.loads(w.communicate(timeout=20)[0]) for w in workers]
 assert sum(bool(r['allowed']) for r in results)==1 and max(int(r['hourly_count']) for r in results)==3,results
 assert not json.loads(run('rate_reserve',H,'2026-10-08 10:59:59'))['allowed']
 boundary=json.loads(run('rate_reserve',H,'2026-10-08 11:00:00')); assert boundary['allowed'] and boundary['hourly_count']==1,boundary
 print('PASS: concurrent fourth request blocked and exact one-hour boundary starts a new window.')
finally: run('cleanup')
