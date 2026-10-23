(() => {
'use strict';const kinds=['experiences','mentors','organizations'];
const apply=(kind,on)=>{document.querySelectorAll('[data-view-toggle="'+kind+'"]').forEach(c=>c.checked=on);document.querySelectorAll('[data-view-kind="'+kind+'"]').forEach(grid=>{grid.classList.toggle('thumbnail-view',on);grid.querySelectorAll('[data-detail-url]').forEach(card=>{card.removeAttribute('tabindex');card.removeAttribute('role');});});};
kinds.forEach(kind=>{let on=false;try{on=localStorage.getItem('morshed.view.'+kind)==='thumbnail';}catch(_){}apply(kind,on);});
document.addEventListener('change',e=>{const kind=e.target.dataset.viewToggle;if(!kinds.includes(kind))return;apply(kind,e.target.checked);try{localStorage.setItem('morshed.view.'+kind,e.target.checked?'thumbnail':'card');}catch(_){}});
document.addEventListener('click',e=>{const card=e.target.closest('.thumbnail-view [data-detail-url]');if(card&&!e.target.closest('a,button,input,form'))location.assign(card.dataset.detailUrl);});
document.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.matches('.thumbnail-view [data-detail-url]')){e.preventDefault();location.assign(e.target.dataset.detailUrl);}});
window.addEventListener('storage',e=>kinds.forEach(kind=>{if(e.key==='morshed.view.'+kind)apply(kind,e.newValue==='thumbnail');}));
})();