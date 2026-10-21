"""HTTP acceptance on a named disposable loopback fixture; never production accounts."""
import json,sys,urllib.request,urllib.parse,urllib.error,http.cookiejar,secrets
from pathlib import Path
scratch=Path(sys.argv[1]);cfg=json.loads((scratch/'private-config.json').read_text());fixture=json.loads((scratch/'fixture.json').read_text());p=fixture['product']
assert cfg['environment']=='testing' and cfg['origins']['site'].startswith('http://127.0.0.1:')
class Client:
 def __init__(self,origin):self.base=origin+'/index.php?r=';self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,route,data=None,csrf=True):
  path,_,query=route.partition('?');url=self.base+urllib.parse.quote(path,safe='')+('&'+query if query else '');headers={}
  if data is not None:
   headers['Content-Type']='application/json'
   if csrf:headers['X-CSRF-Token']=self.get('/api/v1/csrf')['csrf']
  try:response=self.opener.open(urllib.request.Request(url,data=None if data is None else json.dumps(data).encode(),headers=headers),timeout=15)
  except urllib.error.HTTPError as error:response=error
  body=response.read().decode();return response.status,json.loads(body) if 'application/json' in response.headers.get('Content-Type','') else body
 def get(self,path):status,body=self.req(path);assert status==200,(status,body);return body
 def post(self,path,data):status,body=self.req('/api/v1'+path,data);assert status==200,(status,body);return body
guest=Client(cfg['origins']['site']);user=Client(cfg['origins']['site']);admin=Client(cfg['origins']['admin']);anonymous=Client(cfg['origins']['admin'])
admin.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']});user.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
assert 'اطلس مرشد' in guest.get('/atlas')
assert 'data-atlas-map' in guest.get('/atlas')
assert 'مجموعه‌های تجربه' in guest.get('/organizations')
assert guest.req('/api/v1/knowledge')[0]==403
assert anonymous.req('/api/v1/knowledge')[0]==403
assert anonymous.req('/api/v1/atlas/manage')[0]==401
assert user.req('/api/v1/atlas/organization/save',{'name':'forged'})[0]==403
assert admin.req('/api/v1/atlas/organization/save',{},False)[0]==419
assert 'حساب من' not in user.get('/').split('</header>')[0]
assert '/assets/morshed-logo-288.webp' in guest.get('/')
provinces=guest.get('/api/v1/places')['places'];assert len(provinces)==31
province=provinces[0]['id'];counties=guest.get('/api/v1/places?province='+province)['places'];assert counties
assert guest.req('/api/v1/atlas?province=forged')[0]==400
assert guest.req('/api/v1/atlas?topic%5B%5D=forged')[0]==400
data={'name':'مجموعهٔ آزمون HTTP '+secrets.token_hex(6),'org_type':'cultural','description':'معرفی آزمایشی','province_id':province,'county_id':counties[0]['id'],'verified':'1'}
org=admin.post('/atlas/organization/save',data)['id'];assert guest.req('/api/v1/organization?id='+org)[0]==404
experience=guest.get('/api/v1/experience?id='+p['experience_id'])['experience'];version=experience['version_id']
link={'version_id':version,'kind':'organization','org_id':org,'status':'proposed','is_primary':1,'raw_name':'HTTP_PRIVATE_RAW','internal_note':'HTTP_PRIVATE_NOTE','evidence':'HTTP_PRIVATE_EVIDENCE'}
admin.post('/atlas/attribution',link);assert guest.req('/api/v1/organization?id='+org)[0]==404
link['status']='approved';admin.post('/atlas/attribution',link)
public=guest.get('/api/v1/organization?id='+org);assert public['organization']['id']==org and public['experiences']['total']==1
assert 'HTTP_PRIVATE' not in json.dumps(public)
admin.post('/atlas/attribution',{'version_id':version,'kind':'place','province_id':province,'county_id':counties[0]['id'],'status':'approved','is_primary':1})
atlas=guest.get('/api/v1/atlas?province='+province);assert atlas['total']>=1 and any(row['id']==p['experience_id'] for row in atlas['rows'])
assert 'مجموعه‌ها و جغرافیای تجربه' in admin.get('/atlas/manage')
assert 'روابط و فرادادهٔ مرشد' in admin.get('/knowledge')
assert admin.get('/api/v1/knowledge')['eventPolicy']['enabled']==0
link['status']='revoked';admin.post('/atlas/attribution',link);assert guest.req('/api/v1/organization?id='+org)[0]==404
proof={'province':province,'county':counties[0]['id'],'experience':p['experience_id'],'organization':org}
(scratch/'atlas15-proof.json').write_text(json.dumps(proof),encoding='utf-8')
print('PASS 24 atlas HTTP checks: public routes, reference hierarchy, admin permissions, CSRF, reviewed attribution, private DTOs, live geography filtering, graph admin and disabled analytics')
