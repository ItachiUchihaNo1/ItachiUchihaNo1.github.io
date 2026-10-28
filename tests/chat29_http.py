"""Real HTTP chat on a disposable DB; never targets production or sends notifications."""
from pathlib import Path
import sys,json,http.cookiejar,urllib.parse,re,secrets,subprocess
scratch=Path(sys.argv[1]);php=sys.argv[2]
exec(Path(__file__).with_name('consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
auth=json.loads((scratch/'chat29-auth.json').read_text());members=json.loads((scratch/'membership28-http-auth.json').read_text())
def client(token,realm='site'):
 c=Client(cfg['origins'][realm]);cookie=http.cookiejar.Cookie(0,'morshed_dev_'+realm,token,None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{})
 for h in c.opener.handlers:
  if isinstance(h,urllib.request.HTTPCookieProcessor):h.cookiejar.set_cookie(cookie)
 return c
u=client(auth['user']);m=client(auth['mentor']);other=client(auth['other']);a=client(members['admin'],'admin');bid=auth['id'];room='/api/v1/chat/poll?id='+bid
assert 'data-chat-room' in u.get('/chat?id='+bid)
assert a.req('/api/v1/legal/recording/create',{'title':'LOCAL CHAT ONLY','body':'LOCAL ONLY: storage notice'},False)[0]==419
assert u.req('/api/v1/legal/recording/create',{'title':'invalid','body':'invalid'})[0] in (403,404,503)
a.post('/legal/recording/create',{'title':'LOCAL CHAT ONLY','body':'LOCAL ONLY: storage notice'})
html=a.get('/legal?document=recording');vid=re.search(r'name="id" value="([a-f0-9]{32})"',html)[1]
assert a.req('/api/v1/legal/recording/publish',{'id':vid})[0]==400
assert a.req('/api/v1/legal/recording/publish',{'id':auth['base_version'],'confirmed':'1'})[0]==404
a.post('/legal/recording/publish',{'id':vid,'confirmed':'1'})
assert not u.get(room)['chat']['can_send']
assert u.req('/api/v1/chat/consent',{'id':bid,'version_id':vid,'accepted':'0'})[0]==400
u.post('/chat/consent',{'id':bid,'version_id':vid,'accepted':'1'})
assert not u.get(room)['chat']['can_send']
m.post('/chat/consent',{'id':bid,'version_id':vid,'accepted':'1'})
assert u.get(room)['chat']['can_send'] and m.get(room)['chat']['can_send']
key=secrets.token_hex(16);payload={'id':bid,'client_key':key,'body':'سلام؛ پیام واقعی HTTP <script> صرفاً متن'}
assert u.req('/api/v1/chat/send',payload,False)[0]==419
one=u.post('/chat/send',payload)['message'];retry=u.post('/chat/send',payload)['message'];assert one==retry
assert u.req('/api/v1/chat/send',dict(payload,body='different'))[0]==409
messages=m.get(room+'&after='+str(one['id']-1))['chat']['messages'];assert len(messages)==1 and messages[0]['body']==payload['body'] and not messages[0]['own']
two=m.post('/chat/send',{'id':bid,'client_key':secrets.token_hex(16),'body':'پاسخ مرشد'})['message']
assert u.get(room+'&after='+str(one['id']))['chat']['messages'][0]['id']==two['id']
assert other.req(room)[0] in (403,404)
assert a.req('/api/v1/chat/poll?id='+bid)[0] in (403,404)
assert 'other_accepted' not in u.get(room)['chat'] # policy flags only in authenticated room view
cmd=[php,'-c',str(scratch/'php.ini'),str(Path(__file__).with_name('chat29_fixture.php')),str(scratch/'private-config.php')]
for mode in ['future','ended','cancelled']:
 subprocess.run(cmd+[mode],check=True,capture_output=True)
 assert not u.get(room)['chat']['can_send']
 assert u.req('/api/v1/chat/send',dict(payload,client_key=secrets.token_hex(16)))[0]==409
 assert m.get(room+'&after='+str(one['id']))['chat']['messages'][0]['id']==two['id']
print('PASS CHAT29 HTTP: admin publication and CSRF; both own consents; two-way chat; retry and conflict; private cursor; outsider/admin denial; server-side start/end/cancellation and durable history')
