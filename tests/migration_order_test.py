"""New application fails closed when duplicate/rate migration has not run."""
import http.cookiejar,os,re,secrets,socket,subprocess,tempfile,time,urllib.parse,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN',r'C:\xampp\php\php.exe');DB='pasig_count_test_'+secrets.token_hex(8);F=ROOT/'tests/client_count_fixture.php';FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
def fx(a):return subprocess.check_output([PHP,str(F),a,DB],cwd=ROOT,text=True,creationflags=FLAGS).strip()
server=None
with tempfile.TemporaryDirectory(prefix='pasig-migration-') as temp:
 try:
  fx('setup');fx('drop_guard_tables');s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close();base=f'http://127.0.0.1:{port}/{ROOT.name}';env=dict(os.environ,PASIG_DB_NAME=DB,PASIG_STORAGE_PATH=str(Path(temp)/'storage'));server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT.parent)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=FLAGS)
  for _ in range(100):
   try:socket.create_connection(('127.0.0.1',port),.1).close();break
   except OSError:time.sleep(.05)
  o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));page=o.open(base+'/survey.php?office=TEST').read().decode();t=re.search(r'name="csrf_token" value="([^"]+)',page).group(1)
  data={'csrf_token':t,'office_code':'TEST','visit_date':'2026-10-08','sex':'Female','age':30,'client_type':'City Government Employee','service_received':'Migration guard','timeliness':4,'client_handling':4,'quality':4,'overall':4,'comment':'','consent':1}
  body=o.open(base+'/survey.php?office=TEST',urllib.parse.urlencode(data).encode()).read().decode()
  assert 'Pansamantalang hindi available ang pagsusumite' in body and 'submitted successfully' not in body
  print('PASS: application-before-migration fails closed with a recoverable public message and no success response.')
 finally:
  if server:server.terminate();server.wait(10)
  fx('cleanup')
