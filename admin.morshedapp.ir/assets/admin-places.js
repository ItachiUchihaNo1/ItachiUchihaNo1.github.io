(() => {
  'use strict';
  for (const form of document.querySelectorAll('form')) {
    const province=form.querySelector('[name="province_id"]'),county=form.querySelector('[name="county_id"]');
    if (!province || !county) continue;
    const options=Array.from(county.options);
    const filter=()=>{for(const option of options){if(!option.value)continue;option.hidden=option.dataset.parent!==province.value;option.disabled=option.hidden;}if(county.selectedOptions[0]?.disabled)county.value='';};
    province.addEventListener('change',filter);filter();
  }
})();
