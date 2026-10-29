(() => {'use strict';
const kind=document.querySelector('[data-schedule-kind]');
if(kind){const form=kind.form,weekly=form.querySelector('[data-weekly-field]'),date=form.querySelector('[data-date-field]');const sync=()=>{weekly.hidden=!['weekly','monthly'].includes(kind.value);const monthly=form.querySelector('[data-monthly-field]');if(monthly)monthly.hidden=kind.value!=='monthly';date.hidden=kind.value!=='date';const input=date.querySelector('input');input.required=kind.value==='date';input.disabled=kind.value!=='date';};sync();kind.addEventListener('change',sync);}
document.querySelectorAll('[data-slot-preview]').forEach(preview=>{const form=preview.closest('form'),start=form.elements.start_time,end=form.elements.end_time,list=preview.querySelector('[data-preview-list]');const minutes=v=>{if(!/^\d{2}:\d{2}$/.test(v))return NaN;const [h,m]=v.split(':').map(Number);return h*60+m;};const label=m=>String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');const sync=()=>{list.replaceChildren();const from=minutes(start.value),to=minutes(end.value),duration=Number(document.getElementById('duration')?.value||preview.dataset.duration),buffer=Number(document.querySelector('[name=buffer_minutes]')?.value??preview.dataset.buffer);if(!(to>from&&duration>=15)){list.textContent='بازهٔ معتبر انتخاب کنید.';return;}let count=0;for(let t=from;t+duration<=to&&count<100;t+=duration+buffer){const span=document.createElement('span');span.textContent=label(t)+' – '+label(t+duration);list.append(span);count++;}if(!count)list.textContent='بازه از مدت یک جلسه کوتاه‌تر است.';};start.addEventListener('input',sync);end.addEventListener('input',sync);document.getElementById('duration')?.addEventListener('change',sync);document.querySelector('[name=buffer_minutes]')?.addEventListener('input',sync);sync();});
})();

// Keep the date, duration and reservable choices in one visible context.
(()=>{'use strict';
const browser=document.querySelector('[data-slot-browser]');if(!browser||!window.fetch)return;
const choice=document.querySelector('input[name=selected_date]');if(!choice)return;
const booking=choice.form,date=browser.elements.date,duration=browser.elements.duration_minutes,list=booking.querySelector('.slot-options'),submit=booking.querySelector('[data-booking-submit]'),feedback=document.querySelector('[data-slot-feedback]'),empty=document.querySelector('[data-slot-empty]');
const number=new Intl.NumberFormat('fa-IR'),dateFormat=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',dateStyle:'medium'}),timeFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Tehran',hour:'2-digit',minute:'2-digit'});
let busy=false,sequence=0,controller,timer,signature='';
const controls=()=>{if(submit)submit.disabled=busy||!current()||submit.dataset.requestEnabled!=='1'||!list.children.length;if(submit&&!busy)submit.removeAttribute('aria-busy');list.querySelectorAll('input').forEach(input=>input.disabled=busy);};
const current=()=>date.value===choice.value&&Number(duration.value)===Number(booking.elements.duration_minutes.value);
async function load(){
 if(!submit){browser.requestSubmit();return;}
 if(!date.value||!date.checkValidity()){feedback.textContent='یک تاریخ معتبر انتخاب کنید.';submit.disabled=true;return;}
 const serial=++sequence;controller?.abort();controller=new AbortController();const deadline=setTimeout(()=>controller.abort(),12000);let healthy=false;clearTimeout(timer);busy=true;controls();feedback.textContent='در حال بررسی زمان‌های آزاد همین روز…';
 try{
  const requestedDate=date.value,minutes=Number(duration.value),url=new URL(browser.dataset.availabilityUrl,location.origin);url.searchParams.set('mentor_id',browser.elements.mentor_id.value);url.searchParams.set('date',requestedDate);url.searchParams.set('duration_minutes',minutes);
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});const data=await response.json();if(serial!==sequence)return;
  if(!response.ok)throw Error(data.error?.message||'زمان‌ها دریافت نشدند.');if(data.date!==requestedDate||data.duration!==minutes||!Array.isArray(data.slots))throw Error('پاسخ زمان‌بندی معتبر نیست.');
  const selected=list.querySelector('input:checked')?.value,sameDay=current(),nextSignature=JSON.stringify([requestedDate,minutes,data.slots]);
  if(signature!==nextSignature){
   const fragment=document.createDocumentFragment();for(const slot of data.slots){const label=document.createElement('label');label.className='slot-choice';const input=document.createElement('input');input.type='radio';input.name='starts_at';input.required=true;input.value=String(slot.starts_at);input.checked=sameDay&&input.value===selected;const span=document.createElement('span'),day=document.createElement('small'),clock=document.createElement('bdi');day.textContent=dateFormat.format(new Date(slot.starts_at*1000));clock.textContent=timeFormat.format(new Date(slot.starts_at*1000))+' — '+timeFormat.format(new Date(slot.ends_at*1000));span.append(day,document.createElement('br'),clock);label.append(input,span);fragment.append(label);}list.replaceChildren(fragment);signature=nextSignature;
  }
  healthy=true;choice.value=requestedDate;booking.elements.duration_minutes.value=String(minutes);empty.hidden=data.slots.length>0;
  const label=dateFormat.format(new Date(requestedDate+'T12:00:00Z'));document.querySelector('[data-current-slot-date]').textContent=label;document.querySelector('[data-current-slot-duration]').textContent=number.format(minutes);document.querySelector('[data-current-slot-notice]').textContent='روز انتخاب‌شده: '+label+'، به وقت تهران. زمان‌های گرفته‌شده قابل انتخاب نیستند.';if(!sameDay)document.querySelector('[data-next-slot-date]')?.remove();
  feedback.textContent=selected&&sameDay&&!data.slots.some(slot=>String(slot.starts_at)===selected)?'زمان انتخابی دیگر آزاد نیست؛ یک زمان دیگر انتخاب کنید.':(data.slots.length?'فقط زمان‌های آزاد این روز نمایش داده می‌شوند.':'در این روز زمانی برای این مدت آزاد نیست؛ تاریخ را خودتان تغییر دهید.');
  const page=new URL(location.href);page.searchParams.set('date',requestedDate);page.searchParams.set('duration_minutes',String(minutes));history.replaceState(null,'',page);
 }catch(error){if(serial!==sequence)return;feedback.textContent=error.name==='AbortError'?'دریافت زمان‌ها طول کشید؛ نمایش زمان‌ها را دوباره بزنید.':(error.message||'ارتباط برقرار نشد؛ نمایش زمان‌ها را دوباره بزنید.');list.querySelectorAll('input').forEach(input=>input.disabled=true);if(submit)submit.disabled=true;return;}
 finally{clearTimeout(deadline);if(serial===sequence){busy=false;if(healthy&&current())controls();if(!document.hidden)timer=setTimeout(load,20000);}}
}
browser.addEventListener('submit',event=>{if(submit){event.preventDefault();load();}});date.addEventListener('change',load);duration.addEventListener('change',load);
booking.addEventListener('submit',event=>{if(busy||!current()||!list.querySelector('input:checked')){event.preventDefault();feedback.textContent='زمان‌های تاریخ و مدت انتخابی را بررسی و یک زمان آزاد انتخاب کنید.';controls();}});
document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden&&submit)load();});window.addEventListener('online',()=>{if(submit)load();});window.addEventListener('pagehide',()=>{clearTimeout(timer);controller?.abort();});window.addEventListener('pageshow',event=>{if(event.persisted&&submit)load();});if(submit)timer=setTimeout(load,20000);
})();
