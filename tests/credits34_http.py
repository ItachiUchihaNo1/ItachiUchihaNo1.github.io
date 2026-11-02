"""Grant one extra opportunity from a user card; disposable loopback data only."""
from pathlib import Path
import sys,json,http.cookiejar,urllib.parse,urllib.request,re
scratch=Path(sys.argv[1])
exec(Path(__file__).with_name('consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
auth=json.loads((scratch/'membership28-http-auth.json').read_text())
def client(role,realm='site'):
 c=Client(cfg['origins'][realm]);cookie=http.cookiejar.Cookie(0,'morshed_dev_'+realm,auth[role],None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{})
 for h in c.opener.handlers:
  if isinstance(h,urllib.request.HTTPCookieProcessor):h.cookiejar.set_cookie(cookie)
 return c
admin=client('admin','admin');user=client('user');manager=client('manager');guest=Client(cfg['origins']['admin']);uid=auth['fixture']['user']['user_id']
assert 'فرصت مشاوره' in admin.get('/users')
page=admin.get('/users/edit?id='+uid);assert 'فرصت‌های مشاوره' in page
card=page.split('id="consultation-credits"')[1].split('</form>')[0]
key=re.search(r'name="id" value="([a-f0-9]{32})"',card)[1]
assert re.search(r'name="user_id" value="'+uid+'"',card)
expiry=re.search(r'name="expires_local"[^>]*value="([^"]+)"',card)[1]
before=user.get('/api/v1/account/credits')['balance'];data={'id':key,'user_id':uid,'amount':'1','expires_local':expiry,'reason':'LOCAL USER CARD CREDIT','return_user':'1'}
assert admin.req('/api/v1/membership/credits/grant',data,False)[0]==419
assert manager.req('/api/v1/membership/credits/grant',data)[0]==403
assert guest.req('/api/v1/users/edit?id='+uid)[0] in (401,403)
for invalid in [dict(data,amount='0'),dict(data,expires_local='2020-01-01T12:00')]:assert admin.req('/api/v1/membership/credits/grant',invalid)[0]==400
csrf=admin.get('/api/v1/csrf')['csrf'];form=dict(data,csrf=csrf)
response=admin.opener.open(urllib.request.Request(admin.base+urllib.parse.quote('/membership/credits/grant',safe=''),data=urllib.parse.urlencode(form).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'}),timeout=15)
html=response.read().decode();url=urllib.parse.urlparse(response.geturl());params=urllib.parse.parse_qs(url.query)
assert response.status==200 and params['r']==['/users/edit'] and params['id']==[uid]
assert 'LOCAL USER CARD CREDIT' in html
after=user.get('/api/v1/account/credits')['balance'];assert after['available']==before['available']+1 and after['bonus']==before['bonus']+1 and after['quota']==before['quota']
assert admin.post('/membership/credits/grant',data)['id']==key
assert user.get('/api/v1/account/credits')['balance']['available']==after['available']
assert any('LOCAL USER CARD CREDIT' in e['reason'] for e in user.get('/api/v1/account/credits')['creditEvents'])
assert admin.req('/api/v1/membership/credits/grant',dict(data,amount='2'))[0]==409
print('PASS CREDIT34: user-list entry, targeted form, one extra usable credit, unchanged base quota, persistent history, user-card return, retry idempotency, conflict, role/CSRF/expiry/amount validation')
