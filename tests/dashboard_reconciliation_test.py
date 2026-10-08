"""Reconcile dashboard aggregations with direct SQL in disposable data."""
import json, os, secrets, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]; PHP=os.environ.get('PHP_BIN',r'C:\xampp\php\php.exe'); DB='pasig_count_test_'+secrets.token_hex(8); F=ROOT/'tests/client_count_fixture.php'; FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
def run(a): return subprocess.check_output([PHP,str(F),a,DB],cwd=ROOT,text=True,creationflags=FLAGS).strip()
try:
 run('setup'); run('output_setup'); result=json.loads(run('dashboard_reconcile'))
 assert result['direct']==result['metrics']==result['ages']==result['services'],result
 print('PASS: direct feedback count, dashboard total, age chart and service summary reconcile under identical office/date filters.')
finally: run('cleanup')
