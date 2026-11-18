"""Live HTTP product tests; zero-stock fixtures only, with guarded cleanup."""
import csv,io,json,os,re,secrets,subprocess,http.cookiejar,urllib.request,urllib.parse,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];PHP=os.environ.get('SPARE_PHP_BINARY','/Applications/XAMPP/xamppfiles/bin/php');BASE=os.environ.get('SPARE_TEST_URL','http://127.0.0.1/spare_pos/public/index.php')
fixture=json.loads(subprocess.check_output([PHP,str(ROOT/'tests/HttpFixture.php'),'create'],cwd=ROOT,text=True));checks=0;stages=[]
def check(v,message):
 global checks
 if not v: raise AssertionError(message)
 checks+=1
def call(opener,route,data=None,raw=None,headers=None):
 url=BASE+'?route='+urllib.parse.quote(route,safe='');req=urllib.request.Request(url,raw if raw is not None else (urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None),headers=headers or {})
 try:r=opener.open(req,timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read().decode(),r.geturl()
def token(page):return re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
def login(role):
 o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));_,page,_=call(o,'/login');a=fixture['accounts'][role];status,page,_=call(o,'/login',{'csrf':token(page),'email':a['email'],'password':a['password']});check(status==200,'Login succeeds');return o
try:
 env=os.environ.copy();env['PRODUCT_HTTP_SHOP']=str(fixture['shop_id'])
 setup="require 'app/bootstrap.php';$s=(int)getenv('PRODUCT_HTTP_SHOP');$m=new App\\Services\\ProductMasterService($s);$m->seedVehicles();$m->save('brands',['name'=>'HTTP Brand']);echo json_encode($m->all());"
 masters=json.loads(subprocess.check_output([PHP,'-r',setup],cwd=ROOT,env=env,text=True));category=masters['categories'][0];model=masters['vehicle_models'][0]
 owner=login('shop_owner');staff=login('staff');check(call(staff,'/products')[0]==403,'Unprivileged staff cannot access products');check(call(staff,'/product-masters')[0]==403,'Masters owner-only')
 status,form,_=call(owner,'/products/form');check(status==200 and 'product-form' in form,'Product form renders')
 key=re.search(r'name="creation_key" value="([a-f0-9]+)"',form).group(1)
 data={'csrf':token(form),'creation_key':key,'name':'HTTP pad','category_id':category['id'],'purchase_price':'80.50','selling_price':'120','opening_quantity':'0','opening_cost':'','opening_date':'2026-01-01','reorder_level':'2','fitments[0][manufacturer_id]':model['manufacturer_id'],'fitments[0][model_id]':model['id']}
 status,page,url=call(owner,'/products/save',data);check(status==200 and 'HTTP pad' in page and 'No stock movements recorded' in page,'Create product through form with own stock balance');id=int(urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['id'][0]);check('Unverified' in page,'New bike mapping unverified')
 status,again,url=call(owner,'/products/save',data);check(status==200 and int(urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['id'][0])==id,'HTTP retries are idempotent')
 r=owner.open(BASE+'?route=%2Fproducts%2Fform&duplicate='+str(id));copy=r.read().decode();check('Duplicate product' in copy and 'Generated uniquely when saved' in copy and 'name="opening_quantity" type="number" value="0"' in copy,'Duplicate form never copies SKU or stock')
 copykey=re.search(r'name="creation_key" value="([a-f0-9]+)"',copy).group(1);newdata=dict(data,creation_key=copykey,csrf=token(copy),name='HTTP pad copy');status,page,_=call(owner,'/products/save',newdata);check(status==200 and 'HTTP pad copy' in page,'Duplicate creates another product only on submit')
 edit=owner.open(BASE+'?route=%2Fproducts%2Fform&id='+str(id)).read().decode();check('name="opening_quantity"' not in edit,'Edit excludes stock fields');version=re.search(r'name="lock_version" value="(\d+)"',edit).group(1)
 edited={k:v for k,v in data.items() if not k.startswith('opening_')};edited.update({'id':id,'lock_version':version,'name':'HTTP pad edited','csrf':token(edit)});status,page,_=call(owner,'/products/save',edited);check(status==200 and 'Stock was not changed' in page,'Edit preserves inventory');check(call(owner,'/products/save',edited)[0]==422,'Stale edit rejected')
 status,page,_=call(owner,'/products');check(status==200 and 'HTTP pad edited' in page and 'HTTP pad copy' in page,'Listing shows products')
 check(call(owner,'/products/save',{'name':'No csrf'})[0]==403,'Product mutation requires CSRF')
 _,importpage,_=call(owner,'/products/import');check('Download compatibility template' in importpage,'Separate compatibility workflow exposed')
 output=io.StringIO();writer=csv.writer(output);writer.writerow(['name','category','purchase_price','selling_price','opening_quantity','sku']);writer.writerow(['Imported HTTP pad',category['name'],'20','40','0','HTTP-IMPORT']);writer.writerow(['Rejected HTTP pad','Unknown category','20','40','0','HTTP-BAD'])
 boundary='----spare'+secrets.token_hex(12);body=b''
 for name,value in {'csrf':token(importpage),'kind':'products'}.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
 body+=f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="products.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode()+output.getvalue().encode()+f'\r\n--{boundary}--\r\n'.encode()
 status,page,url=call(owner,'/products/import-preview',raw=body,headers={'Content-Type':'multipart/form-data; boundary='+boundary});check(status==200 and 'Unknown or inactive category' in page and 'Ready' in page,'Uploaded CSV preview shows valid and rejected rows');stage=urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['token'][0];stages.append(stage)
 status,page,_=call(owner,'/products/import-commit',{'csrf':token(page),'token':stage});check(status==200 and 'Import results' in page and 'Imported HTTP pad' in page and 'Rejected' in page,'Import commits valid rows and reports failures');status,page,_=call(owner,'/products/import-commit',{'csrf':token(page),'token':stage});check(status==200,'Import replay succeeds without duplicate stock')
 check(owner.open(BASE+'?route=%2Fproducts%2Fimport-report&token='+stage).status==200,'Row report downloads')
 print(f'PASS: {checks} product HTTP checks including actual upload, creation, duplicate, edit, permissions and import replay.')
finally:
 for stage in stages:
  path=ROOT/'storage/imports'/('product-'+stage+'.json')
  if path.exists(): path.unlink()
 env=os.environ.copy();env['PRODUCT_HTTP_SHOP']=str(fixture['shop_id'])
 cleanup="""require 'app/bootstrap.php';$s=(int)getenv('PRODUCT_HTTP_SHOP');$q=db()->prepare('SELECT COUNT(*) FROM stock_movements WHERE shop_id=?');$q->execute([$s]);if($q->fetchColumn())throw new Exception('Cleanup refused: stock ledger exists');foreach(['product_fitments','product_specifications','product_aliases','stock_balances','products','vehicle_fitments','vehicle_variants','vehicle_models','vehicle_manufacturers','brands','tax_rates','units'] as $t)db()->prepare("DELETE FROM $t WHERE shop_id=?")->execute([$s]);"""
 subprocess.run([PHP,'-r',cleanup],cwd=ROOT,env=env,check=True)
 subprocess.run([PHP,str(ROOT/'tests/HttpFixture.php'),'cleanup'],input=json.dumps(fixture),text=True,cwd=ROOT,check=True)
