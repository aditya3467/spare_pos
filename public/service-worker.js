/* Bump VERSION whenever any file in STATIC_ASSETS changes. Never add PHP or API URLs. */
'use strict';
const VERSION = '2026.11.21.1';
const BASE = new URL('./', self.location.href);
const CACHE_PREFIX = `spare-shell:${encodeURIComponent(BASE.pathname)}:`;
const CACHE_NAME = CACHE_PREFIX + VERSION;
const STATIC_ASSETS = [
 'offline.html', 'manifest.webmanifest',
 'assets/css/app.css', 'assets/css/workspace.css', 'assets/css/sales.css',
 'assets/css/reports.css', 'assets/css/staff.css', 'assets/css/pwa.css',
 'assets/js/app.js', 'assets/js/workspace.js', 'assets/js/products.js',
 'assets/js/inventory.js', 'assets/js/pos.js', 'assets/js/categories.js',
 'assets/js/category-actions.js', 'assets/js/reports.js', 'assets/js/staff.js',
 'assets/js/invoice-print.js', 'assets/js/pwa.js',
 'assets/icons/app-icon.svg', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png',
 'assets/icons/maskable-192.png', 'assets/icons/maskable-512.png',
 'assets/icons/apple-touch-icon.png', 'assets/icons/favicon-32.png'
];
const ALLOWED = new Set(STATIC_ASSETS.map(path => new URL(path, BASE).href));
const OFFLINE_URL = new URL('offline.html', BASE).href;
function allowedStatic(url) {
 const canonical = new URL(url.href);
 canonical.search = '';
 canonical.hash = '';
 return ALLOWED.has(canonical.href) ? canonical.href : null;
}
function validStatic(url, response) {
 const finalUrl = new URL(response.url);
 finalUrl.search = '';
 finalUrl.hash = '';
 if (!response.ok || response.redirected || response.type === 'opaque' || finalUrl.href !== url) return false;
 if (/\b(private|no-store)\b/i.test(response.headers.get('Cache-Control') || '')) return false;
 const mime = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
 const path = new URL(url).pathname;
 if (path.endsWith('.css')) return mime === 'text/css';
 if (path.endsWith('.js')) return ['text/javascript', 'application/javascript'].includes(mime);
 if (path.endsWith('.woff2')) return mime === 'font/woff2';
 if (path.endsWith('.woff')) return ['font/woff','application/font-woff'].includes(mime);
 if (path.endsWith('.ttf')) return ['font/ttf','application/x-font-ttf'].includes(mime);
 if (path.endsWith('.png')) return mime === 'image/png';
 if (path.endsWith('.svg')) return mime === 'image/svg+xml';
 if (path.endsWith('.webmanifest')) return ['application/manifest+json', 'application/json', 'application/octet-stream'].includes(mime);
 return url === OFFLINE_URL && mime === 'text/html';
}
self.addEventListener('install', event => {
 event.waitUntil((async () => {
  // A same-version deploy never overwrites the cache used by an active worker.
  // Version bumps are required for changed assets; failed new versions leave old caches intact.
  if ((await caches.keys()).includes(CACHE_NAME)) {
   const existing = await caches.open(CACHE_NAME);
   if ((await Promise.all([...ALLOWED].map(url => existing.match(url)))).every(Boolean)) return;
   throw new Error('Incomplete existing shell cache; deploy a new version.');
  }
  const cache = await caches.open(CACHE_NAME);
  try {
   await Promise.all([...ALLOWED].map(async url => {
    const response = await fetch(url, {credentials:'omit', cache:'reload', redirect:'error'});
    if (!validStatic(url, response)) throw new Error('Unsafe or unavailable static asset.');
    await cache.put(url, response);
   }));
  } catch (error) { await caches.delete(CACHE_NAME); throw error; }
 })());
});
self.addEventListener('activate', event => {
 event.waitUntil((async () => {
  const keys = await caches.keys();
  await Promise.all(keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME).map(key => caches.delete(key)));
  await self.clients.claim();
 })());
});
self.addEventListener('message', event => {
 if (event.data?.type !== 'ACTIVATE_UPDATE' || !event.source?.url) return;
 const source = new URL(event.source.url);
 if (source.origin === BASE.origin && source.pathname.startsWith(BASE.pathname)) event.waitUntil(self.skipWaiting());
});
self.addEventListener('fetch', event => {
 const request = event.request;
 const url = new URL(request.url);
 // No POST interception, retries, background sync, transaction queue or cross-origin cache.
 if (request.method !== 'GET' || url.origin !== BASE.origin || !url.pathname.startsWith(BASE.pathname)) return;
 const staticUrl = allowedStatic(url);
 if (staticUrl) {
  event.respondWith((async () => {
   const cache = await caches.open(CACHE_NAME);
   try {
    // Revalidate allowlisted public assets online. PHP adds content hashes to
    // view URLs; canonical cache keys preserve the last good copy for offline use.
    const response = await fetch(request, {cache:'no-store'});
    if (validStatic(staticUrl, response)) {
     await cache.put(staticUrl, response.clone());
     return response;
    }
    return (await cache.match(staticUrl)) || response;
   } catch (error) {
    const cached = await cache.match(staticUrl);
    if (cached) return cached;
    throw error;
   }
  })());
  return;
 }
 // Every route, API, export, upload and query-string asset stays network-only.
 event.respondWith((async () => {
  try { return await fetch(request, {cache:'no-store'}); }
  catch (error) {
   if (request.mode !== 'navigate') throw error;
   const offline = await (await caches.open(CACHE_NAME)).match(OFFLINE_URL);
   if (offline) {
    // Resolve safe public shell assets against this install's scope, even for deep URLs.
    const html = (await offline.text()).replace(/\b(href|src)="([^"]+)"/g, (match, attribute, value) => {
     return /^(assets\/|manifest\.webmanifest$|index\.php\?)/.test(value) ? `${attribute}="${new URL(value, BASE).href}"` : match;
    });
    return new Response(html, {headers:{'Content-Type':'text/html; charset=UTF-8','Cache-Control':'no-store'}});
   }
   return new Response('Connection unavailable. Reconnect and reload. No transaction was queued.', {
    status:503, headers:{'Content-Type':'text/plain; charset=UTF-8', 'Cache-Control':'no-store'}
   });
  }
 })());
});
