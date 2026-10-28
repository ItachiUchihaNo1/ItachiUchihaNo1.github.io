(()=>{'use strict';
if(typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal)return;
const normalize=s=>s.replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/‌/g,' ').trim().toLowerCase();
let serial=0;
for(const form of document.querySelectorAll('.classification-discovery-form,[data-atlas-filters]')){
 const layout=form.closest('.discovery-layout,.atlas-layout');layout.classList.add('pill-filter-layout');form.classList.add('pill-filter-form');
 const panel=form.closest('[data-filter-panel]');if(panel){panel.open=true;panel.querySelector('summary').hidden=true;}
 const nodes=[...form.querySelectorAll(':scope > .dimension-filter,:scope > label')];
 for(const node of nodes){
  const select=node.querySelector('select');if(!select&&!node.matches('.dimension-filter'))continue;
  const title=node.matches('details')?node.querySelector('summary').textContent.split(' · ')[0]:node.firstChild.textContent.trim();
  const button=document.createElement('button');button.type='button';button.className='filter-pill';button.setAttribute('aria-haspopup','dialog');
  const label=document.createElement('span'),chevron=document.createElement('span');label.textContent=title;chevron.textContent='⌄';chevron.setAttribute('aria-hidden','true');button.append(label,chevron);
  const dialog=document.createElement('dialog');dialog.className='filter-dialog';dialog.dir='rtl';const id='filter-title-'+(++serial);dialog.setAttribute('aria-labelledby',id);
  const head=document.createElement('header'),heading=document.createElement('h2'),close=document.createElement('button');heading.id=id;heading.textContent=title;close.type='button';close.className='filter-close';close.textContent='×';close.setAttribute('aria-label','بستن');head.append(heading,close);
  const content=document.createElement('div');content.className='filter-dialog-body';
  const search=document.createElement('input');search.type='search';search.placeholder='جست‌وجو در گزینه‌ها';search.setAttribute('aria-label','جست‌وجو در '+title);content.append(search);
  let list,staged,snapshot,applied=false;
  node.before(button,dialog);node.hidden=true;
  if(select){list=document.createElement('div');list.className='filter-choice-list';content.append(list);}
  else{list=node.querySelector('.dimension-options');content.append(list);node.querySelector('[data-option-search]').disabled=true;}
  const footer=document.createElement('footer'),apply=document.createElement('button'),clear=document.createElement('button');apply.type=clear.type='button';apply.textContent='اعمال فیلتر';clear.textContent='پاک کردن';clear.className='secondary';footer.append(apply,clear);dialog.append(head,content,footer);
  const chosen=()=>select?(select.selectedIndex>0?select.selectedOptions[0]?.textContent:''):[...list.querySelectorAll('input:checked')].length;
  const update=()=>{const value=chosen();label.textContent=title+(value?' · '+value:'');button.classList.toggle('is-active',!!value);};update();select?.addEventListener('change',update);
  const render=()=>{list.replaceChildren();for(const option of select.options){const row=document.createElement('button');row.type='button';row.className='filter-choice';row.dataset.value=option.value;row.textContent=option.textContent;row.setAttribute('aria-pressed',String(option.value===staged));row.disabled=option.disabled;row.addEventListener('click',()=>{staged=option.value;for(const sibling of list.children)sibling.setAttribute('aria-pressed',String(sibling===row));});list.append(row);}};
  button.addEventListener('click',()=>{applied=false;search.value='';if(select){staged=select.value;render();}else{snapshot=[...list.querySelectorAll('input')].map(c=>c.checked);for(const row of list.children)row.hidden=false;}dialog.showModal();search.focus();});
  close.addEventListener('click',()=>dialog.close());dialog.addEventListener('click',e=>{if(e.target===dialog){const box=dialog.getBoundingClientRect();if(e.clientX<box.left||e.clientX>box.right||e.clientY<box.top||e.clientY>box.bottom)dialog.close();}});
  dialog.addEventListener('close',()=>{if(!applied&&!select&&snapshot)[...list.querySelectorAll('input')].forEach((c,i)=>c.checked=snapshot[i]);button.focus();});
  search.addEventListener('input',()=>{for(const row of list.children)row.hidden=!normalize(row.textContent).includes(normalize(search.value));});
  clear.addEventListener('click',()=>{if(select){staged=select.options[0].value;render();}else for(const c of list.querySelectorAll('input'))c.checked=false;search.value='';for(const row of list.children)row.hidden=false;});
  search.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();apply.click();}});
  apply.addEventListener('click',()=>{if(select){const changed=select.value!==staged;select.value=staged;if(changed)select.dispatchEvent(new Event('change',{bubbles:true}));}applied=true;update();dialog.close();form.requestSubmit();});
 }
}
})();
