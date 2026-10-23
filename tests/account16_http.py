"""Server-backed avatar and navigation regression tests against disposable loopback only."""
from pathlib import Path
import json,sys,urllib.request,urllib.parse,urllib.error,re,secrets
scratch=Path(sys.argv[1])
# Reuse only the fixture-safe client definition, never the other module's test actions.
exec((Path(__file__).parent/'consultations_http14.py').read_text(encoding='utf-8').split('host=Client(')[0])
first=Client(cfg['origins']['site']);second=Client(cfg['origins']['site']);other=Client(cfg['origins']['site']);guest=Client(cfg['origins']['site'])
for client in [first,second]:client.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
other.post('/auth/password',{'phone':p['mentor_phone'],'password':p['mentor_password']})
image=(scratch/'app/morshedapp.ir/assets/morshed-logo.png').read_bytes()
def upload(client,token=True,blob=image,name='portrait.png'):
 boundary='morshed16'+secrets.token_hex(12)
 body=(f'--{boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode()+blob+f'\r\n--{boundary}--\r\n'.encode())
 headers={'Content-Type':'multipart/form-data; boundary='+boundary}
 if token:headers['X-CSRF-Token']=client.get('/api/v1/csrf')['csrf']
 try:r=client.opener.open(urllib.request.Request(client.base+urllib.parse.quote('/api/v1/account/avatar',safe=''),data=body,headers=headers),timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.status,r.read()
assert upload(first,False)[0]==419
assert upload(first,blob=b'not an image')[0]==400
assert upload(first)[0]==200
html=second.get('/account/profile');asset=re.search(r'/content/asset\?id=([a-f0-9]{32})|%2Fcontent%2Fasset&amp;id=([a-f0-9]{32})',html)
assert asset,'second device did not see the saved avatar'
asset=next(v for v in asset.groups() if v)
(scratch/'ui16-avatar-proof.json').write_text(json.dumps({'asset_id':asset}),encoding='utf-8')
def image_status(client):
 url=client.base+urllib.parse.quote('/content/asset',safe='')+'&id='+asset
 try:r=client.opener.open(url,timeout=10)
 except urllib.error.HTTPError as e:r=e
 return r.status,r.read()
assert image_status(second)==(200,image)
assert image_status(guest)[0]==404 and image_status(other)[0]==404
account_page=second.get('/account');assert 'nav-avatar' not in account_page and asset in account_page
nav=guest.get('/').split('aria-label="ناوبری اصلی"')[1].split('</nav>')[0]
assert 'اطلس' in nav and 'جلسات' not in nav and nav.count('<a ')==5
home=guest.get('/');assert 'header-account' not in home and 'site-menu' in home and 'ورود/ثبت نام' in home
assert 'اطلس مرشد' in guest.get('/atlas')
stale=guest.get('/slots?mentor_id='+p['mentor_id']+'&date=2020-01-01&duration_minutes=15')
assert 'درخواست انجام نشد' not in stale and 'dateCorrected' not in stale and 'تاریخ لینک قبلی' in stale
assert 'name="duration_minutes"' in stale and 'value="45"' not in stale
assert guest.req('/api/v1/slots?mentor_id='+p['mentor_id']+'&duration_minutes=20')[0]==400
second.post('/account/avatar',{'remove':'1'});assert 'class="nav-avatar"' not in first.get('/account')
assert image_status(second)[0]==200 # Removing the selected photo doesn't delete a shared owned asset.
assert upload(other)[0]==200
assert other.get('/api/v1/mentor/calendar')['settings'] # Personal photo must not demote mentor to review.
print('PASS UI16 HTTP: private avatar upload, CSRF, image validation, cross-device persistence, access isolation, removal, mentor role preservation, five-tab atlas nav, home topic cards and stale-date recovery')
