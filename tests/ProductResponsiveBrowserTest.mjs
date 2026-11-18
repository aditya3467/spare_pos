import fs from 'node:fs';
const fixturePath=process.argv[2];if(!fixturePath)throw new Error('Pass the isolated fixture JSON path.');
const account=JSON.parse(fs.readFileSync(fixturePath,'utf8')).accounts.shop_owner;
const targets=await (await fetch('http://127.0.0.1:9227/json/list')).json();
const socket=new WebSocket(targets.find(x=>x.type==='page').webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
let id=0;const pending=new Map();let checks=0;const errors=[];
socket.onmessage=event=>{const message=JSON.parse(event.data);if(message.id){const request=pending.get(message.id);pending.delete(message.id);message.error?request.reject(new Error(JSON.stringify(message.error))):request.resolve(message.result);}else if(message.method==='Page.javascriptDialogOpening'){send('Page.handleJavaScriptDialog',{accept:true});}else if(message.method==='Runtime.exceptionThrown'){errors.push(message.params.exceptionDetails.text);}};
function send(method,params={}){return new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params}));});}
async function evaluate(expression){const result=await send('Runtime.evaluate',{expression:'{'+expression+'\n}',returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;}
function expect(value,message){if(!value)throw new Error(message);checks++;}
async function until(expression){for(let i=0;i<100;i++){if(await evaluate(expression))return;await new Promise(r=>setTimeout(r,70));}throw new Error('Timed out: '+expression);}
async function navigate(route){await send('Page.navigate',{url:'http://127.0.0.1/spare_pos/public/index.php?route='+encodeURIComponent(route)});await until(`document.readyState==='complete' && document.querySelector('.app-shell')!==null`);}
async function screenshot(name){const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync('/private/tmp/'+name,Buffer.from(shot.data,'base64'));}
await send('Page.enable');await send('Runtime.enable');await send('Network.clearBrowserCookies');
await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
await send('Page.navigate',{url:'http://127.0.0.1/spare_pos/public/index.php?route=%2Flogin'});await until(`document.querySelector('#email')!==null`);
await evaluate(`document.querySelector('#email').value=${JSON.stringify(account.email)};document.querySelector('#password').value=${JSON.stringify(account.password)};document.querySelector('.login-card form').requestSubmit()`);await until(`document.querySelector('.metrics-grid')!==null`);await navigate('/products');await until(`document.querySelector('.product-table tbody tr')!==null`);
expect(await evaluate(`getComputedStyle(document.querySelector('.product-table')).display==='table' && getComputedStyle(document.querySelector('.product-table tbody tr')).display==='table-row'`),'Desktop retains the table');
expect(await evaluate(`document.querySelectorAll('.product-table th').length===8 && document.querySelectorAll('.product-table tbody tr').length===25`),'Desktop retains columns and pagination');
expect(await evaluate(`document.documentElement.scrollWidth<=innerWidth && document.querySelector('.products-table-wrap').getBoundingClientRect().width>=document.querySelector('#product-results').getBoundingClientRect().width-2`),'Desktop table fills available width without page overflow');
for(const width of [360,390,430]){
 await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true});await new Promise(r=>setTimeout(r,100));
 expect(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),'No horizontal page scrolling at '+width);
 expect(await evaluate(`getComputedStyle(document.querySelector('.product-table tbody tr')).display==='grid'`),'Product cards at '+width);
 expect(await evaluate(`(()=>{const f=document.querySelector('.product-filters');const r=name=>f.elements[name].getBoundingClientRect();return r('q').width>=f.getBoundingClientRect().width-2 && [['category_id','subcategory_id'],['brand_id','model_id'],['stock','status'],['sort','direction']].every(([a,b])=>Math.abs(r(a).y-r(b).y)<2 && r(a).right<r(b).left);})()`),'Search spans full row and paired filters share rows at '+width);
 expect(await evaluate(`[...document.querySelectorAll('.product-filters input:not([type=hidden]),.product-filters select,.product-filters>.button')].every(e=>e.getBoundingClientRect().height>=44 && e.getBoundingClientRect().right<=innerWidth)`),'Controls fit and have touch targets at '+width);
 expect(await evaluate(`[...document.querySelectorAll('.product-table tbody tr:first-child .row-actions a,.product-table tbody tr:first-child .row-actions button')].every(e=>e.getBoundingClientRect().height>=44 && e.getBoundingClientRect().right<=innerWidth)`),'All card actions accessible at '+width);
 expect(await evaluate(`document.querySelector('.product-list-heading h1').getBoundingClientRect().width>0 && document.querySelector('.product-list-heading .button-primary').getBoundingClientRect().left<document.querySelector('.product-list-heading .button:not(.button-primary)').getBoundingClientRect().left`),'Title/count remain visible and Add Product is primary at '+width);
 await screenshot('product-cards-'+width+'.png');
}
await evaluate(`window.__responsiveProbe=7;const q=document.querySelector('.product-filters [name=q]');q.value='Needle';q.dispatchEvent(new Event('input',{bubbles:true}))`);
await until(`document.getElementById('product-total').textContent==='1' && !document.getElementById('product-results').hasAttribute('aria-busy')`);
expect(await evaluate(`window.__responsiveProbe===7 && document.querySelectorAll('.product-table tbody tr').length===1 && document.querySelector('.product-table tbody tr').textContent.includes('OEM-NEEDLE-99') && document.querySelector('.product-table tbody tr').textContent.includes('Search Brand')`),'Live search updates mobile card data, retaining OEM and brand');
expect(await evaluate(`document.querySelector('.product-table tbody tr').querySelectorAll('[data-label]').length===8 && document.querySelector('.product-table tbody tr .row-actions').children.length===4`),'Same complete product data and four actions on mobile');
await screenshot('product-card-searched.png');
await evaluate(`const q=document.querySelector('.product-filters [name=q]');q.value='';q.dispatchEvent(new Event('input',{bubbles:true}))`);await until(`document.getElementById('product-total').textContent==='28' && !document.getElementById('product-results').hasAttribute('aria-busy')`);
await evaluate(`document.querySelector('#product-results .pagination a').click()`);await until(`new URL(location.href).searchParams.get('page')==='2'`);
expect(await evaluate(`document.querySelectorAll('.product-table tbody tr').length===3 && getComputedStyle(document.querySelector('.product-table tbody tr')).display==='grid'`),'AJAX pagination preserves mobile cards');
await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
expect(await evaluate(`getComputedStyle(document.querySelector('.product-table tbody tr')).display==='table-row'`),'Returning to desktop restores table without reloading');
expect(errors.length===0,'No browser exceptions');console.log(`PASS: ${checks} desktop/mobile product-list checks.`);await send('Browser.close');socket.close();
