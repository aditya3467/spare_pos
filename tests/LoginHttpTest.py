import os, json, urllib.request, urllib.parse, urllib.error, http.cookiejar, re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
ACCOUNTS = json.loads((ROOT/'storage/local-accounts.json').read_text())
BASE = os.environ.get('SPARE_TEST_URL', 'http://127.0.0.1/spare_pos/public/index.php') + '?route='
checks = 0

def expect(value, message):
    global checks
    if not value: raise AssertionError(message)
    checks += 1

def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)), jar

def request(opener, route, data=None):
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        response = opener.open(BASE+urllib.parse.quote(route,safe=''), body, timeout=10)
    except urllib.error.HTTPError as response:
        return response.code, response.read().decode(), response.headers
    return response.code, response.read().decode(), response.headers

def token(html):
    found = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    expect(bool(found), 'CSRF token must render')
    return found.group(1)

def login(role):
    opener, jar = client()
    status, html, headers = request(opener, '/login')
    expect(status == 200, 'Login GET returns 200')
    before = next(cookie.value for cookie in jar if cookie.name == 'spare_session')
    account = ACCOUNTS[role]
    status, html, headers = request(opener, '/login', {'csrf':token(html),'email':account['email'],'password':account['password']})
    expect(status == 200 and 'Hello, ' in html, role+' must reach dashboard')
    after = next(cookie.value for cookie in jar if cookie.name == 'spare_session')
    expect(before != after, 'Session ID must rotate after login')
    expect('no-store' in headers.get('Cache-Control',''), 'Authenticated response must prevent caching')
    return opener, html

# Repeated successful logins must not consume failed-login quota.
for i in range(7):
    opener, html = login('shop_owner')
    status, html, _ = request(opener, '/logout', {'csrf':token(html)})
    expect(status == 200 and 'Welcome back.' in html, 'POST logout returns login')
print(f'PASS: repeated login, session rotation and logout ({checks} checks)')

for role in ['superadmin', 'staff']:
    opener, html = login(role)
    status, _, _ = request(opener, '/logout', {'csrf': token(html)})
    expect(status == 200, role + ' logout must succeed')
anonymous, _ = client()
expect(request(anonymous, '/login', {'email': 'owner@spare.test', 'password': 'bad'})[0] == 403, 'Missing CSRF must return HTTP 403')
print(f'PASS: {checks} live HTTP login checks across all three roles.')
