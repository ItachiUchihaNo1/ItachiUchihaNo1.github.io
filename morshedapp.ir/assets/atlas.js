(() => {
  'use strict';
  const form = document.querySelector('[data-atlas-filters]');
  if (!form) return;
  const panel = document.querySelector('[data-filter-panel]'), mobile = matchMedia('(max-width:700px)');
  if (panel) { panel.open = !mobile.matches; mobile.addEventListener('change',()=>{panel.open=!mobile.matches;}); }
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
  const counts = new Map(JSON.parse(map.dataset.regions).map(r => [r.id, Number(r.total)]));
  const ns = 'http://www.w3.org/2000/svg';
  const choose = id => {
    if (map.dataset.province) county.value = id; else { province.value = id; county.value = ''; }
    form.requestSubmit();
  };
  async function load() {
    retry.hidden = true; status.hidden = false; status.textContent = 'در حال بارگیری نقشه…';
    try {
      const file = map.dataset.province || 'provinces';
      if (!/^(provinces|osm-\d+)$/.test(file)) throw new Error('region');
      const response = await fetch('/assets/geo/' + file + '.json', {cache:'force-cache'});
      if (!response.ok) throw new Error('map');
      const data = await response.json(), points = [], paths = [];
      for (const f of data.features) {
        const polygons = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
        let d = '';
        for (const polygon of polygons) for (const ring of polygon) {
          d += ring.map(([x,y],i) => { const p=[x*0.84,-y];points.push(p);return (i?'L':'M')+p.join(','); }).join('')+'Z';
        }
        const path = document.createElementNS(ns, 'path'), count=counts.get(f.id)||0;
        path.setAttribute('d', d);path.setAttribute('tabindex','0');path.setAttribute('role','button');path.setAttribute('fill-rule','evenodd');
        path.setAttribute('aria-label',f.properties.name+'، '+count.toLocaleString('fa-IR')+' نتیجه');path.dataset.level=count?'populated':'empty';
        const title=document.createElementNS(ns,'title');title.textContent=path.getAttribute('aria-label');path.append(title);
        path.addEventListener('click',()=>choose(f.id));path.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose(f.id);}});paths.push(path);
      }
      if (!points.length) throw new Error('empty geometry');
      const xs=points.map(p=>p[0]),ys=points.map(p=>p[1]);
      let x=Infinity,y=Infinity,maxX=-Infinity,maxY=-Infinity;for(let i=0;i<xs.length;i++){x=Math.min(x,xs[i]);maxX=Math.max(maxX,xs[i]);y=Math.min(y,ys[i]);maxY=Math.max(maxY,ys[i]);}
      svg.setAttribute('viewBox',`${x-.1} ${y-.1} ${maxX-x+.2} ${maxY-y+.2}`);svg.replaceChildren(...paths);svg.removeAttribute('hidden');status.hidden=true;
    } catch (_) { svg.setAttribute('hidden','');status.textContent='نقشه بارگیری نشد؛ انتخاب مکان از فهرست همچنان در دسترس است.';retry.hidden=false; }
  }
  retry.addEventListener('click',load);load();
})();
