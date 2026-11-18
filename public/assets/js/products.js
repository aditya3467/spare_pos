(() => {
 const raw=document.getElementById('product-master-data');const masters=raw?JSON.parse(raw.textContent):null;
 function parentFilter(){const category=document.querySelector('[data-product-category]'),sub=document.querySelector('[data-product-subcategory]');if(!category||!sub)return;for(const option of sub.options){if(!option.value)continue;option.hidden=!!category.value&&option.dataset.parent!==category.value;option.disabled=option.hidden;}if(sub.selectedOptions[0]?.disabled)sub.value='';}
 function selectSubcategoryParent(sub){
  const form=sub.closest('#product-form');if(!form)return;
  const category=form.querySelector('[data-product-category]');
  const parent=sub.selectedOptions[0]?.dataset.parent;
  if(!category || !sub.value || !parent)return;
  const option=[...category.options].find(option=>option.value===parent && !option.disabled);
  if(!option)return;
  category.value=parent;parentFilter();
 }
 function bikes(row,reset=false){if(!masters)return;const maker=row.querySelector('[data-bike-maker]'),model=row.querySelector('[data-bike-model]'),variant=row.querySelector('[data-bike-variant]');for(const option of model.options){if(!option.value)continue;const record=masters.vehicle_models.find(r=>String(r.id)===option.value);option.disabled=String(record.manufacturer_id)!==maker.value;option.hidden=option.disabled;}if(reset||model.selectedOptions[0]?.disabled)model.value='';for(const option of variant.options){if(!option.value)continue;const record=masters.vehicle_variants.find(r=>String(r.id)===option.value);option.disabled=String(record.model_id)!==model.value;option.hidden=option.disabled;}if(reset||variant.selectedOptions[0]?.disabled)variant.value='';}
 const openingCost = () => {const form=document.getElementById('product-form');if(form?.elements.opening_quantity && form.elements.opening_cost){form.elements.opening_cost.required=Number(form.elements.opening_quantity.value)>0;}};
 document.addEventListener('input',event=>{if(event.target.name==='opening_quantity')openingCost();});openingCost();
 const initialSub=document.querySelector('#product-form [data-product-subcategory]');
 if(initialSub && !document.querySelector('#product-form [data-product-category]').value)selectSubcategoryParent(initialSub);
 let index=1000;parentFilter();document.querySelectorAll('[data-fitment-row]').forEach(r=>bikes(r));
 document.addEventListener('change',event=>{if(event.target.matches('#product-form [data-product-subcategory]'))selectSubcategoryParent(event.target);if(event.target.matches('[data-product-category]'))parentFilter();if(event.target.matches('[data-bike-maker]'))bikes(event.target.closest('[data-fitment-row]'),true);if(event.target.matches('[data-bike-model]'))bikes(event.target.closest('[data-fitment-row]'));});
 document.addEventListener('click',event=>{const spec=event.target.closest('[data-add-specification]'),fit=event.target.closest('[data-add-fitment]');if(spec||fit){const template=document.getElementById(spec?'specification-template':'fitment-template');const container=document.getElementById(spec?'specification-rows':'fitment-rows');container.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(index++)));if(fit)bikes(container.lastElementChild);container.lastElementChild.querySelector('input,select')?.focus();}const remove=event.target.closest('[data-remove-row]');if(remove)remove.closest('.specification-row,.fitment-row').remove();});
 document.addEventListener('submit',event=>{const form=event.target;if(form.dataset.productConfirm&&!confirm(form.dataset.productConfirm)){event.preventDefault();return;}if(form.id==='product-form'){const button=form.querySelector('[type="submit"]');button.disabled=true;form.querySelector('[role="status"]').textContent='Saving product…';}});
 // Progressive enhancement of the existing listing; forms elsewhere are untouched.
 const filters=document.querySelector('.product-filters');
 if(filters && filters.elements.route?.value==='/products' && window.fetch){
  const search=filters.elements.q,status=document.getElementById('product-search-status');
  let timer=null,controller=null,generation=0,composing=false;
  function invalidate(){clearTimeout(timer);generation++;controller?.abort();}
  function listingUrl(page=1){const url=new URL(filters.getAttribute('action'),location.href);url.search=new URLSearchParams(new FormData(filters)).toString();const query=search.value.trim();if([...query].length<2)url.searchParams.delete('q');else url.searchParams.set('q',query);url.searchParams.set('page',String(page));return url;}
  async function load(url,historyMode='replace'){
   invalidate();const request=generation;controller=new AbortController();
   status.textContent='Searching…';status.classList.add('is-loading');document.getElementById('product-results').setAttribute('aria-busy','true');
   try{
    const response=await fetch(url,{headers:{Accept:'application/json'},signal:controller.signal});
    if(!response.ok)throw new Error('Search is unavailable. Please try again.');
    if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Your session may have expired. Reload to sign in.');
    const result=await response.json();if(request!==generation)return;
    const template=document.createElement('template');template.innerHTML=result.html;
    if(!template.content.querySelector('#product-results'))throw new Error('Unable to display results. Please try again.');
    document.getElementById('product-results').replaceWith(template.content);document.getElementById('product-total').textContent=result.total;
    url.searchParams.set('page',String(result.page));
    if(historyMode==='push')history.pushState(null,'',url);else if(historyMode==='replace')history.replaceState(null,'',url);
    status.textContent=result.total===0?'No matching products.':`${result.total} products found.`;
   }catch(error){if(request!==generation || error.name==='AbortError')return;status.textContent=error.message || 'Connection interrupted. Please retry.';}
   finally{if(request===generation){status.classList.remove('is-loading');document.getElementById('product-results').removeAttribute('aria-busy');}}
  }
  function typed(){invalidate();status.classList.remove('is-loading');document.getElementById('product-results').removeAttribute('aria-busy');if(composing)return;if([...search.value.trim()].length<2)load(listingUrl());else timer=setTimeout(()=>load(listingUrl()),300);}
  search.addEventListener('input',typed);
  search.addEventListener('compositionstart',()=>{composing=true;invalidate();});
  search.addEventListener('compositionend',()=>{composing=false;typed();});
  filters.addEventListener('submit',event=>{event.preventDefault();load(listingUrl());});
  filters.addEventListener('change',event=>{if(event.target.tagName==='SELECT')load(listingUrl());});
  document.addEventListener('click',event=>{const link=event.target.closest('#product-results .pagination a');if(!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button!==0)return;event.preventDefault();load(new URL(link.href),'push');});
  window.addEventListener('popstate',()=>{const url=new URL(location.href);if(url.searchParams.get('route')!=='/products')return;for(const input of filters.querySelectorAll('[name]')){if(input.name==='route')continue;input.value=url.searchParams.get(input.name)??(input.tagName==='SELECT'?input.options[0].value:'');}parentFilter();load(url,'none');});
 }
})();
