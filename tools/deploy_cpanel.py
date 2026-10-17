"""Deploy reviewed source with explicit FTPS, private staging, backup and rollback.
No database access, remote shell, runtime deletion, or plaintext FTP fallback.
"""
from pathlib import Path, PurePosixPath
import ftplib, hashlib, io, json, os, re, ssl, sys, time, urllib.request

ROOT=Path(__file__).resolve().parent.parent
ALLOWED_ROOTS={'morshed-core','morshedapp.ir','admin.morshedapp.ir'}
ALLOWED_EXT={'.php','.css','.js','.png','.woff2','.txt'}

def allowed(name):
    p=PurePosixPath(name)
    return (not p.is_absolute() and len(p.parts)>1 and p.parts[0] in ALLOWED_ROOTS
            and all(x not in ('','.','..','storage','config','database','.git') for x in p.parts)
            and all(not x.startswith('.') for x in p.parts[1:] if x!='.htaccess')
            and (p.suffix in ALLOWED_EXT or p.name=='.htaccess')
            and p.name!='local.php' and '\\' not in name and not any(ord(x)<32 for x in name))

def plan(root=ROOT):
    migrations=sorted((root/'morshed-core/database/migrations').glob('*.sql'))
    if not migrations or max(int(p.name.split('_')[0]) for p in migrations)!=6:
        raise ValueError('Database migration review required before automatic deployment')
    files={}
    for top in sorted(ALLOWED_ROOTS):
        for p in sorted((root/top).rglob('*')):
            if p.is_symlink():raise ValueError('Symlinks are not deployable')
            if p.is_file():
                name=p.relative_to(root).as_posix()
                if allowed(name):files[name]=p.read_bytes()
    for required in ['morshedapp.ir/index.php','morshedapp.ir/assets/app.css','morshedapp.ir/assets/product.js','morshed-core/bootstrap.php']:
        if required not in files:raise ValueError('Incomplete deployment source')
    return files

def digest(data):return hashlib.sha256(data).hexdigest()

class Publisher:
    def __init__(self,ftp,base,commit):
        if not re.fullmatch(r'[a-f0-9]{40}',commit):raise ValueError('Invalid commit')
        if not base.startswith('/') or '..' in PurePosixPath(base).parts or any(c in base for c in '\r\n\\'):
            raise ValueError('Invalid FTP base path')
        self.ftp=ftp;self.base=base.rstrip('/');self.commit=commit
        self.release=commit+'-'+str(time.time_ns())
        self.private=self.base+'/morshed-core/.deploy/'+self.release
        self.marker=self.base+'/morshed-core/.deploy/maintenance.json'
        self.applied=[];self.maintenance=False;self.phase='preflight'
    def read(self,path):
        out=io.BytesIO();self.ftp.retrbinary('RETR '+path,out.write);return out.getvalue()
    def exists(self,path):
        p=PurePosixPath(path)
        for name,facts in self.ftp.mlsd(str(p.parent),facts=['type']):
            if name==p.name:
                if facts.get('type')!='file':raise RuntimeError('Unexpected remote file type')
                return True
        return False
    def mkdir(self,path,mode='700'):
        # Existing deployment/public directories must be usable; do not treat other errors as absence.
        self.ftp.cwd('/')
        parts=PurePosixPath(path).parts[1:]
        built=''
        for part in parts:
            built+='/'+part
            try:self.ftp.cwd(built)
            except ftplib.error_perm:
                self.ftp.mkd(built);self.ftp.sendcmd('SITE CHMOD '+mode+' '+built)
        self.ftp.cwd('/')
    def upload(self,path,data,mode):
        self.mkdir(str(PurePosixPath(path).parent))
        self.ftp.storbinary('STOR '+path,io.BytesIO(data))
        self.ftp.sendcmd('SITE CHMOD '+mode+' '+path)
        if digest(self.read(path))!=digest(data):raise RuntimeError('Upload integrity mismatch')
    def begin_maintenance(self):
        self.upload(self.marker,json.dumps({'commit':self.commit}).encode(),'600');self.maintenance=True
    def end_maintenance(self):
        if self.maintenance:self.ftp.delete(self.marker);self.maintenance=False
    def preflight(self):
        for path in ['morshed-core/config/local.php','morshed-core/bootstrap.php','morshedapp.ir/index.php','admin.morshedapp.ir/index.php']:
            print('Checking installed component: '+path,flush=True)
            try:present=self.exists(self.base+'/'+path)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'):raise
                raise RuntimeError('FTP_ROOT_MISMATCH') from None
            if not present:raise RuntimeError('FTP_ROOT_MISMATCH')
        self.mkdir(self.base+'/morshed-core/.deploy')
        if self.exists(self.marker):raise RuntimeError('Existing maintenance marker requires operator review')
        # Check fixed public entrypoints, never read private configuration.
        for top,realm in [('morshedapp.ir','site'),('admin.morshedapp.ir','admin')]:
            data=self.read(self.base+'/'+top+'/index.php')
            if ("morshed_run('"+realm+"')").encode() not in data:raise RuntimeError('Wrong public entrypoint')
    def publish(self,files,smoke):
        if not all(allowed(name) or name=='morshedapp.ir/_release.json' for name in files):raise ValueError('Forbidden deployment path')
        self.preflight();self.phase='stage'
        # Every byte is verified before any running code is replaced.
        for name,data in files.items():
            mode='600' if name.startswith('morshed-core/') else '644'
            self.upload(self.private+'/stage/'+name,data,mode)
        self.phase='activate';self.begin_maintenance()
        try:
            for name in sorted(files,key=lambda n:(n.startswith('morshed-core/'),n.endswith('bootstrap.php'),n)):
                target=self.base+'/'+name;backup=self.private+'/backup/'+name
                mode='600' if name.startswith('morshed-core/') else '644'
                self.mkdir(str(PurePosixPath(target).parent),'755' if mode=='644' else '700')
                if mode=='644':self.ftp.sendcmd('SITE CHMOD 755 '+str(PurePosixPath(target).parent))
                had_old=self.exists(target)
                if had_old:
                    self.mkdir(str(PurePosixPath(backup).parent));self.ftp.rename(target,backup)
                record={'target':target,'backup':backup,'old':had_old,'installed':False};self.applied.append(record)
                self.ftp.rename(self.private+'/stage/'+name,target);record['installed']=True
                self.ftp.sendcmd('SITE CHMOD '+mode+' '+target)
            self.phase='http-smoke';self.end_maintenance();smoke(files)
        except Exception:
            self.phase='rollback'
            # Fail closed if maintenance could not be re-established; old code is still restored.
            try:self.begin_maintenance()
            except Exception:pass
            rollback_ok=True
            for r in reversed(self.applied):
                try:
                    if r['installed']:self.ftp.delete(r['target'])
                    if r['old']:self.ftp.rename(r['backup'],r['target'])
                except Exception:rollback_ok=False
            if rollback_ok:self.end_maintenance()
            else:raise RuntimeError('Rollback incomplete; maintenance and private backups retained') from None
            raise RuntimeError('Deployment failed; previous files restored') from None
        self.phase='complete'

def smoke(files):
    layout=files['morshed-core/templates/layout.php']
    css_ref=re.search(rb'<link rel="stylesheet" href="([^"]+)"',layout).group(1)
    def check(url,accept):
        for attempt in range(4):
            try:
                request=urllib.request.Request(url,headers={'Cache-Control':'no-cache'})
                with urllib.request.urlopen(request,timeout=20,context=ssl.create_default_context()) as response:
                    if response.status==200 and accept(response.read(2000000)):return
            except (OSError,ValueError):pass
            if attempt<3:time.sleep(attempt+1)
        raise RuntimeError('HTTP_SMOKE_FAILED')
    for url,name in [('https://morshedapp.ir/assets/app.css','morshedapp.ir/assets/app.css'),('https://morshedapp.ir/assets/product.js','morshedapp.ir/assets/product.js')]:
        check(url+'?release='+os.environ['GITHUB_SHA'],lambda data:digest(data)==digest(files[name]))
    for url in ['https://morshedapp.ir/','https://morshedapp.ir/index.php?r=%2Flogin']:
        check(url,lambda body:css_ref in body)

def main():
    files=plan();commit=os.environ.get('GITHUB_SHA','')
    if not re.fullmatch('[a-f0-9]{40}',commit):raise ValueError('A full GitHub commit SHA is required')
    # Avoid deploying an older queued run after main has advanced.
    req=urllib.request.Request('https://api.github.com/repos/ItachiUchihaNo1/ItachiUchihaNo1.github.io/git/ref/heads/main',headers={'Accept':'application/vnd.github+json','User-Agent':'Morshed-deploy'})
    with urllib.request.urlopen(req,timeout=20) as response:head=json.load(response)['object']['sha']
    if head!=commit:print('Skipped: main has a newer commit.');return
    files['morshedapp.ir/_release.json']=json.dumps({'commit':commit,'schema':6},sort_keys=True).encode()
    context=ssl.create_default_context();context.minimum_version=ssl.TLSVersion.TLSv1_2
    ftp=ftplib.FTP_TLS(context=context,timeout=45)
    ftp.connect(os.environ['DEPLOY_FTPS_HOST'],int(os.environ.get('DEPLOY_FTPS_PORT','21')))
    ftp.auth();ftp.login(os.environ['DEPLOY_FTPS_USER'],os.environ['DEPLOY_FTPS_PASSWORD']);ftp.prot_p();ftp.set_pasv(True);ftp.voidcmd('TYPE I')
    publisher=Publisher(ftp,os.environ.get('DEPLOY_FTPS_BASE','/'),commit)
    try:publisher.publish(files,smoke);print('DEPLOYED '+commit+'; public assets and pages verified. Runtime configuration and database preserved.')
    finally:ftp.close()

if __name__=='__main__':
    try:main()
    except Exception as error:
        # FTP/network errors can contain connection details. Never print credentials or response bodies.
        safe={'FTP_ROOT_MISMATCH','HTTP_SMOKE_FAILED','Existing maintenance marker requires operator review','Deployment failed; previous files restored','Rollback incomplete; maintenance and private backups retained'}
        code=str(error) if str(error) in safe else type(error).__name__
        if isinstance(error,ftplib.Error) and re.match(r'^\d{3}',str(error)):code='FTP_STATUS_'+str(error)[:3]
        print('Deployment stopped ('+code+'). Check FTPS access, home directory, permissions and the private deployment backup. No secret values logged.',file=sys.stderr)
        sys.exit(1)
