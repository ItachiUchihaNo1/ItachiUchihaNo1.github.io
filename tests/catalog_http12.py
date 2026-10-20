"""Exercise actual save routes against the runner's disposable loopback preview, never production."""
import sys,json,urllib.request,urllib.parse,http.cookiejar
from pathlib import Path
scratch=Path(sys.argv[1]);cfg=json.loads((scratch/'private-config.json').read_text());fixture=json.loads((scratch/'fixture.json').read_text())
assert cfg['environment']=='testing' and cfg['origins']['site'].startswith('http://127.0.0.1:')
base=cfg['origins']['site']+'/index.php?r='
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(route):
 with client.open(base+urllib.parse.quote(route,safe=''),timeout=10) as response:return response.read().decode()
def post(route,data):
 csrf=json.loads(get('/api/v1/csrf'))['csrf']
 request=urllib.request.Request(base+urllib.parse.quote('/api/v1'+route,safe=''),data=json.dumps(data).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
 with client.open(request,timeout=10) as response:return json.load(response)
post('/auth/password',{'phone':fixture['phone'],'password':fixture['password']})
rows=json.loads(get('/api/v1/mentors'))['rows'];mentor=rows[0]['user_id']
assert post('/account/saved/set',{'mentor_id':mentor,'saved':'1'})['saved'] is True
assert 'aria-pressed="true"' in get('/account/saved')
assert post('/account/saved/set',{'mentor_id':mentor,'saved':'1'})['saved'] is True
assert get('/account/saved').count('class="catalog-card catalog-mentor"')==1
assert post('/account/saved/set',{'mentor_id':mentor,'saved':'0'})['saved'] is False
assert 'هنوز مرشدی ذخیره نکرده‌ای' in get('/account/saved')
print('PASS real authenticated HTTP save, repeated save, persisted list and removal')
