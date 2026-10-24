from pathlib import Path
import sys,secrets,json,re
scratch=Path(sys.argv[1])
exec((Path(__file__).parent/'consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
guest=Client(cfg['origins']['site']);user=Client(cfg['origins']['site']);admin=Client(cfg['origins']['admin']);editor=Client(cfg['origins']['admin'])
home=guest.get('/')
assert 'header-account' not in home and 'ورود/ثبت نام' in home
assert 'data-view-kind="experiences"' not in home and 'data-view-toggle="mentors"' not in home
assert 'favicon-64.png' in home and 'rel="canonical"' in home
for kind in ['experiences','mentors']:
 page=guest.get('/'+kind);assert 'data-view-toggle="'+kind+'"' in page and 'data-view-kind="'+kind+'"' in page
assert guest.req('/api/v1/experience/submit',{'title':'bad','summary':'bad'},False)[0]==419
assert guest.req('/api/v1/experience/submit',{'title':'bad','summary':'bad'})[0]==401
user.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
admin.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']})
editor.post('/auth/password',{'username':fixture['content_username'],'password':fixture['content_password']})
marker='HTTP22-'+secrets.token_hex(5)
id=user.post('/experience/submit',{'title':marker,'summary':'خلاصهٔ تجربه برای تماس تحریریه و ثبت کامل تجربه'})['id']
assert marker in user.get('/experience/submit') and marker in admin.get('/experience-leads')
assert editor.req('/api/v1/experience-leads')[0]==403
admin.post('/experience-leads/review',{'id':id,'state':'contacted'})
assert 'تماس گرفته شد' in user.get('/experience/submit')
assert editor.req('/api/v1/eitaa-delivery')[0]==403
assert guest.req('/api/v1/eitaa-delivery/test',{'username':'javadmozafari'})[0] in [401,403,404]
assert guest.req('/api/v1/account/eitaa/test',{})[0]==401
assert user.req('/api/v1/account/eitaa/test',{'username':'somebody_else'})[0]==503
# Guest navigation preserves exact intended route through password login.
back=Client(cfg['origins']['site']);back.get('/login?return_to=%2Fatlas%3Fq%3Dhttp22%26layer%3Dmentors')
result=back.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
assert 'atlas' in result['redirect'] and 'http22' in result['redirect'],result
print('PASS release22 HTTP: lead submission/follow-up, privacy, auth return and presentation controls')
