"""Local integration runner. Requires initialized, disposable MySQL/MariaDB data directory.
No system service is installed. No real SMS is sent. All HTTP servers bind to loopback.
"""
from pathlib import Path
import argparse,subprocess,socket,time,secrets,json,shutil,urllib.request,urllib.error,http.cookiejar,hashlib,sys,os

parser=argparse.ArgumentParser()
parser.add_argument('--php',required=True)
parser.add_argument('--db-server',required=True)
parser.add_argument('--db-data',required=True)
parser.add_argument('--work-dir',required=True)
parser.add_argument('--preview-seconds',type=int,default=0)
args=parser.parse_args()
source=Path(__file__).resolve().parent.parent
php=Path(args.php).resolve();server=Path(args.db_server).resolve();data=Path(args.db_data).resolve()
if (php.parent/'extras/ssl/openssl.cnf').exists():os.environ['OPENSSL_CONF']=str(php.parent/'extras/ssl/openssl.cnf')
scratch=Path(args.work_dir).resolve()/('morshed-test-'+secrets.token_hex(6));scratch.mkdir(parents=True)
app=scratch/'app';shutil.copytree(source,app)
def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
dbport,webport,adminport=port(),port(),port()
processes=[];logs=[];checks=[]
ini=scratch/'php.ini'
ini.write_text('extension_dir="'+str(php.parent/'ext').replace('\\','/')+'"\nextension=pdo_mysql\nextension=openssl\nextension=curl\nextension=fileinfo\ndisplay_errors=0\nlog_errors=1\n',encoding='utf-8')
phpcmd=[str(php),'-c',str(ini)]
token=secrets.token_hex(32)
cfg={'environment':'testing','app_key':secrets.token_hex(32),'origins':{'site':f'http://127.0.0.1:{webport}','admin':f'http://127.0.0.1:{adminport}'},
     'allow_http_loopback':True,'pretty_urls':False,'database':{'dsn':f'mysql:host=127.0.0.1;port={dbport};dbname=morshed_test_{secrets.token_hex(8)};charset=utf8mb4','user':'root','password':''},
     'setup':{'enabled':True,'token_sha256':hashlib.sha256(token.encode()).hexdigest()},'sms':{'enabled':False},
     'test_setup_token':token,'test_fixture_path':str(scratch/'fixture.json')}
config=scratch/'private-config.json';config.write_text(json.dumps(cfg),encoding='utf-8')
configphp=scratch/'private-config.php';configphp.write_text("<?php return json_decode(file_get_contents(__DIR__.'/private-config.json'),true,512,JSON_THROW_ON_ERROR);",encoding='utf-8')
local=app/'morshed-core/config/local.php';local.write_text("<?php return json_decode(file_get_contents('"+str(config).replace('\\','/')+"'),true,512,JSON_THROW_ON_ERROR);",encoding='utf-8')
def wait_port(p):
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1',p),timeout=.15):return
        except OSError:time.sleep(.1)
    raise RuntimeError('Local test server did not start; inspect private scratch logs.')
def start(cmd,name):
    out=open(scratch/(name+'.log'),'wb');logs.append(out)
    process=subprocess.Popen(cmd,stdout=out,stderr=subprocess.STDOUT);processes.append(process);return process
def php_run(file):
    r=subprocess.run(phpcmd+[str(app/'tests'/file),str(configphp)],capture_output=True,text=True,encoding='utf-8')
    print(r.stdout,end='')
    if r.returncode:raise RuntimeError('PHP test failed: '+r.stderr[:1500])
def race(job):
    workers=[subprocess.Popen(phpcmd+[str(app/'tests/race_worker.php'),str(configphp)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf-8') for _ in range(2)]
    # Both processes exist before either result is collected; each has an independent DB connection.
    for worker in workers:worker.stdin.write(json.dumps(job));worker.stdin.close()
    replies=[]
    for worker in workers:
        text=worker.stdout.read();err=worker.stderr.read();worker.wait(timeout=30)
        if worker.returncode:raise RuntimeError('Race worker failed: '+err[:300])
        replies.append(json.loads(text))
    assert sum(x['ok'] for x in replies)==1,replies
    return replies
class Client:
    def __init__(self,base):
        self.base=base;self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def request(self,path,body=None,csrf=None,headers=None):
        route,_,query=path.partition('?')
        url=self.base+'/index.php?r='+urllib.parse.quote(route,safe='')+('&'+query if query else '')
        h=dict(headers or {})
        if body is not None:h['Content-Type']='application/json'
        if csrf is not None:h['X-CSRF-Token']=csrf
        request=urllib.request.Request(url,data=None if body is None else json.dumps(body).encode(),headers=h)
        try:r=self.opener.open(request,timeout=10)
        except urllib.error.HTTPError as error:r=error
        return r.status,r.read().decode('utf-8'),r.headers
    def post(self,path,body):
        status,text,_=self.request('/api/v1/csrf');assert status==200,text
        return self.request('/api/v1'+path,body,json.loads(text)['csrf'])
def assert_status(client,path,status,body=None):
    got,text,_=client.request(path,body);assert got==status,(path,got,text[:100]);return text
def ok(name):checks.append(name);print('PASS '+name)

try:
    start([str(server),'--defaults-file='+str(data/'my.ini'),'--bind-address=127.0.0.1','--port='+str(dbport),'--console','--skip-log-bin','--innodb-buffer-pool-size=32M'],'db')
    wait_port(dbport)
    # Only the random database created by this runner; no existing schema is touched.
    create=scratch/'create.php'
    create.write_text("<?php $c=require $argv[1];$dsn=$c['database']['dsn'];preg_match('/dbname=(morshed_test_[a-f0-9]+);/',$dsn,$m);if(!$m)exit(1);$pdo=new PDO(preg_replace('/dbname=[^;]+;/','',$dsn),'root','');$pdo->exec('CREATE DATABASE `'.$m[1].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');echo $pdo->query('SELECT VERSION()')->fetchColumn();",encoding='utf-8')
    version=subprocess.check_output(phpcmd+[str(create),str(configphp)],text=True)
    print('DATABASE TEST ENGINE: '+version)
    php_run('prepare.php')
    replies=race({'operation':'setup'});assert any(x.get('code')=='SETUP_LOCKED' for x in replies);ok('two concurrent initial-admin requests produce one administrator')
    php_run('integration.php')
    php_run('product.php')
    php_run('external.php')
    php_run('delivery.php')
    php_run('tsms_gateway.php')
    php_run('release03.php')
    fixture=json.loads(Path(cfg['test_fixture_path']).read_text(encoding='utf-8'))
    replies=race(fixture['race_job']);assert any(x.get('code')=='INVALID_CODE' for x in replies);ok('two concurrent OTP verifications consume code only once')
    replies=race(fixture['booking_race']);assert any(x.get('code')=='SLOT_UNAVAILABLE' for x in replies);ok('two concurrent reservation requests cannot consume the same capacity')
    for realm,root,p in [('site','morshedapp.ir',webport),('admin','admin.morshedapp.ir',adminport)]:
        start(phpcmd+['-S',f'127.0.0.1:{p}','-t',str(app/root),str(app/root/'index.php')],realm);wait_port(p)
    site=Client(cfg['origins']['site']);adminclient=Client(cfg['origins']['admin']);content=Client(cfg['origins']['admin'])
    guest=Client(cfg['origins']['site'])
    status,home,headers=guest.request('/');assert status==200 and 'Location' not in headers and 'صاحب‌تجربه‌های اربعین' in home and 'card-rail' in home
    assert '<nav class="bottom"' in home and home.count('aria-hidden="true"')>=5
    assert_status(guest,'/api/v1/me',401);ok('guest opens real public storefront with five navigation icons and no forced authentication')
    for suffix in ['', '?mode=register', '?method=otp']:
        html=assert_status(guest,'/login'+suffix,200);assert 'auth-card' in html and 'auth-tabs' in html and 'ورود با ایتا' in html
    ok('teal login signup and OTP pages render with real server forms')
    otpclient=Client(cfg['origins']['site']);otpclient.request('/api/v1/csrf')
    sid=next(c.value for c in otpclient.jar if c.name=='morshed_dev_csrf_site')
    otp=json.loads(subprocess.check_output(phpcmd+[str(app/'tests/otp_fixture.php'),str(configphp),sid],text=True,encoding='utf-8'))
    html=assert_status(otpclient,'/auth/otp/code',200);assert 'otp-card' in html and 'maxlength="4"' in html and html.count('class="otp-digit"')==4 and '4719' not in html
    status,body,_=guest.post('/auth/otp/resend',{'challenge_id':otp['challenge_id']});assert status==403 and 'INVALID_CODE' in body
    status,body,_=otpclient.post('/auth/otp/resend',{'challenge_id':otp['challenge_id']});assert status==429 and 'RESEND_COOLDOWN' in body
    status,body,_=otpclient.post('/auth/otp/verify',{'challenge_id':otp['challenge_id'],'code':otp['code']});assert status==200 and 'complete' in json.loads(body)['redirect']
    status,body,_=otpclient.post('/auth/otp/verify',{'challenge_id':otp['challenge_id'],'code':otp['code']});assert status==400
    ok('four-digit OTP UI authenticates through the real server, prevents replay and binds resend to its browser')

    assert_status(guest,'/api/v1/auth/eitaa/context',419,{'init_data':'forged'})
    status,body,_=guest.post('/auth/eitaa/context',{'init_data':'forged'});assert status==503
    cfg['eitaa']={'enabled':True,'live_verified':True,'app_token':'test-only-eitaa-token-123456'};config.write_text(json.dumps(cfg),encoding='utf-8')
    status,home,headers=guest.request('/');assert status==200 and 'developer.eitaa.com' in headers['Content-Security-Policy'] and 'eitaa-context' in home
    status,body,_=guest.post('/auth/eitaa/context',{'init_data':'hash=forged'});assert status==401
    assert_status(guest,'/api/v1/me',401)
    status,body,_=guest.post('/auth/eitaa/start',{'purpose':'login','init_data':'hash=forged'});assert status==401 and 'INVALID_ASSERTION' in body
    assert_status(guest,'/api/v1/me',401);ok('Eitaa launch context never authenticates and forged cached data cannot create a session')
    cfg.pop('eitaa');config.write_text(json.dumps(cfg),encoding='utf-8')
    assert_status(site,'/api/v1/me',401);assert_status(adminclient,'/api/v1/users',401);ok('both realms reject unauthenticated API access')
    assert_status(site,'/api/v1/auth/password',419,{'phone':fixture['phone'],'password':fixture['password']});ok('POST without CSRF is rejected')
    status,body,_=site.request('/api/v1/me/profile',[]);assert status==400 and json.loads(body)['error']['code']=='INVALID_JSON';ok('non-object JSON receives a safe structured validation error')
    status,body,_=site.post('/auth/password',{'phone':fixture['phone'],'password':fixture['password']});assert status==200,(status,body)
    status,body,_=site.request('/api/v1/me');assert json.loads(body)['user']['id']==fixture['user_id'];ok('real HTTP password login creates usable site session')
    assert_status(site,'/api/v1/sessions',409)
    status,body,_=site.post('/account/complete',{'username':'primary_test_user','current_password':fixture['password'],'new_password':fixture['password'],'first_name':'علی','last_name':'آزمون','province':'تهران','city':'تهران','gender':'male'});assert status==200,(status,body)
    ok('incomplete account cannot use protected services before completing local credentials')
    redirectclient=Client(cfg['origins']['site']);redirectclient.request('/favicon.ico')
    status,body,_=redirectclient.request('/api/v1/csrf');redirectcsrf=json.loads(body)['csrf']
    form=urllib.parse.urlencode({'csrf':redirectcsrf,'login':'manual_release03','password':'Eight123'}).encode()
    response=redirectclient.opener.open(urllib.request.Request(redirectclient.base+'/index.php?r=%2Fauth%2Fpassword',data=form,headers={'Content-Type':'application/x-www-form-urlencoded'}))
    assert '%2Faccount' in response.url and 'حساب من' in response.read().decode('utf-8')
    ok('browser favicon requests cannot replace the destination after username login')
    sitecookie=next(c.value for c in site.jar if c.name=='morshed_dev_site')
    request=urllib.request.Request(cfg['origins']['admin']+'/index.php?r=%2Fapi%2Fv1%2Fme',headers={'Cookie':'morshed_dev_admin='+sitecookie})
    try:urllib.request.urlopen(request);raise AssertionError('Realm escalation accepted')
    except urllib.error.HTTPError as e:assert e.code==401
    ok('copying site auth token into admin cookie cannot escalate access')
    status,body,_=adminclient.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']});assert status==200,(status,body)
    status,body,_=site.request('/api/v1/csrf');site_csrf=json.loads(body)['csrf']
    status,body,_=adminclient.request('/api/v1/legal/create',{'title':'cross-realm','body':'cross-realm'},site_csrf);assert status==419;ok('site CSRF token is invalid in the admin realm')
    status,body,_=site.request('/api/v1/me/profile',{'display_name':'origin-attack'},site_csrf,{'Origin':'https://invalid.example'});assert status==419;ok('foreign Origin cannot mutate an authenticated profile')
    for path in ['/','/security','/users','/users/new','/experience/edit','/admins','/legal','/audit','/settings']:
        assert_status(adminclient,path,200)
    html=assert_status(adminclient,'/login',200);assert 'راه‌اندازی اولین مدیر با کلید نصب' not in html
    assert_status(adminclient,'/setup',404)
    status,body,_=site.post('/me/phone/request',{'phone':'09120001111','current_password':fixture['password']});assert status==403 and 'PHONE_READ_ONLY' in body
    status,body,_=adminclient.post('/upgrade',{'current_password':'invalid'});assert status==403
    status,body,_=adminclient.post('/upgrade',{'current_password':fixture['admin_password']});assert status==200
    ok('completed setup is hidden, existing phone is read-only and upgrades require admin proof')
    ok('all implemented admin pages render using live database')
    status,body,_=content.post('/auth/password',{'username':fixture['content_username'],'password':fixture['content_password']});assert status==200,(status,body)
    assert_status(content,'/api/v1/users',403)
    status,body,_=content.post('/legal/create',{'title':'forbidden','body':'forbidden'});assert status==403,(status,body);ok('content admin denied system-only API operations')
    evil='<script>alert(1)</script>'
    status,body,_=site.post('/me/profile',{'first_name':evil,'last_name':'آزمون','province':'تهران','city':'تهران','gender':'male','phone':'09120009999','role':'system_admin','user_id':'ffffffffffffffffffffffffffffffff'});assert status==200,(status,body)
    html=assert_status(site,'/account',200);assert '&lt;script&gt;alert(1)&lt;/script&gt;' in html and evil not in html
    status,body,_=site.request('/api/v1/me');assert json.loads(body)['user']['id']==fixture['user_id'];ok('profile ignores injected ownership/role and escapes stored HTML')
    for path in ['/bookings','/sessions/join','/sessions/archive','/oauth/google','/auth/eitaa']:
        status,body,_=site.post(path,{});assert status==503,(path,status,body)
    status,body,_=adminclient.post('/legal/recording/publish',{});assert status==503,(status,body)
    ok('booking media archive external auth and recording publication remain server-blocked')
    status,body,_=site.post('/auth/otp/request',{'phone':'09124444444','purpose':'login'});assert status==503 and 'SMS_UNAVAILABLE' in body
    assert 'challenge_id' not in body;ok('disabled SMS cannot fake successful code delivery')
    for client in [site,adminclient]:
        for cookie in client.jar:
            assert cookie.domain_specified is False
            assert cookie.has_nonstandard_attr('HttpOnly')
    ok('auth and CSRF cookies are HttpOnly and host-only in local test mode')
    html=assert_status(site,'/login',200)
    import re
    ids=re.findall(r'\bid="([^"]+)"',html);assert len(ids)==len(set(ids));ok('login form labels have unique input IDs')
    status,text,headers=site.request('/api/v1/me');assert 'no-store' in headers['Cache-Control'];assert "frame-ancestors 'none'" in headers['Content-Security-Policy'];ok('private responses disable cache and restrict framing')
    status,text,_=site.request('/api/v1/me',headers={'Host':'invalid.example'});assert status==400;ok('unconfigured Host is rejected before account access')
    for realm in ['site','admin']:
        origin=cfg['origins'][realm]
        with urllib.request.urlopen(origin+'/assets/Vazirmatn.woff2') as r:assert r.read(4)==b'wOF2'
    ok('both public roots serve local Vazirmatn font')
    product=fixture['product']
    for path in ['/','/mentors','/experiences','/about','/mentor/profile?id='+product['mentor_id'],'/experience?id='+product['experience_id']]:
        html=assert_status(site,path,200);assert 'سامانه موقتاً' not in html
    status,body,_=site.request('/api/v1/mentors?term='+product['topic_id']+'&q='+urllib.parse.quote('كلاس'));assert len(json.loads(body)['rows'])==1
    ok('public home search mentor profile and experience use published database content')
    for path in ['/mentors','/taxonomy','/experiences','/experience?id='+product['version_id'],'/storefront','/bookings','/booking?id='+product['booking_id'],'/support','/ticket?id='+product['ticket_id'],'/archives','/organizations','/reports','/general','/notification-templates','/notification-deliveries']:
        html=assert_status(adminclient,path,200);assert 'سامانه موقتاً' not in html
    ok('every product administration page renders against live tables')
    assert_status(content,'/api/v1/mentors',403);assert_status(content,'/api/v1/support',403)
    assert_status(content,'/experiences',200);assert_status(content,'/taxonomy',200);assert_status(content,'/storefront',200)
    html=assert_status(content,'/',200);assert 'dashboard-chart' not in html and 'آخرین درخواست‌های گفت‌وگو' not in html
    ok('content administrator can moderate content but cannot access system operations')
    for path in ['/api/v1/booking?id='+product['booking_id'],'/api/v1/session?id='+product['booking_id'],'/api/v1/ticket?id='+product['ticket_id']]:assert_status(site,path,404)
    assert_status(adminclient,'/api/v1/archive?id='+product['booking_id'],403)
    status,body,_=site.post('/bookings/create',{'mentor_id':product['mentor_id']});assert status==503 and 'BOOKING_CLOSED' in body
    ok('ownership and explicit archive grants protect actual product HTTP routes')
    mentorclient=Client(cfg['origins']['site']);ownerclient=Client(cfg['origins']['site'])
    for client,phone,password in [(mentorclient,product['mentor_phone'],product['mentor_password']),(ownerclient,product['owner_phone'],product['owner_password'])]:
        status,body,_=client.post('/auth/password',{'phone':phone,'password':password});assert status==200,(status,body)
        status,body,_=client.post('/account/complete',{'username':'user_'+phone,'current_password':password,'new_password':password,'first_name':'کاربر','last_name':'آزمون','province':'تهران','city':'تهران','gender':'unspecified'});assert status==200,(status,body)
    for path in ['/mentor','/mentor/experiences','/mentor/experience','/mentor/experience?id='+product['experience_id'],'/mentor/calendar','/sessions','/booking?id='+product['booking_id'],'/session?id='+product['booking_id']]:assert_status(mentorclient,path,200)
    ok('mentor account renders only its own real panel calendar experiences and sessions')
    status,body,_=mentorclient.post('/mentor/experience/save',{'title':'<img src=x onerror=alert(1)>','problem':'آزمون متن پایدار','context':'شرایط آزمون','actions':'اقدام آزمون','results':'نتیجه آزمون','lessons':'آموخته آزمون','visibility':'public','owner_approve':'1','term_id':product['topic_id']});assert status==200,(status,body);created=json.loads(body)
    assert_status(site,'/api/v1/experience?id='+created['experience_id'],404)
    status,body,_=mentorclient.post('/mentor/experience/submit',{'id':created['id']});assert status==200,(status,body)
    status,body,_=content.post('/experience/review',{'id':created['id'],'decision':'approved','note':''});assert status==200,(status,body)
    html=assert_status(site,'/experience?id='+created['experience_id'],200);assert '&lt;img src=x onerror=alert(1)&gt;' in html and '<img src=x' not in html
    ok('HTTP experience draft submission moderation publication and stored-XSS escaping work end to end')
    for path in ['/auth/google/start','/auth/eitaa/start']:
        status,body,_=site.post(path,{'purpose':'login'});assert status==503 and 'PROVIDER_DISABLED' in body
    ok('real external-auth routes stay disabled without verified provider configuration')
    def control(operation):
        r=subprocess.run(phpcmd+[str(app/'tests/fixture_control.php'),str(configphp),operation],capture_output=True,text=True,encoding='utf-8');assert r.returncode==0,r.stderr
    control('start');control('failure')
    status,body,_=ownerclient.request('/api/v1/csrf');csrf=json.loads(body)['csrf']
    retained='متن پیام باید هنگام شکست ذخیره حفظ شود'
    form=urllib.parse.urlencode({'csrf':csrf,'id':product['booking_id'],'client_key':'http-storage-failure-key','body':retained}).encode()
    request=urllib.request.Request(ownerclient.base+'/index.php?r=%2Fsession%2Fmessage',data=form,headers={'Content-Type':'application/x-www-form-urlencoded'})
    try:r=ownerclient.opener.open(request)
    except urllib.error.HTTPError as error:r=error
    html=r.read().decode('utf-8');assert r.status==503 and retained in html and 'data-durable-message' in html,(r.status,html[:100])
    control('restore')
    status,body,_=ownerclient.post('/session/message',{'id':product['booking_id'],'client_key':'http-storage-failure-key','body':retained});assert status==200,(status,body);message=json.loads(body)
    status,body,_=ownerclient.post('/session/message',{'id':product['booking_id'],'client_key':'http-storage-failure-key','body':retained});assert status==200 and json.loads(body)['id']==message['id']
    ok('HTTP storage failure preserves message text and retry acknowledges exactly one durable message')
    status,body,_=ownerclient.request('/api/v1/csrf');csrf=json.loads(body)['csrf'];boundary='morshed-test-'+secrets.token_hex(8)
    pieces=[]
    for key,value in {'csrf':csrf,'id':product['booking_id']}.items():pieces.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    image_bytes=(app/'morshedapp.ir/assets/morshed-logo.png').read_bytes()
    pieces.append(f'--{boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+image_bytes+b'\r\n');pieces.append(f'--{boundary}--\r\n'.encode())
    request=urllib.request.Request(ownerclient.base+'/index.php?r=%2Fapi%2Fv1%2Fsession%2Ffiles',data=b''.join(pieces),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    try:r=ownerclient.opener.open(request)
    except urllib.error.HTTPError as error:r=error
    body=r.read().decode('utf-8');assert r.status==200,(r.status,body);fileid=json.loads(body)['file_id']
    with ownerclient.opener.open(ownerclient.base+'/index.php?r=%2Ffiles%2Fdownload&id='+fileid) as r:
        assert r.headers['Content-Type']=='application/octet-stream';assert 'attachment' in r.headers['Content-Disposition'];assert hashlib.sha256(r.read()).digest()==hashlib.sha256(image_bytes).digest()
    assert_status(site,'/api/v1/files/download?id='+fileid,404);assert_status(adminclient,'/api/v1/files/download?id='+fileid,403)
    control('stop')
    ok('real multipart upload private storage integrity and authorized download pass without exposing files publicly')
    # Content assets have different publication rules from private session attachments.
    status,body,_=mentorclient.request('/api/v1/csrf');csrf=json.loads(body)['csrf'];boundary='content-test-'+secrets.token_hex(8)
    payload=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="evidence.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+image_bytes+f'\r\n--{boundary}--\r\n'.encode())
    request=urllib.request.Request(mentorclient.base+'/index.php?r=%2Fapi%2Fv1%2Fmentor%2Fevidence',data=payload,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    try:r=mentorclient.opener.open(request)
    except urllib.error.HTTPError as error:r=error
    body=r.read().decode('utf-8');assert r.status==200,(r.status,body);assetid=json.loads(body)['asset_id']
    assert_status(site,'/api/v1/content/asset?id='+assetid,404)
    status,body,_=mentorclient.post('/mentor/experience/save',{'experience_id':created['experience_id'],'base_version_id':created['id'],'title':'مستند عمومی','problem':'متن تجربه با مستند','context':'شرایط آزمون','actions':'اقدام آزمون','results':'نتیجه آزمون','lessons':'آموخته آزمون','visibility':'public','owner_approve':'1','asset_ids':[assetid]});assert status==200,(status,body);version=json.loads(body)['id']
    status,body,_=mentorclient.post('/mentor/experience/submit',{'id':version});assert status==200,(status,body)
    status,body,_=content.post('/experience/review',{'id':version,'decision':'approved','note':''});assert status==200,(status,body)
    with site.opener.open(site.base+'/index.php?r=%2Fcontent%2Fasset&id='+assetid) as r:assert r.headers['Content-Type']=='image/png' and r.read()==image_bytes
    status,body,_=content.post('/experience/unpublish',{'id':created['experience_id']});assert status==200,(status,body)
    assert_status(site,'/api/v1/content/asset?id='+assetid,404)
    ok('public evidence becomes readable only with its approved version and disappears on unpublication')
    request=urllib.request.Request(mentorclient.base+'/index.php?r=%2Fapi%2Fv1%2Fmentor%2Favatar',data=payload,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    try:r=mentorclient.opener.open(request)
    except urllib.error.HTTPError as error:r=error
    body=r.read().decode('utf-8');assert r.status==200 and json.loads(body)['asset_id']==assetid,(r.status,body)
    assert_status(site,'/api/v1/mentor/profile?id='+product['mentor_id'],404)
    status,body,_=adminclient.post('/mentors/status',{'id':product['mentor_id'],'status':'active'});assert status==200,(status,body)
    html=assert_status(site,'/mentor/profile?id='+product['mentor_id'],200);assert assetid in html
    with site.opener.open(site.base+'/index.php?r=%2Fcontent%2Fasset&id='+assetid) as r:assert r.headers['Content-Type']=='image/png' and r.read()==image_bytes
    ok('portrait upload reuses owned content safely and requires renewed profile approval')
    # Branding publication is explicit, role-controlled and independent of mentor evidence.
    status,body,_=adminclient.request('/api/v1/csrf');brand_csrf=json.loads(body)['csrf'];brand_boundary='brand-test-'+secrets.token_hex(8)
    brand_payload=(f'--{brand_boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{brand_csrf}\r\n--{brand_boundary}\r\nContent-Disposition: form-data; name="kind"\r\n\r\nfooter_logo\r\n--{brand_boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="brand.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+image_bytes+f'\r\n--{brand_boundary}--\r\n'.encode())
    request=urllib.request.Request(adminclient.base+'/index.php?r=%2Fapi%2Fv1%2Fgeneral%2Fasset',data=brand_payload,headers={'Content-Type':'multipart/form-data; boundary='+brand_boundary})
    with adminclient.opener.open(request) as response:brand_id=json.loads(response.read())['asset_id']
    with guest.opener.open(guest.base+'/index.php?r=%2Fcontent%2Fasset&id='+brand_id) as response:assert response.read()==image_bytes
    status,body,_=content.post('/general/save',{'footer_logo_link':'/about'});assert status==403
    status,body,_=adminclient.post('/general/save',{'about_text':'درباره مرشد','support_phone':'09120691542','support_email':'mozafarijavad287@gmail.com','support_address':'','footer_text':'<script>unsafe-footer</script>','footer_logo_link':'/about','enamad_link':''});assert status==200,(status,body)
    html=assert_status(guest,'/',200);assert '09120691542' in html and 'mozafarijavad287@gmail.com' in html and '&lt;script&gt;unsafe-footer&lt;/script&gt;' in html and '<script>unsafe-footer</script>' not in html and brand_id in html
    status,body,_=adminclient.post('/general/asset',{'kind':'footer_logo','remove':'1'});assert status==200
    assert_status(guest,'/api/v1/content/asset?id='+brand_id,404)
    status,body,_=adminclient.post('/general/save',{'about_text':'درباره مرشد','support_phone':'09120691542','support_email':'mozafarijavad287@gmail.com','support_address':'','footer_text':'مرشد، جایی برای گفت‌وگو با صاحب تجربه.','footer_logo_link':'/','enamad_link':''});assert status==200
    ok('admin branding upload publication removal and safe editable footer work with server role checks')
    dumpbin=server.parent/('mariadb-dump.exe' if os.name=='nt' else 'mariadb-dump')
    if dumpbin.exists():
        dbname=cfg['database']['dsn'].split('dbname=')[1].split(';')[0];dumpfile=scratch/'private-test-backup.sql'
        with dumpfile.open('wb') as stream:
            subprocess.run([str(dumpbin),'--host=127.0.0.1','--port='+str(dbport),'--user=root','--single-transaction','--skip-lock-tables','--skip-comments',dbname],stdout=stream,stderr=subprocess.PIPE,check=True)
        restore=scratch/'restore-check.php'
        restore.write_text(r'''<?php
require $argv[3].'/morshed-core/autoload.php';$c=require $argv[1];
if($c['environment']!=='testing'||!preg_match('/dbname=(morshed_test_[a-f0-9]+);/',$c['database']['dsn'],$match)){throw new RuntimeException('Isolated DB required');}
$original=new Morshed\Core\Database($c['database']);$copyConfig=$c['database'];$clone='morshed_test_'.bin2hex(random_bytes(8));$original->pdo->exec('CREATE DATABASE `'.$clone.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$copyConfig['dsn']=str_replace($match[1],$clone,$copyConfig['dsn']);$restored=new Morshed\Core\Database($copyConfig);$restored->pdo->exec(file_get_contents($argv[2]));
foreach($original->all('SHOW TABLES') as $row){$table=array_values($row)[0];if(!preg_match('/^m_[a-z_]+$/D',$table))throw new RuntimeException('Unexpected table');$keys=$original->all('SHOW KEYS FROM `'.$table.'` WHERE Key_name=\'PRIMARY\'');usort($keys,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);$order=implode(',',array_map(fn($k)=>'`'.$k['Column_name'].'`',$keys));$sql='SELECT * FROM `'.$table.'` ORDER BY '.$order;if($original->all($sql)!==$restored->all($sql))throw new RuntimeException('Restore mismatch '.$table);}
$restored->run('UPDATE m_auth_sessions SET revoked_at=? WHERE revoked_at IS NULL',[time()]);$restored->run('UPDATE m_otp_challenges SET consumed_at=? WHERE consumed_at IS NULL',[time()]);$restored->run('UPDATE m_external_flows SET consumed_at=? WHERE consumed_at IS NULL',[time()]);if((int)$restored->run('SELECT COUNT(*) FROM m_auth_sessions WHERE revoked_at IS NULL')->fetchColumn()!==0)throw new RuntimeException('Restored sessions not invalidated');
$fresh='morshed_test_'.bin2hex(random_bytes(8));$original->pdo->exec('CREATE DATABASE `'.$fresh.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$copyConfig['dsn']=str_replace($clone,$fresh,$copyConfig['dsn']);$empty=new Morshed\Core\Database($copyConfig);$empty->pdo->exec(file_get_contents($argv[3].'/morshed-core/database/install-new-database.sql'));if((int)$empty->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn()!==7||count($empty->all('SHOW TABLES'))!==count($original->all('SHOW TABLES')))throw new RuntimeException('Combined installer mismatch');
echo "PASS all database rows restored and old authentication invalidated\nPASS combined empty-database installer matches numbered migrations\n";
''',encoding='utf-8')
        r=subprocess.run(phpcmd+[str(restore),str(configphp),str(dumpfile),str(app)],capture_output=True,text=True,encoding='utf-8');assert r.returncode==0,r.stderr
        ok('local database backup restores every table exactly and invalidates snapshot authentication')
        ok('combined fresh installer imports successfully with all schema migrations')
    (scratch/'http-results.json').write_text(json.dumps({'database_engine':version,'checks':checks},indent=2),encoding='utf-8')
    print('HTTP/RACE RESULT: '+str(len(checks))+' checks passed')
    if args.preview_seconds:
        print('PREVIEW_SITE '+cfg['origins']['site'],flush=True);print('PREVIEW_ADMIN '+cfg['origins']['admin'],flush=True)
        deadline=time.monotonic()+min(args.preview_seconds,1800)
        while time.monotonic()<deadline and not (scratch/'stop-preview').exists():time.sleep(.5)
finally:
    for process in reversed(processes):
        process.terminate()
        try:process.wait(timeout=10)
        except subprocess.TimeoutExpired:process.kill();process.wait()
    for out in logs:out.close()
    print('Private test scratch: '+str(scratch))
