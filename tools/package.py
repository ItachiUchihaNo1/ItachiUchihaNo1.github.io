"""Build clean source and optional cPanel upload archives; never package private config/storage."""
from pathlib import Path
import argparse, hashlib, json, zipfile

parser=argparse.ArgumentParser()
parser.add_argument('--output',required=True)
parser.add_argument('--deploy-dir')
args=parser.parse_args()
root=Path(__file__).resolve().parent.parent
output=Path(args.output).resolve()
if root in output.parents:raise SystemExit('Archive must be outside the source directory.')
if (root/'morshed-core/config/local.php').exists():raise SystemExit('Use a clean source checkout without private local.php.')
if any(p.is_file() for p in (root/'morshed-core/storage').rglob('*')):raise SystemExit('Runtime storage must not be in the source build.')
for dirname in ['morshed-core','morshedapp.ir','admin.morshedapp.ir','docs','tests','tools']:
    if not (root/dirname).is_dir():raise SystemExit('Missing package component: '+dirname)
expected_logo='ebe6121895542158ed5d21cf760e62e7ef2d4e4ea9e9019ba6642eae16ef064a'
for public in ['morshedapp.ir','admin.morshedapp.ir']:
    if hashlib.sha256((root/public/'assets/morshed-logo.png').read_bytes()).hexdigest()!=expected_logo:raise SystemExit('Official logo bytes changed.')
allowed={'.php','.sql','.json','.md','.png','.webp','.woff2','.txt','.css','.js','.html','.py','.cjs','.example','.yml'}
files=[]
for path in sorted(root.rglob('*')):
    if not path.is_file():continue
    relative=path.relative_to(root)
    # Local upload archives may be kept beside source; never nest them in builds.
    if path.parent==root and path.suffix=='.zip':continue
    if '__pycache__' in relative.parts or '.git' in relative.parts or path.name=='MANIFEST-SHA256.json':continue
    if 'storage' in relative.parts or '.deploy' in relative.parts or path.name.startswith('.env') or path.name=='local.php':raise SystemExit('Refusing sensitive/runtime file: '+str(relative))
    if path.suffix not in allowed and path.name not in ['.htaccess','.gitignore']:raise SystemExit('Unexpected file type: '+str(relative))
    files.append(path)
manifest={p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
manifest_path=root/'MANIFEST-SHA256.json'
manifest_path.write_text(json.dumps({'schema_version':7,'files':manifest},ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
files.append(manifest_path)
def archive(target, selected, prefix):
    target.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(target,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as out:
        for path,name in selected:
            info=zipfile.ZipInfo(prefix+name)
            info.create_system=3
            info.external_attr=(0o100644 << 16)
            info.compress_type=zipfile.ZIP_DEFLATED
            out.writestr(info,path.read_bytes())
    with zipfile.ZipFile(target) as check:
        if check.testzip() is not None:raise RuntimeError('Archive integrity check failed.')
        for path,name in selected:
            if hashlib.sha256(check.read(prefix+name)).digest()!=hashlib.sha256(path.read_bytes()).digest():raise RuntimeError('Archive file mismatch: '+name)
    print(target.name+': '+str(target.stat().st_size)+' bytes; SHA256 '+hashlib.sha256(target.read_bytes()).hexdigest())
archive(output,[(p,p.relative_to(root).as_posix()) for p in files],'morshed/')
if args.deploy_dir:
    deploy=Path(args.deploy_dir).resolve()
    if root==deploy or root in deploy.parents:raise SystemExit('Deployment archives must be outside the source directory.')
    for dirname,name,keep_root in [('morshed-core','core-private.zip',True),('morshedapp.ir','site-public.zip',False),('admin.morshedapp.ir','admin-public.zip',False)]:
        component=root/dirname
        selected=[(p,p.relative_to(component).as_posix()) for p in files if component in p.parents]
        archive(deploy/name,selected,dirname+'/' if keep_root else '')
print(str(len(files))+' clean source files verified; no runtime config, account seed or storage included.')
