"""Real HTTP checks on isolated loopback fixture only; never production accounts."""
import json,sys,urllib.request,urllib.parse,urllib.error,http.cookiejar,datetime,secrets
from pathlib import Path
scratch=Path(sys.argv[1]);cfg=json.loads((scratch/'private-config.json').read_text());fixture=json.loads((scratch/'fixture.json').read_text());p=fixture['product']
assert cfg['environment']=='testing' and cfg['origins']['site'].startswith('http://127.0.0.1:')
class Client:
 def __init__(self,origin):self.base=origin+'/index.php?r=';self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,route,data=None,csrf=True):
  path,_,query=route.partition('?');url=self.base+urllib.parse.quote(path,safe='')+('&'+query if query else '');headers={}
  if data is not None:
   headers['Content-Type']='application/json'
   if csrf:headers['X-CSRF-Token']=self.get('/api/v1/csrf')['csrf']
  try:response=self.opener.open(urllib.request.Request(url,data=None if data is None else json.dumps(data).encode(),headers=headers),timeout=10)
  except urllib.error.HTTPError as error:response=error
  body=response.read().decode();return response.status,json.loads(body) if 'application/json' in response.headers.get('Content-Type','') else body
 def get(self,path):status,body=self.req(path);assert status==200,(status,body);return body
 def post(self,path,data):status,body=self.req('/api/v1'+path,data);assert status==200,(status,body);return body
host=Client(cfg['origins']['site']);user=Client(cfg['origins']['site']);admin=Client(cfg['origins']['admin'])
host.post('/auth/password',{'phone':p['mentor_phone'],'password':p['mentor_password']});user.post('/auth/password',{'phone':p['owner_phone'],'password':p['owner_password']});admin.post('/auth/password',{'username':fixture['admin_username'],'password':fixture['admin_password']})
assert 'زمان‌های مشاوره من' in host.get('/account')
own=host.get('/mentor/experiences');assert 'نوشتن تجربه' not in own and 'ویرایش</a>' not in own and p['experience_id'] in own
assert user.req('/api/v1/mentor/calendar')[0]==403
assert host.req('/api/v1/mentor/experience/save',{'title':'not allowed'})[0]==410
assert host.req('/api/v1/mentor/calendar/settings',{},False)[0]==419
host.post('/mentor/calendar/settings',{'duration_minutes':30,'buffer_minutes':15,'lead_hours':1,'daily_capacity':10})
date=(datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=3,minutes=30)))+datetime.timedelta(days=6)).strftime('%Y-%m-%d')
existing=next((r for r in host.get('/api/v1/mentor/calendar')['dates'] if r['local_date']==date),{})
host.post('/mentor/calendar/rule',{'id':existing.get('id',''),'kind':'date','date':date,'weekday':0,'start_time':'11:00','end_time':'14:00'})
cal=host.get('/api/v1/mentor/calendar');rule=next(r for r in cal['dates'] if r['local_date']==date)
host.post('/mentor/calendar/rule',{'kind':'date','id':rule['id'],'date':date,'weekday':0,'start_time':'12:00','end_time':'15:00'})
slots=host.get('/api/v1/slots?mentor_id='+p['mentor_id']+'&date='+date)['slots'];assert slots
data={'user_id':p['owner'],'mentor_id':p['mentor_id'],'starts_at':slots[0]['starts_at'],'subject':'موضوع جلسه آزمایشی','problem':'شرح نیاز کاربر در آزمون رابط','experience_id':p['experience_id'],'requested_method':'audio','idempotency_key':secrets.token_hex(16)}
booking=user.post('/bookings/create',dict(data,duration_minutes=30))['id'];route='/api/v1/booking?id='+booking
assert host.get(route)['booking']['experience_id']==p['experience_id']
assert any(r['id']==booking for r in host.get('/api/v1/sessions?direction=incoming')['rows'])
assert any(r['id']==booking for r in user.get('/api/v1/sessions?direction=outgoing')['rows'])
assert user.req('/api/v1/booking/action',{'id':booking,'action':'accept'})[0]==409
host.post('/booking/action',{'id':booking,'action':'accept'})
assert any(n['title']=='جلسه شما قطعی شد' for n in user.get('/api/v1/notifications')['notifications'])
html=host.get('/booking?id='+booking);assert 'مشاهدهٔ اتاق جلسه' in html; room_page=host.get('/meeting?id='+booking);assert 'سرور تماس در حال آماده‌سازی است.' in room_page and 'data-join-method=' not in room_page
user.post('/booking/action',{'id':booking,'action':'cancel','note':'دلیل لغو آزمون'})
assert host.get(route)['booking']['state']=='cancelled'
host.post('/mentor/calendar/remove',{'kind':'date','id':rule['id']})
assert host.get(route)['booking']['state']=='cancelled'
(scratch/'consultations14-proof.json').write_text(json.dumps({'booking_id':booking,'date':date}),encoding='utf-8')
print('PASS mentor account tools, read-only ownership, calendar CRUD, CSRF, role checks, experience-bound request, acceptance notification, two directions, cancellation and unavailable room')
