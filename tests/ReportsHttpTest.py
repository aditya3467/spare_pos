import os,json,re,csv,io,zipfile,http.cookiejar,urllib.request,urllib.parse,urllib.error,subprocess
login=json.loads(os.environ['REPORT_HTTP_LOGIN']);base='http://127.0.0.1:8102/index.php';checks=0

def check(v,m):
 global checks
 if not v:raise RuntimeError(m)
 checks+=1

def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def call(c,route,data=None,params=None):
 req=urllib.request.Request(base+'?'+urllib.parse.urlencode({'route':route,**(params or {})}),urllib.parse.urlencode(data).encode() if data is not None else None)
 try:r=c.open(req,timeout=20)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read(),r.headers

def signin(email):
 c=client();_,b,_=call(c,'/login');token=re.search(r'name="csrf" value="([a-f0-9]+)"',b.decode()).group(1);call(c,'/login',{'csrf':token,'email':email,'password':login['password']});return c
owner=signin(login['email']);staff=signin(login['staff_email']);other=signin('other@reports.test')
for route in ['/reports','/reports/sales','/reports/profit','/reports/inventory']:
 status,b,_=call(owner,route,params={'period':'today'});check(status==200 and b'Reports &amp; Analytics' in b,'Owner report '+route);check(call(staff,route)[0]==403,'Staff permission '+route)
check(call(staff,'/reports/export',params={'dataset':'stock'})[0]==403,'Exports enforce reports permission')
status,b,_=call(owner,'/reports/sales',params={'period':'today'});text=b.decode();check('708.00' in text and 'Cancelled (excluded)' in text and '236.00' in text,'Sales totals refunds and cancellation status');check('Card' in text and 'Bank Transfer' in text and 'Credit (unpaid)' in text and 'Net collections' in text,'Actual payment breakdown and credit shown separately')
status,b,_=call(owner,'/reports/profit',params={'period':'today'});check(b'390.00' in b and b'Product sales' in b and b'Labour / Service' in b and b'Gross profit is not net profit' in b,'Product/service gross profit separation')
check(b'Record an actual labour expense' not in b,'New analytics does not introduce expense management')
status,b,_=call(owner,'/reports/profit',params={'period':'custom','from':'2001-02-01','to':'2001-02-01'});check(status==200 and b'Unavailable' in b and b'Historical cost / revenue exceptions' in b and b'LEGACY-COST' in b,'Historical missing cost is identified without invented profit')
status,b,_=call(other,'/reports/sales',params={'period':'today','shop_id':'1'});check(status==200 and b'Report Customer' not in b and b'RPT/' not in b,'HTTP shop scope ignores tampered shop ID')
status,b,h=call(owner,'/reports/export',params={'dataset':'stock','format':'csv','period':'today','status':'all'});rows=list(csv.reader(io.StringIO(b.decode('utf-8-sig'))));check(status==200 and len(rows)>26 and 'attachment' in h.get('Content-Disposition',''),'CSV exports all stock rows beyond UI page');check(rows[0][0]=='Product' and any('Archived stock' in row for row in rows),'CSV respects all-status filter')
for dataset in ['invoices','products','services','movements','payments','exceptions','trend']:
 status,b,h=call(owner,'/reports/export',params={'dataset':dataset,'format':'csv','period':'today'});check(status==200 and 'text/csv' in h.get('Content-Type','') and len(b)>5,'CSV dataset '+dataset)
status,b,h=call(owner,'/reports/export',params={'dataset':'products','format':'xlsx','period':'today'});z=zipfile.ZipFile(io.BytesIO(b));check(status==200 and '[Content_Types].xml' in z.namelist() and b'Product' in z.read('xl/worksheets/sheet1.xml'),'True XLSX download')
status,b,_=call(owner,'/reports',params={'period':'custom','from':'2026-11-30','to':'2026-11-01'});check(status==422 and b'Start date must not follow' in b,'Date validation UI')
check(call(owner,'/reports/export',params={'dataset':'evil'})[0]==422 and call(owner,'/reports/export',params={'dataset':'stock','format':'pdf'})[0]==422,'Invalid exports rejected')
_,b,_=call(owner,'/reports/inventory',params={'period':'today','stock':'no_sales','q':'Never sold'});check(b'Never sold' in b and b'Current stock &amp; period sales' in b,'No-sales search/filter UI')
# Grant only the existing reports permission in this disposable fixture.
php='/Applications/XAMPP/xamppfiles/bin/php';subprocess.run([php,'-r',"require 'app/bootstrap.php'; db()->exec(\"INSERT INTO staff_permissions VALUES(2,'reports.view')\");"],check=True)
status,b,_=call(staff,'/reports/sales',params={'period':'today'});check(status==200 and b'708.00' in b and b'route=%2Fsales%2Finvoice&amp;id=' not in b,'Report-only staff sees analytics but not unauthorised invoice links');check(call(staff,'/reports/export',params={'dataset':'invoices','period':'today'})[0]==200,'Granted staff can export reports')
print(f'PASS: {checks} reports HTTP/permission/export checks.')
