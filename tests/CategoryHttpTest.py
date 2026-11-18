"""Opt-in local HTTP checks; only uniquely named category fixtures are modified."""
import html as html_module
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('SPARE_TEST_URL', 'http://127.0.0.1/spare_pos/public/index.php')
PHP = os.environ.get('SPARE_PHP_BINARY', '/Applications/XAMPP/xamppfiles/bin/php')
FIXTURE = json.loads(subprocess.check_output([PHP, str(ROOT / 'tests/HttpFixture.php'), 'create'], cwd=ROOT, text=True))
ACCOUNTS = FIXTURE['accounts']
PREFIX = 'HTTP-CAT-' + secrets.token_hex(6)
checks = 0

def expect(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(opener, route, data=None):
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        response = opener.open(BASE + '?route=' + urllib.parse.quote(route, safe=''), body, timeout=15)
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()
    return response.code, response.read().decode()

def token(page):
    match = re.search(r'name="csrf" value="([a-f0-9]+)"', page)
    if not match:
        raise AssertionError('Missing CSRF token')
    return match.group(1)

def login(role):
    opener = client()
    _, page = request(opener, '/login')
    account = ACCOUNTS[role]
    status, page = request(opener, '/login', {'csrf': token(page), 'email': account['email'], 'password': account['password']})
    expect(status == 200 and 'Hello, ' in page, role + ' login must succeed')
    return opener, page

def category_id(page, name):
    found = re.findall(r'<details class="category-card" id="category-(\d+)"><summary.*?<strong>(.*?)</strong>', page, re.S)
    for entry_id, entry_name in found:
        if html_module.unescape(entry_name) == name:
            return int(entry_id)
    raise AssertionError('Category missing: ' + name)

def subcategory_id(page, name):
    for section in re.findall(r'<section class="subcategory-row">.*?</section>', page, re.S):
        if '<h3>' + html_module.escape(name) + '</h3>' in section:
            return int(re.search(r'name="id" value="(\d+)"', section).group(1))
    raise AssertionError('Subcategory missing: ' + name)

try:
    owner, owner_page = login('shop_owner')
    staff, staff_page = login('staff')
    admin, admin_page = login('superadmin')
    expect(request(client(), '/categories')[1].find('Welcome back.') >= 0, 'Guests must redirect to login')
    for opener, page in [(staff, staff_page), (admin, admin_page)]:
        expect(request(opener, '/categories')[0] == 403, 'Category listing must be owner-only')
        expect(request(opener, '/categories', {'csrf': token(page), 'action': 'save', 'name': PREFIX})[0] == 403, 'Mutation must be owner-only')
    status, page = request(owner, '/categories')
    expect(status == 200 and 'Engine Parts' in page and 'Cables &amp; Controls' in page, 'Owner must see seeded catalogue')
    expect(request(owner, '/categories', {'action': 'save'})[0] == 403, 'Missing CSRF must be rejected')

    def mutate(**data):
        status, page = request(owner, '/categories')
        expect(status == 200, 'Category editor must remain available')
        data['csrf'] = token(page)
        status, page = request(owner, '/categories', data)
        expect(status == 200, 'Mutation must return the category page')
        return page

    a_name, b_name = PREFIX + '-A', PREFIX + '-B'
    page = mutate(action='save', level='category', name=a_name, status='active', sort_order='1')
    a = category_id(page, a_name)
    page = mutate(action='save', level='category', name=b_name, status='active', sort_order='2')
    b = category_id(page, b_name)
    page = mutate(action='save', level='category', name=a_name, status='active', sort_order='1')
    expect('already in use' in page, 'Duplicate categories must show a useful error')
    page = mutate(action='save', level='category', id=a, name=a_name + '-edited', description='Updated through HTTP', status='inactive', sort_order='3')
    expect(a_name + '-edited' in page and 'Updated through HTTP' in page, 'Category editing must persist')
    page = mutate(action='save', level='category', id=a, name=a_name, status='active', sort_order='1')
    page = mutate(action='reorder', level='category', id=a, direction='down')
    expect('Order updated.' in page, 'Reordering main category must succeed')
    page = mutate(action='save', level='subcategory', category_id=a, name=PREFIX + '-child', status='active', sort_order='10')
    sub = subcategory_id(page, PREFIX + '-child')
    page = mutate(action='save', level='subcategory', category_id=a, name=PREFIX + '-child', status='active', sort_order='10')
    expect('already in use' in page, 'Duplicate sibling must be rejected')
    page = mutate(action='save', level='subcategory', id=sub, category_id=a, name=PREFIX + '-child-edited', status='inactive', sort_order='20')
    expect(PREFIX + '-child-edited' in page, 'Subcategory editing must persist')
    page = mutate(action='reorder', level='subcategory', id=sub, direction='up')
    expect('Order updated.' in page, 'Subcategory reordering must succeed')
    page = mutate(action='save', level='subcategory', category_id='999999999', name=PREFIX + '-bad', status='active', sort_order='0')
    expect('Changes weren’t saved.' in page, 'Invalid parent must show validation feedback')
    page = mutate(**{'action':'save', 'level':'category', 'name[]':['invalid'], 'status':'active', 'sort_order':'0'})
    expect('Invalid form data.' in page, 'Malformed input must not crash error rendering')
    page = mutate(action='archive', level='category', id=a)
    expect('Move or archive all subcategories' in page, 'Main category with children must not archive')
    page = mutate(action='merge', level='category', id=a, target_id=b)
    expect('Categories merged.' in page, 'Category merge must succeed')
    expect(a_name not in [html_module.unescape(name) for _, name in re.findall(r'<details class="category-card" id="category-(\d+)"><summary.*?<strong>(.*?)</strong>', page, re.S)], 'Merged source must leave the visible catalogue')
    merged = subcategory_id(page, PREFIX + '-child-edited')
    page = mutate(action='archive', level='subcategory', id=merged)
    expect('Archived safely.' in page, 'Empty subcategory must archive')
    page = mutate(action='archive', level='category', id=b)
    expect('Archived safely.' in page, 'Empty main category must archive')
    print(f'PASS: {checks} category HTTP checks (owner access, CRUD, ordering, validation, merge and archival).')
finally:
    # All reordering and mutations stay in an isolated shop; real ordering is untouched.
    subprocess.run([PHP, str(ROOT / 'tests/HttpFixture.php'), 'cleanup'], input=json.dumps(FIXTURE), text=True, cwd=ROOT, check=True)
