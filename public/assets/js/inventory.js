(() => {
 const filters=document.getElementById('inventory-list-filters');
 if(filters){
  const bikes=JSON.parse(document.getElementById('inventory-bike-data').textContent);
  const makerInput=filters.querySelector('[data-bike-manufacturer-search]'),modelInput=filters.querySelector('[data-bike-model-search]');
  const status=document.getElementById('inventory-list-status');let timer=null,controller=null,sequence=0;
  const normalize=value=>value.trim().replace(/\s+/g,' ').toLocaleLowerCase();
  function catalogue(changed){
   const maker=bikes.vehicle_manufacturers.find(row=>normalize(row.name)===normalize(makerInput.value));
   filters.elements.manufacturer_id.value=maker?.id||'';
   let models=bikes.vehicle_models.filter(row=>!maker || String(row.manufacturer_id)===String(maker.id));
   let matches=models.filter(row=>normalize(row.name)===normalize(modelInput.value));
   if(changed===makerInput && modelInput.value && !matches.length){modelInput.value='';filters.elements.model_id.value='';filters.elements.variant_id.value='';}
   if(matches.length===1){filters.elements.model_id.value=matches[0].id;if(!maker && !makerInput.value){const parent=bikes.vehicle_manufacturers.find(row=>String(row.id)===String(matches[0].manufacturer_id));makerInput.value=parent.name;filters.elements.manufacturer_id.value=parent.id;models=bikes.vehicle_models.filter(row=>String(row.manufacturer_id)===String(parent.id));}}
   else filters.elements.model_id.value='';
   const list=document.getElementById('inventory-models');list.replaceChildren();models.forEach(row=>list.append(new Option(row.name,row.name)));
   for(const option of filters.elements.variant_id.options){if(!option.value)continue;option.hidden=option.dataset.model!==filters.elements.model_id.value;option.disabled=option.hidden;}
   if(filters.elements.variant_id.selectedOptions[0]?.disabled)filters.elements.variant_id.value='';
   makerInput.setCustomValidity(makerInput.value && !filters.elements.manufacturer_id.value?'Choose a manufacturer from the catalogue suggestions.':'');
   modelInput.setCustomValidity(modelInput.value && !filters.elements.model_id.value?'Choose an unambiguous model from the suggestions, selecting manufacturer first if needed.':'');
  }
  function invalidate(){clearTimeout(timer);sequence++;controller?.abort();}
  function url(page=1){const u=new URL('index.php',location.href);u.search=new URLSearchParams(new FormData(filters));u.searchParams.set('page',page);return u;}
  async function load(target,historyMode='replace'){
   invalidate();const current=sequence;controller=new AbortController();status.textContent='Searching inventory…';status.classList.add('is-loading');document.getElementById('inventory-results').setAttribute('aria-busy','true');
   try{const response=await fetch(target,{headers:{Accept:'application/json'},signal:controller.signal});if(!response.ok)throw new Error('Inventory search unavailable. Please try again.');if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Session expired. Reload to sign in.');const result=await response.json();if(current!==sequence)return;const template=document.createElement('template');template.innerHTML=result.html;if(!template.content.querySelector('#inventory-results'))throw new Error('Could not display results.');document.getElementById('inventory-results').replaceWith(template.content);target.searchParams.set('page',result.page);if(historyMode==='push')history.pushState(null,'',target);else if(historyMode==='replace')history.replaceState(null,'',target);status.textContent=result.total?`${result.total} products found.`:'No matching inventory.';
   }catch(e){if(current===sequence && e.name!=='AbortError')status.textContent=e.message||'Connection interrupted. Retry search.';}
   finally{if(current===sequence){status.classList.remove('is-loading');document.getElementById('inventory-results').removeAttribute('aria-busy');}}
  }
  function changed(event){invalidate();catalogue(event.target);if(!filters.checkValidity()){status.textContent='Choose a bike from catalogue suggestions or clear the bike filter.';document.getElementById('inventory-results').removeAttribute('aria-busy');return;}const text=event.type==='input';if(text && event.target.value.trim()!=='')timer=setTimeout(()=>load(url()),300);else load(url());}
  filters.addEventListener('input',changed);filters.addEventListener('change',event=>{if(event.target.tagName==='SELECT')changed(event);});
  filters.addEventListener('submit',event=>{event.preventDefault();catalogue();if(filters.checkValidity())load(url());});
  filters.querySelector('[data-clear-inventory-bike]').addEventListener('click',()=>{makerInput.value='';modelInput.value='';for(const name of ['manufacturer_id','model_id','variant_id','year'])filters.elements[name].value='';catalogue();load(url());});
  document.addEventListener('click',event=>{const link=event.target.closest('#inventory-results .pagination a');if(!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button!==0)return;event.preventDefault();load(new URL(link.href),'push');});
  window.addEventListener('popstate',()=>{const u=new URL(location.href);if(!['/inventory','/inventory/stock'].includes(u.searchParams.get('route')))return;for(const input of filters.querySelectorAll('[name]'))if(input.name!=='route')input.value=u.searchParams.get(input.name)??(input.tagName==='SELECT'?input.options[0].value:'');makerInput.value=bikes.vehicle_manufacturers.find(r=>String(r.id)===filters.elements.manufacturer_id.value)?.name||'';modelInput.value=bikes.vehicle_models.find(r=>String(r.id)===filters.elements.model_id.value)?.name||'';catalogue();load(u,'none');});
  catalogue();
 }
 const form=document.getElementById('inventory-operation');if(!form)return;
 const search=document.getElementById('inventory-product-search'),results=document.getElementById('inventory-search-results'),status=document.getElementById('inventory-search-status'),popup=document.getElementById('inventory-confirm-dialog');
 let timer=null,request=null,version=0,posting=false;
 const difference=()=>{if(form.elements.kind.value!=='adjust')return;const count=form.elements.physical_quantity.value,current=form.elements.expected_quantity.value;document.getElementById('inventory-difference').textContent=count===''?'Enter physical stock to calculate the adjustment.':`Current stock: ${Number(current)} · Physical count: ${Number(count)} · Adjustment: ${Number(count)>Number(current)?'Increase +':Number(count)<Number(current)?'Decrease ':''}${Number((Number(count)-Number(current)).toFixed(6))} · Resulting stock: ${Number(count)}`;};
 form.elements.physical_quantity?.addEventListener('input',difference);difference();
 search.addEventListener('input',()=>{
  clearTimeout(timer);request?.abort();const current=++version;results.replaceChildren();
  if(search.value.trim().length<2){status.textContent='Type at least 2 characters.';return;}
  timer=setTimeout(async()=>{request=new AbortController();status.textContent='Searching products…';const url=new URL('index.php',location.href);url.searchParams.set('route','/inventory/search');url.searchParams.set('kind',form.elements.kind.value);url.searchParams.set('q',search.value.trim());
   try{const response=await fetch(url,{signal:request.signal,headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Product search unavailable.');if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Your session may have expired. Reload to sign in.');const data=await response.json();if(current!==version)return;results.replaceChildren();status.textContent=data.products.length?'Select an exact SKU.':'No matching products.';
    data.products.forEach(product=>{const button=document.createElement('button');button.type='button';button.dataset.productId=product.id;button.className='inventory-product-option';button.textContent=`${product.name} · ${product.sku} · ${product.part_number || 'No OEM code'} · Stock: ${product.quantity===null?'Not tracked':Number(product.quantity)} · ${product.brand_name || 'No brand'} · ${product.bikes || 'No recorded bikes'} · ${product.match_info || ''}`;button.addEventListener('click',()=>{form.elements.product_id.value=product.id;form.elements.expected_quantity.value=product.quantity??'0.000000';document.getElementById('inventory-current').value=product.quantity===null?'Not tracked':Number(product.quantity);document.getElementById('inventory-selected').textContent=`${product.name} · ${product.sku} · ${product.category_name}${product.subcategory_name?' / '+product.subcategory_name:''}`;results.replaceChildren();search.value='';status.textContent='Product selected.';difference();});results.append(button);});
   }catch(e){if(current===version && e.name!=='AbortError')status.textContent=e.message || 'Connection interrupted. Retry search.';}
  },300);
 });
 form.addEventListener('submit',event=>{
  if(posting)return;event.preventDefault();const error=document.getElementById('inventory-operation-error');error.textContent='';
  if(!Number(form.elements.product_id.value)){error.textContent='Choose a product from the search results.';return;}
  const kind=form.elements.kind.value,current=Number(form.elements.expected_quantity.value);let change;
  if(kind==='adjust')change=Number(form.elements.physical_quantity.value)-current;
  else if(kind==='reverse')change=null;else change=Number(form.elements.quantity.value)*(kind==='out'?-1:1);
  if(kind==='out' && current+change<0){error.textContent='Requested quantity exceeds the displayed available stock. Reload the product if stock has changed.';return;}
  document.getElementById('inventory-confirm-summary').textContent=`${document.getElementById('inventory-selected').textContent}. ${kind==='adjust'?`Current stock: ${current}. Physical count: ${form.elements.physical_quantity.value}. Adjustment ${change>0?'Increase':change<0?'Decrease':'No change'}: ${change>=0?'+':''}${Number(change.toFixed(6))}. Resulting stock: ${Number(form.elements.physical_quantity.value)}.`:change===null?'Reverse the selected original movement.':`Change: ${change>=0?'+':''}${Number(change.toFixed(6))}. Expected resulting stock: ${Number((current+change).toFixed(6))}.`} Reason: ${form.elements.reason.value}. Entry date: ${form.elements.entry_date.value}.`;
  form.elements.confirmed.value='0';popup.showModal();
 });
 const cancel=()=>{if(posting)return;form.elements.confirmed.value='0';popup.close();form.querySelector('[type=submit]').focus();};
 document.querySelector('[data-inventory-cancel]').addEventListener('click',cancel);
 popup.addEventListener('cancel',event=>{event.preventDefault();cancel();});
 document.querySelector('[data-inventory-confirm]').addEventListener('click',()=>{posting=true;form.elements.confirmed.value='1';document.querySelector('[data-inventory-confirm]').disabled=true;document.querySelector('[data-inventory-cancel]').disabled=true;document.getElementById('inventory-post-status').textContent='Posting stock…';form.requestSubmit();});
})();
