"""Real catalog, icon and storefront HTTP acceptance on disposable loopback data only."""
from pathlib import Path
import json,sys,urllib.request,urllib.parse,urllib.error,secrets,io,re
from PIL import Image
scratch=Path(sys.argv[1])
exec((Path(__file__).parent/'consultations_http14.py').read_text(encoding='utf-8').split('host=Client(')[0])
runTag=secrets.token_hex(5)
admin=Client(cfg['origins']['admin']);guest=Client(cfg['origins']['site']);user=Client(cfg['origins']['site'])
admin.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']})
user.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']})
assert user.req('/api/v1/taxonomy/save',{'kind':'topic','title':'forged'})[0] in [403,404]
root=admin.post('/taxonomy/save',{'kind':'topic','title':'HTTP17 '+secrets.token_hex(6),'description':'شرح موضوع','display_order':0})['id']
child=admin.post('/taxonomy/save',{'kind':'topic','title':'Child17 '+secrets.token_hex(6),'parent_id':root})['id']
issue=admin.post('/taxonomy/save',{'kind':'issue','title':'پرسش آزمایشی '+secrets.token_hex(6),'short_label':'برچسب آزمون','related_topics':[child]})['id']
assert admin.req('/api/v1/taxonomy/save',{'id':root,'kind':'topic','title':'HTTP17 cycle','parent_id':child})[0]==409
topic_page=guest.get('/topic?id='+root);assert 'dimension-filter' in topic_page and 'زیرموضوع‌ها' not in topic_page
assert 'dimension-filter' in guest.get('/issue?id='+issue)
assert 'dimension-filter' in guest.get('/issues') and 'راهنمای استفاده' in guest.get('/guide')
assert 'discovery-tabs' not in guest.get('/mentors') and 'catalog-experience' not in guest.get('/mentors')
assert 'discovery-tabs' not in guest.get('/experiences') and 'catalog-mentor' not in guest.get('/experiences')
original=admin.get('/api/v1/storefront')['current']['version_id']
draft=admin.post('/storefront/save',{'blocks_json':json.dumps([{'type':'topics','title':'آیکون‌های موضوع','ids':[root,child],'mode':'manual','count':10},{'type':'issues','title':'پرسش‌های کاربردی','ids':[issue],'mode':'manual'},{'type':'text','title':'بخش غیرفعال آزمون','enabled':0}],ensure_ascii=False)})['id']
admin.post('/storefront/publish',{'id':draft});home=guest.get('/')
assert home.index('آیکون‌های موضوع')<home.index('پرسش‌های کاربردی')
assert home.count('class="thumbnail-topic"')==3 and 'بخش غیرفعال آزمون' not in home
assert 'service-card' not in home and '/assets/morshed-logo-transparent-288.png' in home
png=io.BytesIO();Image.new('RGBA',(128,128),(50,190,140,128)).save(png,format='PNG')
def upload(blob,fields,name='icon.png'):
 boundary='catalog17'+secrets.token_hex(12);body=b''
 for key,value in fields.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
 body+=f'--{boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode()+blob+f'\r\n--{boundary}--\r\n'.encode()
 headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-CSRF-Token':admin.get('/api/v1/csrf')['csrf']}
 try:r=admin.opener.open(urllib.request.Request(admin.base+urllib.parse.quote('/api/v1/taxonomy/save',safe=''),data=body,headers=headers),timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.status,json.loads(r.read())
assert upload(b'not a PNG',{'kind':'topic','title':'Rejected17 '+secrets.token_hex(6)})[0]==400
assert upload(png.getvalue(),{'id':root,'kind':'topic','title':'HTTP17 icon '+runTag,'parent_id':''})[0]==200
topic=next(t for t in guest.get('/api/v1/topics')['topics'] if t['id']==root);asset=topic['icon_asset_id'];assert asset
def asset_status(asset):
 try:r=guest.opener.open(guest.base+urllib.parse.quote('/content/asset',safe='')+'&id='+asset,timeout=10)
 except urllib.error.HTTPError as e:r=e
 return r.status,r.read()
assert asset_status(asset)==(200,png.getvalue())
private=json.loads((scratch/'ui16-avatar-proof.json').read_text())['asset_id']
assert admin.req('/api/v1/taxonomy/save',{'id':root,'kind':'topic','title':'forbidden avatar','icon_asset_id':private})[0]==400
assert admin.get('/api/v1/taxonomy')['rows']
admin.post('/taxonomy/save',{'id':root,'kind':'topic','title':'HTTP17 icon '+runTag,'remove_icon':'1'})
assert asset_status(asset)[0]==404
webp=io.BytesIO();Image.new('RGBA',(128,128),(190,140,50,128)).save(webp,format='WEBP')
assert upload(webp.getvalue(),{'id':root,'kind':'topic','title':'HTTP17 webp '+runTag},'icon.webp')[0]==200
admin.post('/taxonomy/save',{'id':root,'kind':'topic','title':'HTTP17 webp '+runTag,'active':0})
assert guest.req('/api/v1/topic?id='+root)[0]==404
assert 'name="topics[]"' in admin.get('/experience/edit') and 'data-classification-search' in admin.get('/experience/edit')
admin.post('/storefront/publish',{'id':original})
print('PASS CLASSIFICATION17 HTTP: hierarchy, cycle rejection, issue labels and pages, independent lists, real ordered topic/issue blocks, disabled rows, PNG/WebP icon upload, private-asset isolation, icon removal, deactivation and experience multiselect')
