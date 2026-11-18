import os,json,re,urllib.request,urllib.parse,http.cookiejar
login=json.loads(os.environ['SALES_HTTP_LOGIN']);base='http://127.0.0.1:8100/index.php';checks=0
def check(v,m):
 global checks
 if not v:raise RuntimeError(m)
 checks+=1
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def call(c,route,data=None,params=None):
 url=base+'?'+urllib.parse.urlencode({'route':route,**(params or {})})
 try:
  r=c.open(url,urllib.parse.urlencode(data).encode() if data is not None else None);return r.status,r.read().decode(),r.url
 except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.url
def token(s):return re.search(r'(?:name="csrf" value|data-csrf)="([a-f0-9]+)"',s).group(1)
def signin(email):
 c=client();_,s,_=call(c,'/login');call(c,'/login',{'email':email,'password':login['password'],'csrf':token(s)});return c
owner=signin(login['email']);staff=signin(login['staff_email'])
status,pos,_=call(owner,'/pos');csrf=token(pos);check(status==200 and 'pos-cart-items' in pos,'POS screen')
for route in ['/pos/search','/pos/quote','/pos/complete','/pos/customer']:
 data={'csrf':csrf,'payload':'{}'} if route!='/pos/search' else None
 check(call(staff,route,data,{'q':'pad'})[0]==403,'Staff POS permission '+route)
check(call(staff,'/sales')[0]==403 and call(staff,'/sales/settings')[0]==403,'History/settings denied')
check(call(owner,'/pos/complete',{'payload':'{}'})[0]==403,'CSRF enforced')
for query in ['OEM-SALE','local brake','Sales Disc']:
 status,s,_=call(owner,'/pos/search',params={'q':query});check(status==200 and json.loads(s)['products'],'Search '+query)
d={'items':[{'product_id':str(login['product_id']),'quantity':'1','discount':'0'}],'place_of_supply':'27','customer_id':'','bill_discount':'0','payments':[{'method':'cash','amount':'120','reference':''}],'confirmed':'1','request_key':'a'*40}
status,s,_=call(owner,'/pos/quote',{'csrf':csrf,'payload':json.dumps(d)});q=json.loads(s)['quote'];check(status==200 and q['total']=='118.00','Server quote endpoint');d['quote_hash']=q['quote_hash']
status,s,_=call(owner,'/pos/complete',{'csrf':csrf,'payload':json.dumps(d)});result=json.loads(s);check(status==200 and result['id'],'Complete sale endpoint');sid=result['id']
_,s,_=call(owner,'/pos/complete',{'csrf':csrf,'payload':json.dumps(d)});check(json.loads(s)['id']==sid,'HTTP retry idempotent')
status,invoice,_=call(owner,'/sales/invoice',params={'id':sid});check(status==200 and 'Payment details' in invoice and 'Linked stock movements' in invoice,'Invoice details')
for fmt in ['a4','thermal']:
 status,s,_=call(owner,'/sales/print',params={'id':sid,'format':fmt});check(status==200 and 'invoice-document' in s and 'Print / Save as PDF' in s,'Printable '+fmt)
_,history,_=call(owner,'/sales',params={'q':'TEST'});check('Sales Disc' not in history and 'TEST/' in history,'History search')
status,s,_=call(owner,'/pos/customer',{'csrf':csrf,'payload':json.dumps({'name':'HTTP Customer','phone':'11112222'})});check(status==200 and json.loads(s)['customer']['id'],'Inline customer create')
status,s,_=call(owner,'/pos/customers',params={'q':'11112222'});check(status==200 and len(json.loads(s)['customers'])==1,'Customer search')
_,cancelled,_=call(owner,'/sales/cancel',{'csrf':csrf,'id':sid,'reason':'HTTP test','confirmed':'1','refund_confirmed':'1'});check('Invoice cancelled.' in cancelled and 'Reversal #' in cancelled,'Cancellation audit links')
_,again,_=call(owner,'/sales/cancel',{'csrf':csrf,'id':sid,'reason':'HTTP test','confirmed':'1','refund_confirmed':'1'});check('Invoice cancelled.' in again,'Cancellation retry')
labour_fixture=json.loads(os.environ['LABOUR_HTTP_FIXTURE'])
check('pos-place' not in pos and 'Place of supply<select' not in pos and 'pos-add-labour' in pos,'Main POS has labour action and no state selector')
check(call(staff,'/sales/report')[0]==403 and call(staff,'/sales/labour-expense',{'csrf':csrf})[0]==403,'Financial reports and expenses respect permissions')
_,report,_=call(owner,'/sales/report');check('Revenue categories' in report and 'Labour / Service' in report and 'Net after recorded expenses' in report,'Revenue and profit report renders actual categories')
_,dash,_=call(owner,'/dashboard');check('Today’s revenue' in dash and 'Labour / Service' in dash and 'Sales not set up' not in dash,'Dashboard includes recorded service revenue')
ld={'items':[],'labour':[{'description':'HTTP wheel fitting','amount':'118','discount':'0'}],'customer_id':'','bill_discount':'0','payments':[{'method':'cash','amount':'118','reference':''}],'confirmed':'1','request_key':'b'*40}
status,s,_=call(owner,'/pos/quote',{'csrf':csrf,'payload':json.dumps(ld)});ld['quote_hash']=json.loads(s)['quote']['quote_hash'];check(status==200 and json.loads(s)['quote']['total']=='118.00','Labour-only quote without explicit state')
status,s,_=call(owner,'/pos/complete',{'csrf':csrf,'payload':json.dumps(ld)});labour_id=json.loads(s)['id'];check(status==200,'Labour-only HTTP checkout')
_,invoice,_=call(owner,'/sales/invoice',params={'id':labour_id});check('HTTP wheel fitting' in invoice and 'Labour charges' in invoice and 'Linked stock movements' not in invoice,'Service invoice separate from stock')
for fmt in ['a4','thermal']:
 status,s,_=call(owner,'/sales/print',params={'id':labour_id,'format':fmt});check(status==200 and 'HTTP wheel fitting' in s and 'Labour / Service' in s,'Labour print '+fmt)
_,cancelled,_=call(owner,'/sales/cancel',{'csrf':csrf,'id':labour_id,'reason':'Labour cancellation HTTP','confirmed':'1','refund_confirmed':'1'});check('Invoice cancelled.' in cancelled and 'Reverses payment' in cancelled,'Labour cancellation/refund audit')
print(f'PASS: {checks} sales HTTP checks.')
