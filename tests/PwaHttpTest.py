import os,json,re,ast,struct,http.cookiejar,urllib.request,urllib.parse,urllib.error
from pathlib import Path
root=Path(__file__).resolve().parents[1];base='http://127.0.0.1:8104/pwa-shop/';login=json.loads(os.environ['PWA_HTTP_LOGIN']);checks=0

def check(v,m):
 global checks
 if not v:raise RuntimeError(m)
 checks+=1

def request(path):
 try:r=urllib.request.urlopen(base+path,timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read(),r.headers

status,b,h=request('manifest.webmanifest');manifest=json.loads(b);check(status==200 and 'application/manifest+json' in h.get('Content-Type',''),'Manifest MIME valid');check(manifest['scope']=='./' and manifest['id']=='./' and manifest['start_url'].startswith('./index.php?route=') and manifest['display']=='standalone','Manifest app identity/scope/start route are relative and standalone');check(all(manifest.get(k) for k in ['name','short_name','theme_color','background_color']),'Required manifest metadata')
for icon in manifest['icons']:
 status,b,h=request(icon['src']);w,hg=struct.unpack('>II',b[16:24]);check(status==200 and b[:8]==b'\x89PNG\r\n\x1a\n' and f'{w}x{hg}'==icon['sizes'],'Icon dimensions '+icon['src'])
check(any(i['purpose']=='maskable' and i['sizes']=='512x512' for i in manifest['icons']),'Maskable icon available')
status,b,h=request('service-worker.js');check(status==200 and 'javascript' in h.get('Content-Type','') and 'no-cache' in h.get('Cache-Control',''),'Worker MIME/update headers')
source=(root/'public/service-worker.js').read_text();assets=ast.literal_eval(re.search(r'const STATIC_ASSETS = (\[[\s\S]*?\]);',source).group(1));check(len(assets)==len(set(assets)) and all('?' not in a and '.php' not in a and not a.startswith('/') for a in assets),'Cache allowlist has only relative public files')
for asset in assets:
 status,b,h=request(asset);check(status==200 and (root/'public'/asset).is_file(),'Precache asset exists '+asset)
expected={str(p.relative_to(root/'public')) for p in (root/'public/assets').rglob('*') if p.suffix in ['.css','.js','.png','.svg','.woff','.woff2','.ttf']};check(expected.issubset(set(assets)),'All current static CSS/JS/icons/fonts covered')
for route in ['/login','/dashboard']:
 status,b,h=request('index.php?route='+urllib.parse.quote(route,safe=''));cache=h.get('Cache-Control','').lower();check(status==200 and all(token in cache for token in ['no-store','no-cache','must-revalidate']) and h.get('Pragma','').lower()=='no-cache' and h.get('Expires')=='0' and b'manifest.webmanifest' in b,'Dynamic route has explicit private cache headers and manifest '+route)
 if route=='/login':check(re.search(rb'assets/css/app\.css\?v=[a-f0-9]{16}',b) and re.search(rb'assets/js/app\.js\?v=[a-f0-9]{16}',b),'Login view uses content-versioned CSS/JS')
status,b,h=request('offline.html');check(status==200 and b'No shop data is available offline' in b and b'PRIVATE' not in b,'Offline page is public generic shell')
# The same URL shapes are also served by the existing local Apache installation.
try:
 r=urllib.request.urlopen('http://127.0.0.1/spare_pos/public/manifest.webmanifest',timeout=10);check('application/manifest+json' in r.headers.get('Content-Type',''),'Apache manifest MIME configured')
 r=urllib.request.urlopen('http://127.0.0.1/spare_pos/public/service-worker.js',timeout=10);check('no-cache' in r.headers.get('Cache-Control','') and 'javascript' in r.headers.get('Content-Type',''),'Apache service worker MIME/update headers')
except urllib.error.URLError:print('NOTE: Apache check unavailable; isolated PHP server checks completed.')
print(f'PASS: {checks} PWA manifest/icon/static/HTTP checks.')
