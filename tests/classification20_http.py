"""Acceptance of four-dimensional editor and filters on guarded disposable loopback data."""
from pathlib import Path
import sys,json,urllib.request,urllib.parse,urllib.error,secrets,re
scratch=Path(sys.argv[1])
exec((Path(__file__).parent/'consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
admin=Client(cfg['origins']['admin']);guest=Client(cfg['origins']['site']);user=Client(cfg['origins']['site'])
admin.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']})
user.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
assert admin.req('/api/v1/classification/catalog/save',{'kind':'audiences','title':'forged','family':'age'},False)[0]==419
assert user.req('/api/v1/classification/catalog/save',{'kind':'audiences','title':'forged','family':'age'})[0]==403
tag=secrets.token_hex(6)
topic=admin.post('/taxonomy/save',{'kind':'topic','title':'HTTP20 topic '+tag})['id']
issue=admin.post('/taxonomy/save',{'kind':'issue','title':'HTTP20 issue '+tag})['id']
audience=admin.post('/classification/catalog/save',{'kind':'audiences','title':'HTTP20 audience '+tag,'family':'role'})['id']
otype=admin.post('/classification/catalog/save',{'kind':'organization-types','title':'HTTP20 organization type '+tag})['id']
org=admin.post('/atlas/organization/save',{'name':'HTTP20 org '+tag,'org_type':otype,'verified':'1'})['id']
for route in ['/organization-types','/audiences','/taxonomy?kind=topic','/taxonomy?kind=issue','/atlas/manage','/experience/edit']:
 assert 'Fatal error' not in admin.get(route)
options=admin.get('/api/v1/classification/organization-options?org_type='+otype+'&q='+urllib.parse.quote(tag))
assert options['rows'][0]['id']==org and all(r['org_type']==otype for r in options['rows'])
data={'mentor_id':p['mentor_id'],'title':'HTTP20 experience '+tag,'topics':[topic],'_topics_present':'1','problems':[{'title':'Specific challenge '+tag,'description':'Searchable problem description '+tag,'tag_id':issue}],'_problems_present':'1','audiences':[audience],'_audiences_present':'1','_organization_present':'1','organization_mode':'existing','org_type':otype,'organization_id':org,'problem':'problem','context':'context','actions':'actions','results':'results','lessons':'lessons','visibility':'public','confidential_notes':'PRIVATE20-'+tag}
invalid=dict(data);invalid['problems']=[{'title':'','tag_id':issue}]
assert admin.req('/api/v1/experience/save',invalid)[0]==400
saved=admin.post('/experience/save',data)
edit=admin.get('/experience/edit?id='+saved['experience_id'])
for value in ['مسائل مشخص این تجربه','مخاطبان تجربه','مجموعهٔ مرتبط',audience,topic,issue,'Specific challenge '+tag]:assert value in edit,value
assert 'PRIVATE20-'+tag in edit
admin.post('/experience/submit',{'id':saved['id']});admin.post('/experience/review',{'id':saved['id'],'decision':'approved','note':''})
public=guest.get('/experience?id='+saved['experience_id'])
assert 'Specific challenge '+tag in public and 'PRIVATE20-'+tag not in public
assert 'HTTP20 org '+tag not in public
admin.post('/atlas/attribution',{'version_id':saved['id'],'org_id':org,'kind':'organization','status':'approved'})
public=guest.get('/experience?id='+saved['experience_id']);assert 'HTTP20 org '+tag in public and 'HTTP20 organization type '+tag in public
query=urllib.parse.urlencode({'topic[]':topic,'issue[]':issue,'audience[]':audience,'org_type[]':otype,'organization_id[]':org})
for route in ['/experiences?','/mentors?','/atlas?']:
 html=guest.get(route+query);assert 'Fatal error' not in html and 'dimension-filter' in html
 if route!='/atlas?':assert saved['experience_id'] in html or p['mentor_id'] in html
for old in ['/topics','/issues','/topic?id='+topic,'/issue?id='+issue]:
 url=guest.base+urllib.parse.quote(old.partition('?')[0],safe='')+('&'+old.partition('?')[2] if '?' in old else '')
 with guest.opener.open(url,timeout=20) as response:assert 'r=%2Fexperiences' in response.url
menu=guest.get('/').split('aria-label="همهٔ بخش‌های سایت"')[1].split('</nav>')[0]
assert '%2Ftopics' not in menu and '%2Fissues' not in menu
assert guest.req('/api/v1/experiences?topic[forged]='+topic)[0]==400
assert guest.req('/api/v1/experiences?audience[forged]='+audience)[0]==400
print('PASS CLASSIFICATION20 HTTP: catalog roles/CSRF, types and audience CRUD, scoped organization search, editor round trip, real problems, published privacy, attribution review, four-filter combination, redirects and malformed filters')
