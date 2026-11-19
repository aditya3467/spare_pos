(() => {
 'use strict';
 let trigger=null;
 document.querySelectorAll('[data-staff-open]').forEach(link=>link.addEventListener('click',event=>{
  const kind=link.dataset.staffOpen,dialog=document.getElementById(`staff-${kind}-dialog`);if(!dialog?.showModal)return;
  event.preventDefault();trigger=link;const form=dialog.querySelector('form');form.reset();form.elements.staff_id.value=link.dataset.staffId||'';
  if(form.elements.name)form.elements.name.value=link.dataset.staffName||'';
  if(form.elements.email)form.elements.email.value=link.dataset.staffEmail||'';
  dialog.querySelector('[data-staff-subject]').textContent=link.dataset.staffId?`${link.dataset.staffName} · ${link.dataset.staffEmail}`:'';
  const error=form.querySelector('.staff-form-error');error.textContent='';error.hidden=true;
  if(kind==='status'){const deactivate=link.dataset.staffActive==='1';form.elements.active.value=deactivate?'0':'1';dialog.querySelector('h2').textContent=deactivate?'Deactivate staff':'Activate staff';form.querySelector('[data-staff-status-description]').textContent=deactivate?'This staff member will be signed out and unable to log in. Their records and permissions will be retained.':'This staff member will be able to sign in with their current credentials and saved permissions.';}
  dialog.showModal();
 }));
 document.querySelectorAll('.staff-dialog').forEach(dialog=>{
  dialog.querySelectorAll('[data-staff-close]').forEach(button=>button.addEventListener('click',()=>{if(dialog.getAttribute('aria-busy')!=='true')dialog.close();}));
  dialog.addEventListener('cancel',event=>{if(dialog.getAttribute('aria-busy')==='true')event.preventDefault();});
  dialog.addEventListener('close',()=>{dialog.querySelector('form').reset();trigger?.focus();});
  dialog.querySelector('form').addEventListener('submit',async event=>{
   event.preventDefault();if(dialog.getAttribute('aria-busy')==='true')return;
   const form=event.target,error=form.querySelector('.staff-form-error');error.hidden=true;dialog.setAttribute('aria-busy','true');dialog.querySelectorAll('button').forEach(b=>b.disabled=true);
   try{const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form),headers:{Accept:'application/json'}});if(!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('Your session expired. Reload the page and sign in again.');const data=await response.json();if(!response.ok)throw new Error(data.message||'Could not save this account.');location.assign(data.redirect);}
   catch(e){error.textContent=e.message||'Connection interrupted. Check the staff list before retrying.';error.hidden=false;}
   finally{dialog.removeAttribute('aria-busy');dialog.querySelectorAll('button').forEach(b=>b.disabled=false);}
  });
 });
})();
