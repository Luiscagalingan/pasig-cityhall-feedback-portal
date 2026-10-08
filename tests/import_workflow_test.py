"""HTTP CSV import/manual-entry regression test using a disposable MySQL database."""
import http.cookiejar, json, os, re, secrets, socket, subprocess, tempfile, time, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]; PHP=os.environ.get('PHP_BIN',r'C:\xampp\php\php.exe'); DB='pasig_count_test_'+secrets.token_hex(8); FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
def fixture(a): return subprocess.check_output([PHP,str(ROOT/'tests/client_count_fixture.php'),a,DB],cwd=ROOT,text=True,creationflags=FLAGS)
def token(s): return re.search(r'name="csrf_token" value="([^"]+)',s).group(1)
class Browser:
 def __init__(self): self.o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,p,data=None,headers=None):
  q=urllib.request.Request(BASE+p,data=data,headers=headers or {}); r=self.o.open(q,timeout=30); return r.read().decode()
 def form(self,p,d): return self.req(p,urllib.parse.urlencode(d).encode())
server=None
with tempfile.TemporaryDirectory(prefix='pasig-import-') as temp:
 try:
  fixture('setup'); s=socket.socket(); s.bind(('127.0.0.1',0)); port=s.getsockname()[1]; s.close(); BASE=f'http://127.0.0.1:{port}/{ROOT.name}'
  env=dict(os.environ,PASIG_DB_NAME=DB,PASIG_STORAGE_PATH=str(Path(temp)/'storage'))
  server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT.parent)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=FLAGS)
  for _ in range(100):
   try: socket.create_connection(('127.0.0.1',port),.1).close(); break
   except OSError: time.sleep(.05)
  b=Browser(); p=b.req('/login.php'); b.form('/login.php',{'csrf_token':token(p),'identity':'other_admin','password':'Workflow123!'})
  p=b.req('/office/data.php?office_id=1'); t=token(p)
  b.form('/office/data.php?office_id=1',{'csrf_token':t,'office_id':1,'action':'manual','visit_date':'2001-02-01','sex':'Female','age':30,'client_type':'City Government Employee','service_received':'Manual valid','timeliness':4,'client_handling':4,'quality':4,'overall':4,'comment':'Manual workflow test'})
  snap=json.loads(fixture('import_inspect')); manual=next(x for x in snap['feedback'] if x['service_received']=='Manual valid')
  assert manual['source']=='manual_entry' and float(manual['rating_percent'])==100
  assert float(manual['final_score'])==round(90+float(manual['comment_score'])*.1,2)
  p=b.req('/office/data.php?office_id=1'); t=token(p)
  csv=('visit_date,sex,age,client_type,service_received,timeliness,client_handling,quality_of_service,overall_satisfaction,comment,assisted_by\r\n'
       '2001-03-01,Female,30,City Government Employee,Import valid,3,3,3,3,Okay service,Test Staff\r\n'
       '2001-03-02,Female,30,City Government Employee,Import invalid,5,3,3,3,Bad row,Test Staff\r\n').encode()
  boundary='----pasig'+secrets.token_hex(8); parts=[]
  for k,v in [('csrf_token',t),('office_id','1'),('action','preview')]: parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()]
  parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="csv_file"; filename="test.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode(),csv,f'\r\n--{boundary}--\r\n'.encode()]
  preview=b.req('/office/data.php?office_id=1',b''.join(parts),{'Content-Type':f'multipart/form-data; boundary={boundary}'})
  assert 'Valid Rows</small><strong>1' in preview and 'Rejected Rows</small><strong>1' in preview
  pt=re.search(r'name="preview_token" value="([^"]+)',preview).group(1)
  b.form('/office/data.php?office_id=1',{'csrf_token':token(preview),'office_id':1,'action':'confirm','preview_token':pt})
  snap=json.loads(fixture('import_inspect')); row=next(x for x in snap['feedback'] if x['service_received']=='Import valid')
  assert int(row['assisted_by_user_id'])==4 and float(row['rating_percent'])==66.67 and int(snap['batches'][-1]['rejected_rows'])==1, (row,snap)
  assert float(row['final_score'])==round(66.67*.9+float(row['comment_score'])*.1,2)
  page=b.req('/office/data.php?office_id=1'); batch=snap['batches'][-1]['id']; b.form('/office/data.php?office_id=1',{'csrf_token':token(page),'office_id':1,'action':'rollback','batch_id':batch})
  snap=json.loads(fixture('import_inspect')); assert not any(x['service_received']=='Import valid' for x in snap['feedback']) and snap['batches'][-1]['rolled_back_at']
  print('PASS: manual entry plus CSV preview, invalid row, confirmation, attribution, scoring, storage and rollback.')
 finally:
  if server: server.terminate(); server.wait(10)
  fixture('cleanup')
