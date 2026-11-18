import os,json,re,http.cookiejar,urllib.request,urllib.parse,urllib.error
login=json.loads(os.environ['INVENTORY_HTTP_LOGIN']);base='http://127.0.0.1:8099/index.php';checks=0

def check(value,message):
 global checks
 if not value:raise AssertionError(message)
 checks+=1

def call(client,route,data=None,params=None):
 values={'route':route};values.update(params or {});req=urllib.request.Request(base+'?'+urllib.parse.urlencode(values),urllib.parse.urlencode(data).encode() if data is not None else None)
 try:r=client.open(req,timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read().decode(),r.geturl()
def token(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
def client(email,password):
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));_,page,_=call(c,'/login');status,page,_=call(c,'/login',{'csrf':token(page),'email':email,'password':password});check(status==200,'Login');return c
owner=client(login['email'],login['password']);staff=client(login['staff_email'],login['staff_password'])
for route in ['/inventory','/inventory/out','/inventory/adjust','/inventory/movements']:
 check(call(staff,route)[0]==403,'Staff permission blocks '+route)
check(call(staff,'/inventory/in')[0]==200,'Stock-in-only staff can open authorised form')
for route in ['/inventory','/inventory/in','/inventory/out','/inventory/adjust','/inventory/movements']:
 status,page,_=call(owner,route);check(status==200 and 'app-sidebar' in page,'Owner page renders: '+route)
status,payload,_=call(owner,'/inventory/search',params={'q':'INV-OEM','kind':'in'});check(status==200 and json.loads(payload)['products'],'OEM search finds product')
_,form,_=call(owner,'/inventory/in',params={'product_id':login['product_id']})
key=re.search(r'name="request_key" value="([a-f0-9]+)"',form).group(1)
data={'csrf':token(form),'kind':'in','request_key':key,'product_id':login['product_id'],'quantity':'2','unit_cost':'50','reason':'HTTP receipt','reference':'HTTP-REF','entry_date':'2026-01-03','confirmed':'1'}
status,page,url=call(owner,'/inventory/post',data);expected_stock=re.search(r'<dt>Resulting stock</dt><dd>([^<]+)',page).group(1);check(status==200 and 'HTTP receipt' in page and 'Previous stock' in page,'HTTP stock-in posts and shows movement details')
status,again,url2=call(owner,'/inventory/post',data);check(status==200 and url==url2,'HTTP retry returns same movement')
_,form,_=call(owner,'/inventory/out',params={'product_id':login['product_id']});out=dict(data,kind='out',csrf=token(form),request_key=re.search(r'name="request_key" value="([a-f0-9]+)"',form).group(1),quantity='999999');status,page,_=call(owner,'/inventory/post',out);check(status==422 and 'Insufficient stock' in page,'HTTP excessive removal rejected with clear error')
out['confirmed']='0';status,page,_=call(owner,'/inventory/post',out);check(status==422 and 'Confirm the stock operation' in page,'Unconfirmed change rejected')
check(call(owner,'/inventory/post',{'kind':'in'})[0]==403,'Stock posting requires CSRF')
_,page,_=call(owner,'/inventory/movements',params={'q':'HTTP-REF','type':'stock_in'});check('HTTP receipt' in page,'Movement search/type filter works')
_,page,_=call(owner,'/products',params={'q':'Edited inventory'});check('Edited inventory pad' in page and 'data-label="Available stock">'+expected_stock in page,'Product listing immediately displays the updated balance')
search_fixture=json.loads(os.environ['INVENTORY_SEARCH_FIXTURES'])
def ajax(params):
 req=urllib.request.Request(base+'?'+urllib.parse.urlencode({'route':'/inventory',**params}),headers={'Accept':'application/json'})
 response=owner.open(req,timeout=15);return json.loads(response.read())
found=ajax({'q':' REF '});check(found['total']==7 and '<main' not in found['html'],'Universal search returns unique ranked products as fragment')
found=ajax({'q':'Bajaj REF','manufacturer_id':search_fixture['maker'],'model_id':search_fixture['model'],'stock':'low'});check(found['total']==1 and 'Bike part' in found['html'] and 'Compatible bikes' in found['html'],'Bike filter combines with universal query and stock state')
check(ajax({'model_id':search_fixture['model'],'variant_id':search_fixture['variant'],'year':'2024'})['total']==0,'Recorded variant year restrictions applied')
_,payload,_=call(owner,'/inventory/search',params={'q':'local slang','kind':'in'});check(json.loads(payload)['products'][0]['match_info']=='Alias / local name','Stock-entry picker reuses ranked aliases search')
_,adjust_form,_=call(owner,'/inventory/adjust',params={'product_id':login['product_id']});prior=re.search(r'name="expected_quantity" value="([^"]+)"',adjust_form).group(1)
_,in_form,_=call(owner,'/inventory/in',params={'product_id':login['product_id']});other=dict(data,csrf=token(in_form),request_key=re.search(r'name="request_key" value="([a-f0-9]+)"',in_form).group(1));call(owner,'/inventory/post',other)
stale={'csrf':token(adjust_form),'request_key':re.search(r'name="request_key" value="([a-f0-9]+)"',adjust_form).group(1),'kind':'adjust','product_id':login['product_id'],'expected_quantity':prior,'physical_quantity':'17','entry_date':'2026-01-03','reason':'Stale count test','confirmed':'1'}
status,refreshed,_=call(owner,'/inventory/post',stale);new_expected=re.search(r'name="expected_quantity" value="([^"]+)"',refreshed).group(1)
check(status==422 and 'Stock changed' in refreshed and new_expected!=prior and 'name="confirmed" value="0"' in refreshed,'Concurrent adjustment refreshes preview and requires confirmation again')
print(f'PASS: {checks} inventory HTTP checks.')
