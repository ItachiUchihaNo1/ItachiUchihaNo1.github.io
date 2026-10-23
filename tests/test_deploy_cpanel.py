import ftplib,importlib.util,io,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('deploy',Path(__file__).parents[1]/'tools/deploy_cpanel.py')
deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)

class FakeFTP:
    def __init__(self):
        self.files={'/morshed-core/config/local.php':b'PRIVATE-SECRET-DO-NOT-READ','/morshed-core/bootstrap.php':b'old core',
                    '/morshedapp.ir/index.php':b"<?php morshed_run('site');",'/admin.morshedapp.ir/index.php':b"<?php morshed_run('admin');",'/morshedapp.ir/assets/app.css':b'old css'}
        self.dirs={'/','/morshed-core','/morshed-core/config','/morshedapp.ir','/morshedapp.ir/assets','/admin.morshedapp.ir'}
        self.commands=[];self.reads=[];self.fail_target=None;self.cwd_calls=0
    def cwd(self,p):
        self.cwd_calls+=1
        if p not in self.dirs:raise ftplib.error_perm('550 Directory missing')
    def mkd(self,p):self.dirs.add(p)
    def mlsd(self,p,facts=None):
        if p not in self.dirs:raise ftplib.error_perm('550 Directory missing')
        for path in sorted(self.files.keys()|self.dirs):
            if str(Path(path).as_posix()).rstrip('/')==p:continue
            if str(deploy.PurePosixPath(path).parent)==p:yield deploy.PurePosixPath(path).name,{'type':'file' if path in self.files else 'dir'}
    def retrbinary(self,cmd,callback):
        p=cmd.removeprefix('RETR ');self.reads.append(p)
        if p.endswith('/config/local.php'):raise AssertionError('Private configuration must not be downloaded')
        callback(self.files[p])
    def storbinary(self,cmd,stream):self.files[cmd.removeprefix('STOR ')]=stream.read()
    def sendcmd(self,cmd):self.commands.append(cmd);return '200 OK'
    def rename(self,a,b):
        if b==self.fail_target:self.fail_target=None;raise OSError('Injected interruption')
        self.files[b]=self.files.pop(a)
    def delete(self,p):del self.files[p]

class DeployTests(unittest.TestCase):
    def test_incremental_plan_requires_a_successful_deployment_of_the_base(self):
        self.assertIsNone(deploy.changed_paths('a'*40,'b'*40,lambda _: {'workflow_runs':[]}))
    def test_incremental_plan_uses_only_complete_ancestor_comparison(self):
        def fetch(path):return {'workflow_runs':[{'head_sha':'a'*40,'conclusion':'success'}]} if path.startswith('actions/') else {'status':'ahead','files':[{'filename':'morshedapp.ir/assets/app.css','status':'modified'},{'filename':'old.php','status':'removed'}]}
        self.assertEqual(deploy.changed_paths('a'*40,'b'*40,fetch),{'morshedapp.ir/assets/app.css'})
    def test_truncated_or_diverged_comparison_falls_back_to_full_plan(self):
        for comparison in [{'status':'diverged','files':[]},{'status':'ahead','files':[{'filename':'file.php'}]*300}]:
            self.assertIsNone(deploy.changed_paths('a'*40,'b'*40,lambda path: {'workflow_runs':[{'head_sha':'a'*40,'conclusion':'success'}]} if path.startswith('actions/') else comparison))
    def test_no_secret_runtime_or_traversal_can_enter_plan(self):
        for name in ['../x.php','/morshedapp.ir/x.php','morshed-core/config/local.php','morshed-core/storage/sessions/a.php','morshed-core/database/migrations/007.sql','morshed-core/.deploy/a.php','morshedapp.ir/.env','morshedapp.ir/../x.php','morshedapp.ir/evil\\x.php']:
            with self.subTest(name=name):self.assertFalse(deploy.allowed(name))
    def test_only_reviewed_additive_upgrade_script_is_deployable(self):
        files=deploy.plan();self.assertIn('morshed-core/database/migrations/007_profiles_and_experience_cases.sql',files);self.assertNotIn('morshed-core/database/migrations/001_identity.sql',files)
    def test_real_source_plan_has_assets_and_no_private_config(self):
        files=deploy.plan();self.assertIn('morshedapp.ir/assets/morshed-logo.png',files);self.assertNotIn('morshed-core/config/local.php',files)
    def test_updates_never_replace_domain_php_handler_configuration(self):
        files=deploy.plan()
        for path in ['morshedapp.ir/.htaccess','admin.morshedapp.ir/.htaccess']:
            self.assertNotIn(path,files)
            ftp=FakeFTP();old=ftp.files.copy()
            with self.assertRaises(ValueError):deploy.Publisher(ftp,'/','a'*40).publish({path:b'replacement'},lambda _:None)
            self.assertEqual(old,ftp.files)
    def test_runtime_sql_dependencies_are_in_the_reviewed_plan(self):
        files=deploy.plan()
        for source in (deploy.ROOT/'morshed-core/src').rglob('*.php'):
            import re
            for relative in re.findall(r"file_get_contents\(__DIR__\.'([^']+\.sql)'",source.read_text('utf-8')):
                dependency=(source.parent/relative.lstrip('/')).resolve().relative_to(deploy.ROOT).as_posix()
                self.assertIn(dependency,files,source.name+' runtime dependency missing')
    def test_incremental_release_repairs_missing_runtime_dependencies(self):
        files=deploy.plan();selected=deploy.incremental_files(files,{'morshed-core/src/Http/Application.php'})
        for required in deploy.REQUIRED_RUNTIME_FILES:self.assertIn(required,selected)
        self.assertNotIn('morshed-core/config/local.php',selected)
        self.assertNotIn('morshed-core/database/install-new-database.sql',selected)

    def test_successful_publish_sets_public_644_and_preserves_private_data(self):
        ftp=FakeFTP();p=deploy.Publisher(ftp,'/','a'*40)
        p.publish({'morshedapp.ir/assets/app.css':b'new css','morshed-core/bootstrap.php':b'new core'},lambda files:self.assertEqual(ftp.files['/morshedapp.ir/assets/app.css'],b'new css'))
        self.assertEqual(ftp.files['/morshed-core/config/local.php'],b'PRIVATE-SECRET-DO-NOT-READ')
        self.assertIn('SITE CHMOD 644 /morshedapp.ir/assets/app.css',ftp.commands)
        self.assertIn('SITE CHMOD 644 /morshed-core/bootstrap.php',ftp.commands)
        self.assertNotIn(p.marker,ftp.files);self.assertNotIn('/morshed-core/config/local.php',ftp.reads)
    def test_failed_http_check_restores_all_previous_files(self):
        ftp=FakeFTP();old=ftp.files.copy();p=deploy.Publisher(ftp,'/','b'*40)
        def fail(files):raise RuntimeError('Injected HTTP failure')
        with self.assertRaises(RuntimeError):p.publish({'morshedapp.ir/assets/app.css':b'new','morshed-core/bootstrap.php':b'new'},fail)
        for path,data in old.items():self.assertEqual(ftp.files[path],data)
        self.assertNotIn(p.marker,ftp.files)
    def test_interrupted_rename_restores_backup(self):
        ftp=FakeFTP();old=ftp.files.copy();ftp.fail_target='/morshedapp.ir/assets/app.css';p=deploy.Publisher(ftp,'/','c'*40)
        with self.assertRaises(RuntimeError):p.publish({'morshedapp.ir/assets/app.css':b'new'},lambda _:None)
        for path,data in old.items():self.assertEqual(ftp.files[path],data)
        self.assertNotIn(p.marker,ftp.files)
    def test_wrong_ftp_root_fails_before_file_upload(self):
        ftp=FakeFTP();old=ftp.files.copy();p=deploy.Publisher(ftp,'/wrong','d'*40)
        with self.assertRaisesRegex(RuntimeError,'FTP_ROOT_MISMATCH'):p.publish({'morshedapp.ir/assets/app.css':b'new'},lambda _:None)
        self.assertEqual(ftp.files,old)
    def test_stale_maintenance_blocks_new_deployment(self):
        ftp=FakeFTP();ftp.dirs.add('/morshed-core/.deploy');ftp.files['/morshed-core/.deploy/maintenance.json']=b'old';old=ftp.files.copy()
        with self.assertRaises(RuntimeError):deploy.Publisher(ftp,'/','e'*40).publish({'morshedapp.ir/assets/app.css':b'new'},lambda _:None)
        self.assertEqual(ftp.files,old)
    def test_invalid_commit_or_base_path_is_rejected(self):
        for base,sha in [('/../../','f'*40),('/','main'),('/\r\n','f'*40)]:
            with self.assertRaises(ValueError):deploy.Publisher(FakeFTP(),base,sha)
    def test_tls_data_error_is_not_reported_as_wrong_directory(self):
        ftp=FakeFTP()
        def reject(*args,**kwargs):raise ftplib.error_perm('522 TLS data connection rejected')
        ftp.mlsd=reject
        with self.assertRaisesRegex(ftplib.error_perm,'522'):
            deploy.Publisher(ftp,'/','f'*40).preflight()
    def test_repeated_directory_setup_avoids_redundant_network_calls(self):
        ftp=FakeFTP();p=deploy.Publisher(ftp,'/','a'*40)
        p.mkdir('/morshed-core/.deploy/stage');calls=ftp.cwd_calls
        p.mkdir('/morshed-core/.deploy/stage');self.assertEqual(ftp.cwd_calls,calls)
        p.upload('/morshed-core/.deploy/stage/a.php',b'one','600')
        p.upload('/morshed-core/.deploy/stage/b.php',b'two','600')
        self.assertEqual(ftp.cwd_calls,calls)
    def test_recovery_requires_exact_user_approved_interrupted_commit(self):
        ftp=FakeFTP();ftp.dirs.add('/morshed-core/.deploy');ftp.files['/morshed-core/.deploy/maintenance.json']=b'{"commit":"'+b'a'*40+b'"}'
        with self.assertRaises(RuntimeError):deploy.Publisher(ftp,'/','b'*40,'c'*40).preflight()
        p=deploy.Publisher(ftp,'/','b'*40,'a'*40)
        p.publish({'morshed-core/bootstrap.php':b'repaired','morshedapp.ir/assets/app.css':b'new'},lambda _:None)
        self.assertEqual(ftp.files['/morshed-core/bootstrap.php'],b'repaired');self.assertNotIn(p.marker,ftp.files)

if __name__=='__main__':unittest.main()
