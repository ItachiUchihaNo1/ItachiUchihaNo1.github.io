"""Exercise two saved lists and global-topic discovery on the disposable local preview."""
import runpy,urllib.parse,json
state=runpy.run_path(__file__.replace('catalog_http13.py','catalog_http12.py'))
client=state['client'];base=state['base'];post=state['post']
def get(route):
 path,_,query=route.partition('?')
 with client.open(base+urllib.parse.quote(path,safe='')+('&'+query if query else ''),timeout=10) as response:return response.read().decode()
rows=json.loads(get('/api/v1/experiences'))['rows'];experience=rows[0]['id']
assert post('/account/saved/set',{'kind':'experience','experience_id':experience,'saved':'1'})['saved'] is True
html=get('/account/saved?tab=experiences');assert 'catalog-experience' in html and 'aria-pressed="true"' in html
assert 'catalog-experience' not in get('/account/saved?tab=mentors')
assert post('/account/saved/set',{'kind':'experience','experience_id':experience,'saved':'0'})['saved'] is False
assert 'هنوز تجربه‌ای ذخیره نکرده‌ای' in get('/account/saved?tab=experiences')
topics=json.loads(get('/api/v1/topics'))['topics'];topic=next(t for t in topics if int(t['experience_count'])>0)
html=get('/topic?id='+topic['id']);assert topic['title'] in html and 'catalog-experience' in html
data=json.loads(get('/api/v1/experiences?topic='+topic['id']));assert all(row['topic_id']==topic['id'] for row in data['rows'])
tagged=next(row for row in rows if row['issue_tags']);issue=tagged['issue_tags'][0]['id']
data=json.loads(get('/api/v1/experiences?issue='+issue));assert data['rows'] and all(issue in [t['id'] for t in row['issue_tags']] for row in data['rows'])
print('PASS authenticated experience like and unlike, distinct tabs, topic page and reusable issue HTTP filters')
