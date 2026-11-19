"""Live workspace, AJAX and permission checks using an isolated temporary shop."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SPARE_PHP_BINARY', '/Applications/XAMPP/xamppfiles/bin/php')
BASE = os.environ.get('SPARE_TEST_URL', 'http://127.0.0.1/spare_pos/public/index.php')
fixture = json.loads(subprocess.check_output([PHP, str(ROOT / 'tests/HttpFixture.php'), 'create'], cwd=ROOT, text=True))
checks = 0

def check(value, message):
    global checks
    if not value: raise AssertionError(message)
    checks += 1

def request(opener, route, data=None, ajax=False):
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    req = urllib.request.Request(BASE + '?route=' + urllib.parse.quote(route, safe=''), body, headers={'Accept': 'application/json' if ajax else 'text/html'})
    try: response = opener.open(req, timeout=15)
    except urllib.error.HTTPError as error: response = error
    return response.code, response.read().decode(), response.headers

def token(page): return re.search(r'name="csrf" value="([a-f0-9]+)"', page).group(1)

def login(role):
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    _, page, _ = request(opener, '/login')
    account = fixture['accounts'][role]
    status, page, _ = request(opener, '/login', {'csrf':token(page),'email':account['email'],'password':account['password']})
    check(status == 200 and 'Dashboard' in page, 'Role login and dashboard render')
    return opener, page

try:
    owner, dashboard = login('shop_owner')
    staff, staff_dashboard = login('staff')
    admin, admin_dashboard = login('superadmin')
    check('app-sidebar' in dashboard and 'app-topbar' in dashboard, 'Dashboard must use shared application shell')
    check(dashboard.count('class="metric-card') == 7, 'Dashboard must display seven summary metrics')
    for label in ['Payments not set up','Purchases not set up']:
        check(label in dashboard, 'Unavailable modules must be identified honestly')
    check('Permission required' in staff_dashboard and '₹0.00' not in staff_dashboard, 'Unprivileged staff must not see stock valuation')
    check('No shop selected' in admin_dashboard, 'Unscoped superadmin must not see global shop totals')
    check('href="index.php?route=%2Fproducts"' in dashboard and 'href="index.php?route=%2Fpos"' in dashboard and 'Today’s revenue' in dashboard, 'Implemented products, POS and sales totals must remain accessible')
    _, staff_page, _ = request(owner, '/staff')
    check('Staff &amp; Permissions' in staff_page and 'data-staff-permissions' in staff_page, 'Staff management must have its own functional settings page')
    check(request(staff, '/staff')[0] == 403 and request(admin, '/staff')[0] == 403, 'Staff management must be owner-only')
    data={'csrf':token(staff_page),'staff_id':fixture['accounts']['staff']['id'],'permissions[]':['reports.view']}
    status, result, headers = request(owner, '/staff/permissions', data, True)
    check(status == 200 and json.loads(result)['message'] == 'Staff permissions updated.', 'Existing staff update URL must support AJAX')
    check('application/json' in headers.get('Content-Type',''), 'AJAX must return JSON')
    _, updated, _ = request(staff, '/dashboard')
    check('₹0.00' in updated, 'Permission grant must apply to an existing staff session')
    status, _, _ = request(owner, '/staff/permissions', {'csrf':token(staff_page),'staff_id':fixture['accounts']['staff']['id']})
    check(status == 200, 'Non-JavaScript staff update must still work')
    _, revoked, _ = request(staff, '/dashboard')
    check('₹0.00' not in revoked, 'Revocation must remove protected dashboard values immediately')
    status, result, _ = request(owner, '/categories', ajax=True)
    catalogue = json.loads(result)['html']
    check(status == 200 and 'category-workspace' in catalogue and '<!doctype' not in catalogue, 'Category fragment must render without a second application shell')
    check('category-removal' not in catalogue and 'main-category-dialog' in catalogue, 'Main action panels must be replaced by a shared modal')
    check('Edit main category' not in catalogue, 'Duplicate edit link must be removed')
    entries = json.loads(re.search(r'<script type="application/json" id="main-category-data">(.*?)</script>', catalogue, re.S).group(1))
    def preview_url(source, target):
        return BASE + '?route=%2Fcategories&preview=merge&id=' + str(source) + '&target_id=' + str(target)
    result = json.loads(owner.open(preview_url(entries[0]['id'], entries[1]['id']), timeout=15).read())
    check(result['products'] == 0 and result['subcategories'] == len(entries[0]['subcategories']), 'HTTP preview must use actual affected counts')
    for opener, source, target, expected in [(owner, entries[0]['id'], entries[0]['id'], 422), (staff, entries[0]['id'], entries[1]['id'], 403)]:
        try: response = opener.open(preview_url(source, target), timeout=15)
        except urllib.error.HTTPError as error: response = error
        check(response.code == expected, 'Preview must reject self-selection and unauthorized users')
    data={'csrf':token(catalogue),'action':'save','level':'category','name':'AJAX category','sort_order':'0','status':'active'}
    status, result, _ = request(owner, '/categories', data, True)
    check(status == 200 and 'AJAX category' in json.loads(result)['html'], 'Category AJAX create must return refreshed content')
    status, result, _ = request(owner, '/categories', data, True)
    check(status == 422 and 'already in use' in json.loads(result)['message'], 'AJAX duplicate errors must have proper validation status and message')
    check(request(staff, '/categories', data, True)[0] == 403, 'AJAX must not bypass owner middleware')
    status, _, _ = request(owner, '/categories', {'action':'save','name':'No CSRF'}, True)
    check(status == 403, 'AJAX must not bypass CSRF')
    print(f'PASS: {checks} workspace HTTP checks (shell, truthful metrics, role boundaries, staff settings and AJAX).')
finally:
    subprocess.run([PHP, str(ROOT / 'tests/HttpFixture.php'), 'cleanup'], input=json.dumps(fixture), text=True, cwd=ROOT, check=True)
