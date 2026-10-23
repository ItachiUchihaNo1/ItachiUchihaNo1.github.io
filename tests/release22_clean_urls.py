from pathlib import Path
import json,urllib.request,urllib.error
import sys,re
scratch=Path(sys.argv[1]);assert re.fullmatch(r'morshed-test-[a-f0-9]+',scratch.name);file=scratch/'private-config.json';old=file.read_text();cfg=json.loads(old);assert cfg['environment']=='testing' and cfg['origins']['site'].startswith('http://127.0.0.1:');cfg['pretty_urls']=True;file.write_text(json.dumps(cfg))
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(NoRedirect());base=cfg['origins']['site']
def req(path):
 try:r=opener.open(base+path)
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers.get('Location'),r.read().decode()
try:
 status,location,body=req('/experiences');assert status==200 and 'name="r"' not in body and 'href="/mentors"' in body
 status,location,body=req('/index.php?r=%2Fexperiences&q=clean22');assert status==301 and location=='/experiences?q=clean22'
 status,location,body=req('/index.php?r=%2F');assert status==200 and not location and 'rel="canonical" href="'+base+'/' in body
 status,location,body=req('/index.php?r=%2Fmentors&hash=signed-entry');assert status==200 and not location
 status,location,body=req('/index.php?r=%2Flogin');assert status==200 and not location
 status,location,body=req('/sitemap.xml');assert status==200 and '<urlset' in body and '/account' not in body
 print('PASS clean route rendering, legacy 301 with filters, redirect-free app entry/auth and public-only sitemap')
finally:file.write_text(old)
