"""Read-only navigation plus real support/authorization flow on guarded loopback fixture."""
from pathlib import Path
import sys,json,secrets,re
scratch=Path(sys.argv[1])
exec((Path(__file__).parent/'consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
guest=Client(cfg['origins']['site']);user=Client(cfg['origins']['site']);admin=Client(cfg['origins']['admin']);editor=Client(cfg['origins']['admin'])
home=guest.get('/')
assert 'name="r" value="/atlas"' in home
assert 'classification-band-topics' in home and 'موضوع‌های تجربه</h2>' not in home
assert 'nav-avatar' not in home
for route in ['/login','/login?mode=register','/password/recover']:
 page=guest.get(route);assert 'aria-label="ناوبری اصلی"' in page and page.count('class="nav-inner"')==1,route
for route in ['/experiences','/mentors','/atlas']:
 page=guest.get(route);assert 'data-dimension="organization_id"' not in page,route
atlas=guest.get('/atlas?q='+secrets.token_hex(20));assert 'atlas-section-nav' not in atlas and 'هنوز تجربهٔ تأییدشده‌ای برای این انتخاب نداریم' not in atlas
assert 'about-layout' in guest.get('/about') and 'درباره مرشد و تماس</a>' not in guest.get('/about')
assert guest.req('/api/v1/meeting/token',{'id':p['experience_id'],'method':'audio'},False)[0]==419
assert guest.req('/api/v1/meeting/token',{'id':p['experience_id'],'method':'audio'})[0]==401
user.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
admin.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']})
editor.post('/auth/password',{'username':fixture['content_username'],'password':fixture['content_password']})
for route in ['/settings','/general','/admins','/users','/support']:
 assert editor.req('/api/v1'+route)[0]==403,route
for route,data in [('/general/save',{}),('/admins/role',{'role':'system_admin','id':'a'*32}),('/users/role',{'role':'mentor','id':'a'*32})]:
 assert editor.req('/api/v1'+route,data)[0]==403,route
for route in ['/experiences','/taxonomy?kind=topic','/storefront','/audiences']:
 assert editor.req(route)[0]==200,route
nav=admin.get('/');assert 'حساب کاربری مدیریت' in nav and 'support_open' not in nav
marker='HTTP21-'+secrets.token_hex(5)
tid=user.post('/support/create',{'kind':'support','title':marker,'body':'مشکل کاربر برای پیگیری مدیر'})['id']
assert marker in admin.get('/support') and 'فرستنده:' in admin.get('/support')
assert marker in user.get('/ticket?id='+tid)
admin.post('/ticket/reply',{'id':tid,'body':'پاسخ رسیدگی آزمایشی','state':'open'})
assert 'پاسخ رسیدگی آزمایشی' in user.get('/ticket?id='+tid)
logged=user.get('/account');assert 'ذخیره‌شده‌ها' in logged and 'جلسات و درخواست‌ها' not in re.search(r'<nav aria-label="همهٔ بخش‌های سایت">(.*?)</nav>',logged,re.S)[1]
assert 'nav-avatar' not in logged
print('PASS release21 HTTP: navigation, classified search, support round trip and role boundaries')
