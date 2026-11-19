# PWA foundation

Spare is installable on supported browsers while retaining its existing Core PHP routes, sessions and permissions. The PWA stores only public app files; **shop operations require a connection**.

## Files

- `public/manifest.webmanifest`: app identity, name, standalone mode, theme/background colours, relative scope/start URL and normal/maskable icons.
- `public/service-worker.js`: versioned static-only allowlist, safe offline navigation response and scoped cache cleanup.
- `public/offline.html`: generic, non-personal offline shell with connection state and a retry action.
- `public/assets/js/pwa.js`, `public/assets/css/pwa.css`: registration, online/offline indicator, install prompt/help and user-controlled update notice.
- `public/assets/icons/`: PNGs at 192/512, maskable 192/512, Apple touch icon 180, favicon 32 and vector source, using the existing green `s.` brand.
- `resources/views/components/pwa-{head,controls}.php`: shared metadata/assets and small status/install controls.
- `resources/views/layouts/app.php`, `resources/views/auth/login.php`: integration in the authenticated workspace and login. Printable invoices keep their existing print layout.
- `app/Core/Auth.php`: explicit same-origin `worker-src`, `manifest-src`, `connect-src` and `font-src` CSP directives. Authentication, no-store headers and permission logic are unchanged.
- `public/.htaccess`: manifest/worker MIME and update-header configuration for Apache.
- `scripts/generate-pwa-icons.php`: optional development-time PNG generator. Generated files ship with the app; production needs no GD/font runtime dependency. The font path can be adjusted on other development systems.
- `tests/Pwa{Test.php,HttpTest.py,BrowserTest.mjs,Router.php}`: isolated browser/server fixtures, installability, offline/privacy and update checks. The test router is outside `public/` and must not be deployed as an application router.

No database migration or new business-data store is required.

## Hosting

1. Prefer the web server document root pointing to `public/`. Existing XAMPP access at `/spare_pos/public/` also works. The project root's existing convenience redirect is unchanged.
2. Use **HTTPS** in deployment. Browsers also support service workers on `localhost` / `127.0.0.1` for development. A plain-HTTP LAN IP/custom hostname normally will not provide an installable worker. The normal online app remains usable when PWA APIs are unavailable.
3. Serve `service-worker.js` as JavaScript and `manifest.webmanifest` as `application/manifest+json`. Their HTTP headers should be `Cache-Control: no-cache, max-age=0, must-revalidate` so browser update checks see releases. Apache `mod_mime` / `mod_headers` rules are included. On Nginx/other hosts, configure the equivalent MIME/cache rules. Do not rewrite these files to PHP or an authentication page.
4. Preserve the existing PHP `Cache-Control: no-store` and session-cookie security. If TLS terminates at a trusted reverse proxy, configure the server/PHP HTTPS indication correctly so existing cookies are Secure; no client-supplied forwarding header is newly trusted.
5. The worker lives in the same public directory as the app, so no broader `Service-Worker-Allowed` header is needed. Relative manifest URLs and worker registration work at the domain root or under a subdirectory. Cache names include the encoded scope path; cleanup cannot delete another app's differently scoped caches.
6. Keep all shell files deployed together before browsers fetch a new worker. Installation fails safely on a missing file, redirect, private/no-store response or wrong content type, leaving the previous working shell intact.

## Installation and launch

On browsers exposing `beforeinstallprompt`, **Install app** appears only once the browser offers installation. Clicking invokes that deferred browser prompt. Dismissing does not repeatedly force a dialog. Installed/standalone windows hide installation controls.

**Install help** provides a fallback: browser install menu, Safari on iPhone/iPad Share → Add to Home Screen, or Safari on Mac File → Add to Dock when supported. Installation remains controlled by the browser/OS. No fake installation or forced banner is shown.

The start URL is `index.php?route=%2Fdashboard`. Online launch runs existing authentication: a valid session opens the dashboard; a signed-out/expired/disabled account goes to login. The manifest contains no shop name, user identity, credentials or business data.

## What is cached

The exact allowlist contains the generic offline shell, manifest, existing CSS/JavaScript and app icons. There are no bundled/downloaded webfont files today; the app uses system fonts available offline. The worker supports known font MIME types if explicit same-origin font paths are added later.

Only allowlisted, query-free same-origin static URLs can enter the cache. Install fetches omit credentials, reject redirects, validate content types and refuse private/no-store responses. There is no broad runtime URL-pattern caching. Service-worker scripts themselves are fetched separately by the browser for updates, not cached as shell assets.

The worker **does not cache**:

- PHP-rendered login, dashboard, POS, invoices, reports, staff, product or inventory pages;
- JSON/AJAX responses, customer/financial records, permissions or session/CSRF data;
- CSV/Excel/PDF downloads, uploaded files or arbitrary query-string resources;
- cross-origin requests or POST/other mutations.

Non-allowlisted GETs are network-only with `cache: no-store`. HTTP errors remain server errors; a transport failure during a navigation displays only the generic offline shell. API requests fail normally rather than receiving fake HTML/JSON data. A query-string asset is not silently converted into an allowlisted cache key. If adding/referencing static assets differently, update the exact list and version explicitly.

Existing in-memory pages can remain visible when the network drops; that is not an offline copy saved by this PWA. The pre-existing same-tab POS draft in session storage remains unchanged; it is not an offline transaction and is never synced by the worker.

## Offline and reconnection

The visible Online/Offline pill follows browser network status. It is a network indication, not a promise that the server is reachable. The fallback distinguishes no network from a network that could not reach the server.

Offline launch/navigation cannot retrieve shop records. **Try again** reloads the original GET page after reconnection, or uses the dashboard start URL when opening `offline.html` directly. Even deep URLs resolve the public shell's assets correctly against the app scope. Fallback navigation responses have `Cache-Control: no-store` and are not persisted under private page URLs.

POSTs are not intercepted, queued, retried or replayed. There is no background sync, offline sale, stock modification, credit operation or synchronization queue. Offline logout cannot contact/destroy the server session; reconnect to complete normal logout. The PWA does not pretend that an unsuccessful transaction/logout was saved.

## Updates

Bump `VERSION` in `public/service-worker.js` for every release changing any cached file. Deploy assets first, then the new worker. `updateViaCache: 'none'`, worker HTTP headers, page-load checks and reconnection checks discover new releases.

A new version builds its own complete cache before becoming installed. A same-version worker never rewrites the active version's cache; forgetting the version bump will leave old cached assets, so the bump is a required release step. Failed installs remove only their incomplete version, never the previous working cache.

Updates wait. The app shows **Refresh app / Later** and asks the user to finish/save work before refreshing. Only a confirmed click requests activation and reloads that window. Other windows are not force-reloaded and can refresh when ready. New activation removes only older caches in this app's namespace. It does not clear cookies, sessions, permissions or unrelated apps' caches.

## Verification and remaining device checks

```sh
RUN_PWA_TESTS=1 /Applications/XAMPP/xamppfiles/bin/php tests/PwaTest.php
```

The automated suite creates/drops a disposable database, uses a fresh Chrome profile and a nested `/pwa-shop/` path, and temporarily stops/restarts only its own PHP server to simulate a genuine outage. Page-only DevTools offline emulation does not stop independent service-worker fetches, so the real outage is part of the test.

Completed checks:

- 42 manifest/icon/static/MIME/HTTP checks, including Apache headers;
- 38 browser checks for Chrome installability, deferred install UI, start URL/authentication, installed-mode UI branch, public-only Cache Storage contents, offline/deep-URL fallback, reconnection, unchanged sales/stock, no background sync, logout, immediate permission grants/revocations and session invalidation;
- update waiting, cancelled refresh preserving input, explicit activation, old-cache cleanup preserving other scopes, and failed/unsafe update retaining the prior shell;
- login/connection/install controls at 360/390/430px;
- existing login/logout/session regressions (64 checks), staff HTTP/session/service checks and the authentication unit suite.

Chrome's automation interface in this environment does not implement `PWA.install`, and its media override does not emulate installed display mode. The manifest has no Chrome installability errors and the install handler is exercised; the installed-mode UI branch is explicitly simulated in the test. **Native OS installation/standalone launch, iOS/Safari Add to Home Screen, HTTPS production deployment and physical-device behaviour still need a manual device check.** No claim of an automated native OS install is made.

For manual acceptance: open the HTTPS site in a supported browser, install, launch the icon, verify login/session routing, switch offline and reopen, reconnect/retry, and deploy a bumped version while a form is open to confirm the explicit update flow. Check DevTools → Application → Cache Storage: only the allowlisted public files should appear.

Primary references: [MDN service-worker guide](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API/Using_Service_Workers), [MDN registration/update cache options](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register), [MDN worker activation](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerGlobalScope/skipWaiting).
