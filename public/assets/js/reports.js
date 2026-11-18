(() => {
 const form=document.getElementById('report-filters');if(!form)return;
 const period=form.elements.period,from=form.elements.from,to=form.elements.to;
 const today=()=>{const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Kolkata',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());const get=t=>parts.find(p=>p.type===t).value;return new Date(`${get('year')}-${get('month')}-${get('day')}T00:00:00Z`);};
 const iso=d=>d.toISOString().slice(0,10);
 period.addEventListener('change',()=>{
  if(period.value==='custom'){from.focus();return;}
  const start=today(),end=today();
  if(period.value==='yesterday'){start.setUTCDate(start.getUTCDate()-1);end.setUTCDate(end.getUTCDate()-1);}
  if(period.value==='week')start.setUTCDate(start.getUTCDate()-((start.getUTCDay()+6)%7));
  if(period.value==='month')start.setUTCDate(1);
  if(period.value==='last_month'){start.setUTCDate(1);start.setUTCMonth(start.getUTCMonth()-1);end.setUTCDate(0);}
  from.value=iso(start);to.value=iso(end);
 });
 [from,to].forEach(input=>input.addEventListener('change',()=>{period.value='custom';}));
 form.addEventListener('submit',e=>{to.setCustomValidity(from.value>to.value?'End date must not be before start date.':'');if(!form.reportValidity())e.preventDefault();});
 to.addEventListener('input',()=>to.setCustomValidity(''));
})();
