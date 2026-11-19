(() => {
 'use strict';
 const script = document.currentScript;
 const base = new URL('../../', script.src);
 const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
 const offlinePage = document.body.hasAttribute('data-pwa-offline');
 let installEvent = null, registration = null, reloadRequested = false, reloading = false;
 function connection() {
  const online = navigator.onLine;
  document.querySelectorAll('[data-pwa-connection]').forEach(node => {
   node.classList.toggle('is-offline', !online);
   node.querySelector('[data-pwa-connection-text]').textContent = online ? 'Online' : 'Offline';
   node.title = online ? 'Network available. Shop operations require the server.' : 'No network connection. Changes cannot be posted or queued.';
  });
  if (offlinePage) {
   document.querySelector('[data-pwa-offline-message]').textContent = online
    ? 'Your network is available. The shop server could not be reached. Try again to load the latest page.'
    : 'You’re offline. Reconnect, then try again to return to the latest page.';
  }
 }
 connection();window.addEventListener('online', connection);window.addEventListener('offline', connection);
 document.querySelector('[data-pwa-retry]')?.addEventListener('click', () => {
  if (location.pathname === new URL('offline.html',base).pathname) location.assign(new URL('index.php?route=%2Fdashboard',base));
  else location.reload();
 });
 function installControls() {
  document.querySelectorAll('[data-pwa-install]').forEach(b => { b.hidden = standalone() || !installEvent; });
  document.querySelectorAll('[data-pwa-install-help]').forEach(b => { b.hidden = standalone() || !!installEvent; });
 }
 window.addEventListener('beforeinstallprompt', event => { event.preventDefault();installEvent = event;installControls(); });
 window.addEventListener('appinstalled', () => { installEvent = null;document.querySelectorAll('[data-pwa-install],[data-pwa-install-help]').forEach(b => {b.hidden=true;}); });
 window.matchMedia('(display-mode: standalone)').addEventListener('change', installControls);
 document.querySelectorAll('[data-pwa-install]').forEach(button => button.addEventListener('click', async () => {
  const prompt = installEvent;if (!prompt) return;
  installEvent = null;installControls();
  try { await prompt.prompt();await prompt.userChoice; } catch (_) { /* Native prompt may no longer be available. */ }
  installControls();
 }));
 document.querySelectorAll('[data-pwa-install-help]').forEach(button => button.addEventListener('click', () => {
  let dialog = document.getElementById('pwa-install-help');
  if (!dialog) {
   dialog = document.createElement('dialog');dialog.id='pwa-install-help';dialog.className='pwa-install-dialog';dialog.setAttribute('aria-labelledby','pwa-install-title');
   const title=document.createElement('h2');title.id='pwa-install-title';title.textContent='Install Spare';
   const text=document.createElement('p');
   text.textContent = !window.isSecureContext ? 'Installation needs HTTPS. For local testing, use localhost or 127.0.0.1.'
    : (/iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1)) ? 'In Safari, open the Share menu, choose Add to Home Screen, then Add. If offered, enable Open as Web App.'
    : 'Use your browser’s install option in the address bar or main menu. In Safari on Mac, use File → Add to Dock when available. If installation is not offered, you can keep using Spare in this browser.';
   const close=document.createElement('button');close.type='button';close.className='pwa-action';close.textContent='Close';close.addEventListener('click',()=>dialog.close());dialog.append(title,text,close);document.body.append(dialog);
  }
  dialog.showModal();
 }));
 installControls();
 if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
 function updateNotice() {
  if (!registration?.waiting) return;
  let notice=document.getElementById('pwa-update');
  if (!notice) {
   notice=document.createElement('aside');notice.id='pwa-update';notice.className='pwa-update';notice.setAttribute('role','status');
   const text=document.createElement('p');text.textContent='An app update is ready.';
   const refresh=document.createElement('button');refresh.type='button';refresh.className='pwa-action';refresh.textContent='Refresh app';refresh.dataset.pwaUpdate='';
   refresh.addEventListener('click',()=>{
    if (!window.confirm('Finish or save any work in this and other Spare windows before refreshing. Refresh this window now?')) return;
    if (!registration.waiting) {location.reload();return;}
    reloadRequested=true;refresh.disabled=true;registration.waiting.postMessage({type:'ACTIVATE_UPDATE'});
   });
   const later=document.createElement('button');later.type='button';later.className='pwa-action';later.textContent='Later';later.addEventListener('click',()=>{notice.hidden=true;});notice.append(text,refresh,later);document.body.append(notice);
  }
  notice.hidden=false;
 }
 navigator.serviceWorker.addEventListener('controllerchange',()=>{
  if (reloadRequested && !reloading) {reloading=true;location.reload();return;}
  const notice=document.getElementById('pwa-update');
  if(notice){notice.querySelector('p').textContent='An app update is active. Refresh when ready.';notice.hidden=false;}
 });
 window.addEventListener('load', async () => {
  try {
   registration=await navigator.serviceWorker.register(new URL('service-worker.js',base),{scope:base.pathname,updateViaCache:'none'});
   updateNotice();
   const track=worker=>worker?.addEventListener('statechange',()=>{if(worker.state==='installed' && navigator.serviceWorker.controller)setTimeout(updateNotice,0);});
   track(registration.installing);registration.addEventListener('updatefound',()=>track(registration.installing));
   if (navigator.onLine) registration.update().catch(()=>{});
  } catch (_) { /* Online application remains usable if installation/cache storage is unavailable. */ }
 });
 window.addEventListener('online',()=>{registration?.update().catch(()=>{});});
})();
