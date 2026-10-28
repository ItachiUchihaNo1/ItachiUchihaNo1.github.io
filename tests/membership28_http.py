from pathlib import Path
import sys,json,http.cookiejar,urllib.parse
scratch=Path(sys.argv[1])
exec(Path(__file__).with_name('consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
auth=json.loads((scratch/'membership28-http-auth.json').read_text())
def client(role,realm='site'):
 c=Client(cfg['origins'][realm]);cookie=http.cookiejar.Cookie(0,'morshed_dev_'+realm,auth[role],None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{})
 for handler in c.opener.handlers:
  if isinstance(handler,urllib.request.HTTPCookieProcessor):handler.cookiejar.set_cookie(cookie)
 return c
u=client('user');h=client('host');manager=client('manager');admin=client('admin','admin');guest=Client(cfg['origins']['site'])
for path in ['/account/membership','/account/credits','/account/problems','/account/behavior','/community/manage']:
 s,b=u.req(path);assert s==200,(path,s,str(b)[:150]);assert 'membership28.css' in b
for path in ['/membership/policy','/membership/reviews','/membership/networks','/membership/credits','/membership/behavior','/membership/reports']:
 s,b=admin.req(path);assert s==200,(path,s,str(b)[:150])
assert manager.req('/api/v1/membership/policy')[0] in (403,404)
body=manager.get('/api/v1/community/manage');assert not any(key in body for key in ['messages','bookings','personalProblems','userChoices']);assert body['delegations']
uid=auth['fixture']['user']['user_id'];assert admin.req('/api/v1/membership/level',{'user_id':uid,'novice':1},False)[0]==419
assert guest.req('/api/v1/account/credits')[0]==401
assert manager.req('/api/v1/membership/credits/grant',{'user_id':uid})[0]==403
status,data=u.req('/api/v1/account/problems');assert status==200 and data['problems']
other_id=auth['fixture']['problem'];assert manager.req('/api/v1/account/problems?id='+other_id)[0]==404
html=h.get('/mentor/calendar');assert 'name="accept_novices"' in html
print('PASS HTTP28: five account screens, six admin screens, delegate isolation, CSRF, guest rejection, private-problem ownership and mentor admission controls')
