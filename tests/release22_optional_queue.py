from pathlib import Path
import sys,json,re,subprocess
scratch=Path(sys.argv[1]);assert re.fullmatch(r'morshed-test-[a-f0-9]+',scratch.name)
exec((Path(__file__).parent/'consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
config=scratch/'private-config.json';old=config.read_text();sql=scratch/'app/morshed-core/database/eitaa-outbox.sql';hold=sql.with_suffix('.test-held');helper=scratch/'app/tests/release22_optional_queue.php';php=Path(sys.argv[2]).resolve();configphp=scratch/'app/morshed-core/config/local.php';hidden=False;renamed=False
try:
 subprocess.run([str(php),'-c',str(scratch/'php.ini'),str(helper),str(configphp),'hold'],check=True);renamed=True
 sql.rename(hold);hidden=True
 cfg['eitaa']={'enabled':True,'live_verified':True,'app_token':'isolated-test-token-'+('x'*32)};config.write_text(json.dumps(cfg))
 client=Client(cfg['origins']['site'])
 assert client.req('/api/v1/account/eitaa/test',{},False)[0]==419,'CSRF gate must run before optional queue initialization'
 response=client.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']});assert 'redirect' in response
 assert client.req('/api/v1/account/saved/set',{'kind':'mentor','mentor_id':'a'*32,'saved':'1'})[0] in [400,404],'optional Eitaa failure must not replace core validation with 503'
 print('PASS missing optional queue/file cannot break CSRF, password login or authenticated core action validation')
finally:
 config.write_text(old)
 if hidden:hold.rename(sql)
 if renamed:subprocess.run([str(php),'-c',str(scratch/'php.ini'),str(helper),str(configphp),'restore'],check=True)
