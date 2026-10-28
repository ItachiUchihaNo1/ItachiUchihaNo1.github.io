(() => {
  'use strict';
  const form = document.querySelector('[data-atlas-filters]');
  if (!form) return;
  const panel = document.querySelector('[data-filter-panel]'), mobile = matchMedia('(max-width:700px)');
  if (panel) { panel.open = !mobile.matches; mobile.addEventListener('change',()=>{panel.open=form.classList.contains('pill-filter-form')||!mobile.matches;}); }
  const province = form.querySelector('[data-province]'), county = form.querySelector('[data-county]');
  let request = 0;
  province.addEventListener('change', async () => {
    const serial = ++request;
    county.replaceChildren(new Option('همهٔ شهرستان‌ها', ''));
    if (!province.value) return;
    county.disabled = true;
    try {
      const url = new URL(form.dataset.placesUrl, location.origin); url.searchParams.set('province', province.value);
      const response = await fetch(url, {headers:{Accept:'application/json'}});
      if (!response.ok) throw new Error('places');
      const data = await response.json(); if (serial !== request) return;
      for (const place of data.places) county.append(new Option(place.name, place.id));
    } catch (_) { county.append(new Option('بارگیری نشد؛ دوباره استان را انتخاب کنید', '')); }
    finally { if (serial === request) county.disabled = false; }
  });
  const map = document.querySelector('[data-atlas-map]');
  if (!map) return;
  const svg = map.querySelector('svg'), status = map.querySelector('[data-map-status]'), retry = map.querySelector('[data-map-retry]');
  const labels={experiences:'تجربه',mentors:'مرشد',organizations:'مجموعه'}, layers=Object.entries(JSON.parse(map.dataset.layers||'{}')).map(([kind,rows])=>({kind,counts:new Map(rows.map(r=>[r.id,Number(r.total)]))}));
  const counts=new Map();for(const layer of layers)for(const [id,total] of layer.counts)counts.set(id,(counts.get(id)||0)+total);
  const ns = 'http://www.w3.org/2000/svg';
  const choose = (id,kind) => {
    if(kind)form.querySelector('[name="layer"]').value=kind;
    if (map.dataset.province) county.value = id; else { province.value = id; county.value = ''; }
    form.requestSubmit();
  };
  const inRing=(point,ring)=>{let inside=false;for(let i=0,j=ring.length-1;i<ring.length;j=i++){const a=ring[i],b=ring[j];if((a[1]>point[1])!==(b[1]>point[1])&&point[0]<(b[0]-a[0])*(point[1]-a[1])/(b[1]-a[1])+a[0])inside=!inside;}return inside;};
  const regionPoint=(polygons,l,t,r,b)=>{const valid=p=>polygons.some(poly=>inRing(p,poly[0])&&!poly.slice(1).some(h=>inRing(p,h)));const mid=[(l+r)/2/.84,-(t+b)/2];if(valid(mid))return [mid[0]*.84,-mid[1]];for(let n=1;n<10;n++)for(let m=1;m<10;m++){const p=[(l+(r-l)*n/10)/.84,-(t+(b-t)*m/10)];if(valid(p))return [p[0]*.84,-p[1]];}const p=polygons[0][0][0];return [p[0]*.84,-p[1]];};
  async function load() {
    retry.hidden = true; status.hidden = false; status.textContent = 'در حال بارگیری نقشه…';
    try {
      const file = map.dataset.province || 'provinces';
      if (!/^(provinces|osm-\d+)$/.test(file)) throw new Error('region');
      const response = await fetch('/assets/geo/' + file + '.json?v=22r', {cache:'force-cache'});
      if (!response.ok) throw new Error('map');
      const data = await response.json(), paths = [], markers = []; let x=Infinity,y=Infinity,maxX=-Infinity,maxY=-Infinity;
      for (const f of data.features) {
        const polygons = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
        let d = '',left=Infinity,top=Infinity,right=-Infinity,bottom=-Infinity;
        for (const polygon of polygons) for (const ring of polygon) {
          d += ring.map(([x,y],i) => { const px=x*0.84,py=-y;left=Math.min(left,px);right=Math.max(right,px);top=Math.min(top,py);bottom=Math.max(bottom,py);return (i?'L':'M')+px+','+py; }).join('')+'Z';
        }
        const path = document.createElementNS(ns, 'path'), count=counts.get(f.id)||0;
        path.setAttribute('d', d);path.setAttribute('tabindex','0');path.setAttribute('role','button');path.setAttribute('fill-rule','evenodd');
        path.setAttribute('aria-label',f.properties.name+'، '+count.toLocaleString('fa-IR')+' نتیجه');path.dataset.level=count?'populated':'empty';
        const title=document.createElementNS(ns,'title');title.textContent=path.getAttribute('aria-label');path.append(title);
        path.addEventListener('click',()=>choose(f.id));path.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose(f.id);}});paths.push(path);x=Math.min(x,left);y=Math.min(y,top);maxX=Math.max(maxX,right);maxY=Math.max(maxY,bottom);if(count){const present=layers.filter(layer=>layer.counts.get(f.id));present.forEach((layer,index)=>{const dot=document.createElementNS(ns,'circle');const spread=(index-(present.length-1)/2)*(map.dataset.province?.13:.34);const marker=regionPoint(polygons,left+spread,top,right+spread,bottom);dot.setAttribute('cx',marker[0]);dot.setAttribute('cy',marker[1]);dot.setAttribute('r',map.dataset.province?'.045':'.12');dot.setAttribute('class','atlas-presence');dot.dataset.kind=layer.kind;dot.setAttribute('aria-label',f.properties.name+'، '+layer.counts.get(f.id).toLocaleString('fa-IR')+' '+labels[layer.kind]+'؛ نشانگر منطقه');dot.setAttribute('role','button');dot.setAttribute('tabindex','0');const caption=document.createElementNS(ns,'title');caption.textContent=dot.getAttribute('aria-label');dot.append(caption);dot.addEventListener('click',()=>choose(f.id,layer.kind));dot.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose(f.id,layer.kind);}});markers.push(dot);});}
      }
      if (!Number.isFinite(x)) throw new Error('empty geometry');
      svg.setAttribute('viewBox',`${x-.1} ${y-.1} ${maxX-x+.2} ${maxY-y+.2}`);svg.replaceChildren(...paths,...markers);svg.removeAttribute('hidden');status.hidden=true;
    } catch (_) { svg.setAttribute('hidden','');status.textContent='نقشه بارگیری نشد؛ انتخاب مکان از فهرست همچنان در دسترس است.';retry.hidden=false; }
  }
  retry.addEventListener('click',load);if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting)){observer.disconnect();load();}},{rootMargin:'200px'});observer.observe(map);}else{load();}
})();
