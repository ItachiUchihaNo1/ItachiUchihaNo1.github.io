from pathlib import Path
import sys,json,urllib.request,concurrent.futures,hashlib
# Development-only dependency: pip install shapely
import argparse
parser=argparse.ArgumentParser()
parser.add_argument('--cache-dir',required=True)
parser.add_argument('--output-root',required=True)
args=parser.parse_args()
from shapely.geometry import shape,mapping
base='https://raw.githubusercontent.com/hosseinhabibi2004/iran-geojson/890ff6c107c54d852328bbf7f9472d146c151ebf/'
cache=Path(args.cache_dir);cache.mkdir(parents=True,exist_ok=True)
for filename,remote in [('LICENSE','LICENSE'),('provinces.all.min.geojson','data/provinces/provinces.all.min.geojson')]:
    target=cache/filename
    if not target.exists():target.write_bytes(urllib.request.urlopen(base+remote,timeout=90).read())
def fetch(code):
    p=cache/(code+'.all.min.geojson')
    if not p.exists():p.write_bytes(urllib.request.urlopen(base+f'data/counties/{code}/{code}.all.min.geojson',timeout=90).read())
    return code,json.loads(p.read_text(encoding='utf-8'))
def name(f):
    tags=f['properties']['tags'];raw=tags.get('name:fa',tags.get('name',''))
    return raw.removeprefix('استان ').removeprefix('شهرستان ').strip()
def feature(f,ident):
    geometry=shape(f['geometry']).simplify(.008,preserve_topology=True)
    # Coarse display geometry only; IDs and names retain the source relationship.
    return {'type':'Feature','id':ident,'properties':{'id':ident,'name':name(f)},'geometry':mapping(geometry)}
provinces=json.loads((cache/'provinces.all.min.geojson').read_text(encoding='utf-8'))['features']
assert len(provinces)==31
places=[];features=[];counties={};seen=set()
codes=[]
for f in provinces:
    code=f['properties']['tags']['ISO3166-2'];codes.append(code)
    ident='osm-'+str(f['properties']['id']);seen.add(ident)
    places.append({'id':ident,'parent_id':None,'kind':'province','name':name(f),'source_ref':'https://www.openstreetmap.org/relation/'+str(f['properties']['id'])})
    features.append(feature(f,ident));counties[code]=(ident,[])
with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
    for code,data in pool.map(fetch,codes):
        parent,items=counties[code]
        for f in data['features']:
            if f['geometry']['type'] not in ['Polygon','MultiPolygon'] or not f['properties'].get('tags',{}).get('name'):continue
            ident='osm-'+str(f['properties']['id'])
            if ident in seen:raise ValueError('Duplicate geography '+ident)
            seen.add(ident);places.append({'id':ident,'parent_id':parent,'kind':'county','name':name(f),'source_ref':'https://www.openstreetmap.org/relation/'+str(f['properties']['id'])});items.append(feature(f,ident))
root=Path(args.output_root);private=root/'morshed-core/data';private.mkdir(exist_ok=True)
def encoded(o):return json.dumps(o,ensure_ascii=False,separators=(',',':'))+'\n'
metadata={'source':'OpenStreetMap via hosseinhabibi2004/iran-geojson','retrieved':'2026-10-21','license':'ODbL-1.0 (upstream data); GPL-3.0 repository redistribution','source_revision':'890ff6c107c54d852328bbf7f9472d146c151ebf','official':False,'simplification_degrees':.008}
(private/'iran-places.json').write_text(encoded({'metadata':metadata,'places':places}),encoding='utf-8')
for public in ['morshedapp.ir','admin.morshedapp.ir']:
    geo=root/public/'assets/geo';geo.mkdir(exist_ok=True)
    (geo/'provinces.json').write_text(encoded({'type':'FeatureCollection','features':features}),encoding='utf-8')
    for parent,items in counties.values():(geo/(parent+'.json')).write_text(encoded({'type':'FeatureCollection','features':items}),encoding='utf-8')
    (geo/'SOURCE.txt').write_text(encoded(metadata)+'Map data © OpenStreetMap contributors, https://www.openstreetmap.org/copyright\nBoundaries are reference data, not an official statement.\nSource: https://github.com/hosseinhabibi2004/iran-geojson\n',encoding='utf-8')
    (geo/'LICENSE.txt').write_bytes((cache/'LICENSE').read_bytes())
print('Reference places:',len(places),'provinces:',len(features),'counties:',len(places)-31)
print('Province geometry bytes:',(root/'morshedapp.ir/assets/geo/provinces.json').stat().st_size)
