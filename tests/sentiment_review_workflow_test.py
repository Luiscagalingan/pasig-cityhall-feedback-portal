"""Sentiment correction and review/action interaction in a disposable database."""
import http.cookiejar,json,os,re,secrets,socket,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]; PHP=os.environ.get('PHP_BIN',r'C:\xampp\php\php.exe'); DB='pasig_count_test_'+secrets.token_hex(8); F=ROOT/'tests/client_count_fixture.php'; FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
def fixture(a,*x): return subprocess.check_output([PHP,str(F),a,DB,*map(str,x)],cwd=ROOT,text=True,creationflags=FLAGS).strip()
def csrf(p): return re.search(r'name="csrf_token" value="([^"]+)',p).group(1)
class B:
 def __init__(self): self.o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,p,d=None):
  try:r=self.o.open(BASE+p,None if d is None else urllib.parse.urlencode(d).encode(),timeout=20)
  except urllib.error.HTTPError as e:r=e
  with r:return r.status,r.read().decode()
 def login(self,u): p=self.req('/login.php')[1]; return self.req('/login.php',{'csrf_token':csrf(p),'identity':u,'password':'Workflow123!'})
server=None
with tempfile.TemporaryDirectory(prefix='pasig-review-') as temp:
 try:
  fixture('setup'); fid=int(fixture('review_setup')); s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close();BASE=f'http://127.0.0.1:{port}/{ROOT.name}'
  env=dict(os.environ,PASIG_DB_NAME=DB,PASIG_STORAGE_PATH=str(Path(temp)/'storage'));server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT.parent)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=FLAGS)
  for _ in range(100):
   try:socket.create_connection(('127.0.0.1',port),.1).close();break
   except OSError:time.sleep(.05)
  admin,head,staff=B(),B(),B();admin.login('other_admin');head.login('head');staff.login('staff')
  page=admin.req('/review.php?status=needs_review')[1]; assert 'Review fixture' in page
  assert staff.req('/review.php')[0]==403
  assert head.req('/review.php',{'action':'correct','feedback_id':fid,'sentiment':'negative','reviewer_notes':'Unauthorized'})[0]==403
  admin.req('/review.php',{'csrf_token':csrf(page),'action':'correct','feedback_id':fid,'sentiment':'negative','reviewer_notes':'Verified negative fixture'})
  snap=json.loads(fixture('review_inspect',fid)); f=snap['feedback']; a=snap['action']
  assert f['sentiment']=='negative' and f['sentiment_source']=='manual' and f['review_status']=='reviewed' and int(f['reviewed_by_user_id'])==2 and f['reviewed_at'] and float(f['comment_score'])==0 and float(f['final_score'])==60
  assert a['status']=='needs_action' and a['completed_at'] is None
  actions=admin.req('/admin/actions.php')[1]; aid=a['id']; admin.req('/admin/actions.php',{'csrf_token':csrf(actions),'action_id':aid,'status':'in_progress','resolution_notes':'Working fixture'})
  actions=admin.req('/admin/actions.php')[1]; admin.req('/admin/actions.php',{'csrf_token':csrf(actions),'action_id':aid,'status':'completed','resolution_notes':'Completed fixture'})
  assert json.loads(fixture('review_inspect',fid))['action']['status']=='completed'
  page=admin.req('/review.php?status=reviewed')[1]; admin.req('/review.php',{'csrf_token':csrf(page),'action':'correct','feedback_id':fid,'sentiment':'positive','reviewer_notes':'Repeated verified positive'})
  snap=json.loads(fixture('review_inspect',fid)); assert snap['feedback']['sentiment']=='positive' and float(snap['feedback']['final_score'])==70 and snap['action']['status']=='completed'
  assert 'Review fixture' not in admin.req('/review.php?status=needs_review')[1]
  print('PASS: correction metadata, recalculation, queue removal, persistence, authorization, reopening and action lifecycle.')
 finally:
  if server:server.terminate();server.wait(10)
  fixture('cleanup')
