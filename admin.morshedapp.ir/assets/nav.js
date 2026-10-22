(()=>{'use strict';
const nav=document.querySelector('.bottom .nav-surface');if(!nav||!window.requestAnimationFrame)return;
const inner=nav.querySelector('.nav-inner'),links=[...inner.querySelectorAll('a')],svg=nav.querySelector('.nav-socket'),path=svg.querySelector('path'),bead=nav.querySelector('.nav-bead');
if(links.length!==5)return;
const motion=window.matchMedia('(prefers-reduced-motion: reduce)'),serverActive=Math.max(0,links.findIndex(a=>a.getAttribute('aria-current')==='page'));let active=serverActive,width=0,centers=[],x=0,target=0,velocity=0,frame=0,last=0,navigating=false,drag=null,suppressClick=0,timer=0;
function remember(i){try{sessionStorage.setItem('morshed.nav.visual-tab',String(i));}catch{}}
function select(i){active=i;links.forEach((a,j)=>{if(j===i)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});bead.textContent='';const icon=links[i].querySelector('.icon');if(icon)bead.appendChild(icon.cloneNode(true));}
function paint(){if(!width)return;const c=Math.max(Math.min(...centers),Math.min(Math.max(...centers),x)),R=28,r=7,top=24,cy=24,d=7,dx=Math.sqrt((R+r)**2-d*d),jx=dx*R/(R+r),jy=cy+d*R/(R+r),b=98,k=12;svg.setAttribute('viewBox',`0 0 ${width} 98`);path.setAttribute('d',`M ${k} ${top} H ${c-dx} A ${r} ${r} 0 0 1 ${c-jx} ${jy} A ${R} ${R} 0 0 0 ${c+jx} ${jy} A ${r} ${r} 0 0 1 ${c+dx} ${top} H ${width-k} Q ${width} ${top} ${width} ${top+k} V ${b-k} Q ${width} ${b} ${width-k} ${b} H ${k} Q 0 ${b} 0 ${b-k} V ${top+k} Q 0 ${top} ${k} ${top} Z`);bead.style.transform=`translate3d(${c-23}px,0,0)`;}
function step(t){const dt=Math.min((t-(last||t-16))/1000,.032);last=t;velocity+=((target-x)*180-velocity*23)*dt;x+=velocity*dt;paint();if(Math.abs(target-x)>.08||Math.abs(velocity)>.5){frame=requestAnimationFrame(step);}else{x=target;velocity=0;frame=0;last=0;paint();}}
function move(value,instant=false){target=value;if(instant||motion.matches){cancelAnimationFrame(frame);frame=0;last=0;x=target;velocity=0;paint();}else if(!frame){last=0;frame=requestAnimationFrame(step);}}
function measure(){const rect=nav.getBoundingClientRect();width=rect.width;centers=links.map(a=>{const b=a.getBoundingClientRect();return b.left-rect.left+b.width/2;});move(centers[active],true);}
function navigate(i){if(navigating)return;select(i);move(centers[i]);remember(i);if(links[i].href===location.href)return;navigating=true;timer=setTimeout(()=>location.assign(links[i].href),motion.matches?0:90);}
nav.classList.add('nav-enhanced');select(active);measure();bead.hidden=false;
try{const previous=sessionStorage.getItem('morshed.nav.visual-tab');if(previous!==null&&/^[0-4]$/.test(previous)&&Number(previous)!==active&&!motion.matches){x=centers[Number(previous)];paint();move(centers[active]);}}catch{}remember(active);
links.forEach((link,i)=>link.addEventListener('click',event=>{if(event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||!navigator.onLine||new URL(link.href).origin!==location.origin)return;event.preventDefault();if(Date.now()<suppressClick)return;navigate(i);}));
nav.addEventListener('pointerdown',event=>{if(navigating||event.button!==0)return;drag={id:event.pointerId,start:event.clientX,moved:false};});
nav.addEventListener('pointermove',event=>{if(!drag||drag.id!==event.pointerId)return;if(!drag.moved&&Math.abs(event.clientX-drag.start)<8)return;drag.moved=true;nav.setPointerCapture(event.pointerId);const rect=nav.getBoundingClientRect();move(Math.max(Math.min(...centers),Math.min(Math.max(...centers),event.clientX-rect.left)));});
nav.addEventListener('pointerup',event=>{if(!drag||drag.id!==event.pointerId)return;const moved=drag.moved;drag=null;if(nav.hasPointerCapture(event.pointerId))nav.releasePointerCapture(event.pointerId);if(moved){suppressClick=Date.now()+500;const i=centers.reduce((best,c,index)=>Math.abs(c-target)<Math.abs(centers[best]-target)?index:best,0);navigate(i);}});
nav.addEventListener('pointercancel',()=>{drag=null;move(centers[active]);});
if(window.ResizeObserver)new ResizeObserver(measure).observe(nav);else addEventListener('resize',measure);
addEventListener('pageshow',event=>{if(event.persisted){clearTimeout(timer);navigating=false;drag=null;select(serverActive);measure();remember(serverActive);}});
})();
