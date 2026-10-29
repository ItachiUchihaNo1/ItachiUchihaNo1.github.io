from pathlib import Path
import sys,json,urllib.parse
scratch=Path(sys.argv[1]);exec(Path(__file__).with_name('consultations_http14.py').read_text('utf-8').split('host=Client(')[0])
f=json.loads((scratch/'slots31-fixture.json').read_text());guest=Client(cfg['origins']['site']);path='/api/v1/slots/availability?'+urllib.parse.urlencode({'mentor_id':f['mentor_id'],'date':f['date']})
short=guest.get(path+'&duration_minutes=15');assert short['date']==f['date'] and short['duration']==15;assert [s['starts_at'] for s in short['slots']]==[f['start'],f['start']+1800,f['start']+2700]
long=guest.get(path+'&duration_minutes=30');assert [s['starts_at'] for s in long['slots']]==[f['start']+1800]
assert guest.req(path+'&duration_minutes=20')[0]==400
assert guest.req('/api/v1/slots/availability?mentor_id='+f['mentor_id']+'&date=invalid&duration_minutes=15')[0]==400
html=guest.get('/slots?mentor_id='+f['mentor_id']+'&date='+f['date']+'&duration_minutes=15');assert 'data-slot-browser' in html and 'name="selected_date" value="'+f['date']+'"' in html and '/assets/schedule.js?v=31' in html
print('PASS SLOTS31 HTTP: lightweight public availability, exact 15/30 grids, overlap exclusion, input validation and date-bound booking markup')
