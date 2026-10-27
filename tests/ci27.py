"""Disposable CI database regression suite; no production config or providers."""
from pathlib import Path
import tempfile,secrets,json,hashlib,subprocess,os
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='morshed-ci27-') as temp:
 p=Path(temp); token=secrets.token_hex(32);name='morshed_test_'+secrets.token_hex(8)
 cfg={'environment':'testing','app_key':secrets.token_hex(32),'origins':{'site':'http://127.0.0.1:8080','admin':'http://127.0.0.1:8081'},'allow_http_loopback':True,'database':{'dsn':f'mysql:host=127.0.0.1;port=3306;dbname={name};charset=utf8mb4','user':'root','password':''},'setup':{'enabled':True,'token_sha256':hashlib.sha256(token.encode()).hexdigest()},'sms':{'enabled':False},'test_setup_token':token,'test_manual_password':secrets.token_urlsafe(24),'test_fixture_path':str(p/'fixture.json')}
 (p/'config.json').write_text(json.dumps(cfg)); config=p/'config.php';config.write_text("<?php return json_decode(file_get_contents(__DIR__.'/config.json'),true);")
 def run(name):subprocess.run(['php',str(root/'tests'/name),str(config)],check=True,cwd=root)
 create=p/'create.php';create.write_text("<?php $p=new PDO('mysql:host=127.0.0.1;port=3306','root','');$p->exec('CREATE DATABASE `"+name+"` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');")
 subprocess.run(['php',str(create)],check=True);run('prepare.php')
 setup=subprocess.run(['php',str(root/'tests/race_worker.php'),str(config)],input='{"operation":"setup"}',text=True,capture_output=True,check=True);assert json.loads(setup.stdout)['ok'],setup.stdout
 for test in ['integration.php','product.php','external.php','delivery.php','tsms_gateway.php','release03.php','admin07.php','catalog12.php','taxonomy13.php','consultations14.php','consultations16.php','performance14.php','atlas15.php','classification17.php','classification20.php','release21.php','release22.php','release23.php','release24.php','chat25.php','queue26.php','release27.php']:run(test)
 print('PASS all isolated PHP database regressions')
