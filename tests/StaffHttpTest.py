import os,json,re,http.cookiejar,urllib.request,urllib.parse,urllib.error
login=json.loads(os.environ['STAFF_HTTP_LOGIN']);base='http://127.0.0.1:8103/index.php';checks=0

def check(v,m):
 global checks
 if not v:raise RuntimeError(m)
 checks+=1

def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def call(c,route,data=None,params=None,as_json=False):
 req=urllib.request.Request(base+'?'+urllib.parse.urlencode({'route':route,**(params or {})}),urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None,headers={'Accept':'application/json'} if as_json else {})
 try:r=c.open(req,timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read().decode(),r.headers

def token(s):return re.search(r'name="csrf" value="([a-f0-9]+)"',s).group(1)
def signin(email,password=None):
 c=client();_,s,_=call(c,'/login');status,s,_=call(c,'/login',{'csrf':token(s),'email':email,'password':password or login['password']});return c,status,s
owner,_,_=signin(login['email']);staff,_,_=signin(login['staff_email']);admin,_,_=signin(login['admin_email']);_,page,_=call(owner,'/staff');csrf=token(page)
check('+ Add staff' in page and 'data-staff-open="reset"' in page and 'data-staff-open="status"' in page,'Owner staff actions render')
for c in [staff,admin]:
 for route in ['/staff','/staff/form','/staff/save']:
  check(call(c,route,{'csrf':csrf,'action':'create'} if route=='/staff/save' else None)[0]==403,'Only owners manage staff '+route)
check(call(owner,'/staff/save',{'action':'create'})[0]==403,'CSRF on account writes')
for action in ['create','edit','reset','status']:
 status,s,_=call(owner,'/staff/form',params={'action':action,'staff_id':'2'});check(status==200 and 'staff-account-page' in s,'No-JavaScript form '+action)
check(call(owner,'/staff/form',params={'action':'edit','staff_id':'3'})[0]==404,'Foreign staff form blocked')
d={'csrf':csrf,'action':'create','name':'HTTP Staff','email':'http@staff.test','password':'HTTP-password-1234','password_confirmation':'HTTP-password-1234','shop_id':'2','role':'shop_owner'}
status,s,_=call(owner,'/staff/save',d,as_json=True);check(status==200 and json.loads(s)['redirect'].endswith('%2Fstaff') and d['password'] not in s,'AJAX create without credential disclosure')
_,listing,_=call(owner,'/staff');check('HTTP Staff' in listing and 'Staff account created.' in listing and d['password'] not in listing,'Created staff listed and success message shown')
m=re.search(r'data-staff-id="([0-9]+)" data-staff-name="HTTP Staff"',listing);new_id=m.group(1)
_,status,body=signin('http@staff.test',d['password']);check(status==200 and 'Hello, HTTP Staff' in body,'New credentials sign in')
status,s,_=call(owner,'/staff/save',d,as_json=True);check(status==422 and 'already registered' in json.loads(s)['message'],'Duplicate email error remains in modal response')
status,s,_=call(owner,'/staff/save',dict(d,email='bad-confirm@staff.test',password_confirmation='Mismatch'),as_json=True);check(status==422 and 'confirmation' in json.loads(s)['message'],'Password confirmation enforced')
status,s,_=call(owner,'/staff/save',dict(d,email='bad-password@staff.test',password='short',password_confirmation='short'));check(status==422 and 'Use a password' in s and 'value="short"' not in s,'Fallback form does not echo rejected passwords')
for target in ['1','3','4']:
 status,s,_=call(owner,'/staff/save',{'csrf':csrf,'action':'edit','staff_id':target,'name':'Changed','email':'changed@staff.test'},as_json=True);check(status==422,'Only own staff can be changed')
old_staff,_,_=signin(login['staff_email']);old_staff2,_,_=signin(login['staff_email']);reset={'csrf':csrf,'action':'reset','staff_id':'2','password':'HTTP-reset-password-123','password_confirmation':'HTTP-reset-password-123','confirmed':'1'}
status,s,_=call(owner,'/staff/save',reset,as_json=True);check(status==200,'Owner password reset')
for old in [old_staff,old_staff2]:
 _,s,_=call(old,'/dashboard');check('login-card' in s,'Reset revokes every existing session')
_,_,s=signin(login['staff_email'],login['password']);check('Email or password is incorrect.' in s,'Old password rejected')
active_staff,_,s=signin(login['staff_email'],reset['password']);check('Hello, Existing Staff' in s,'New password works')
state={'csrf':csrf,'action':'status','staff_id':'2','active':'0','confirmed':'1'};status,_,_=call(owner,'/staff/save',state,as_json=True);check(status==200,'Deactivate')
_,_,s=signin(login['staff_email'],reset['password']);check('Email or password is incorrect.' in s,'Inactive credentials cannot sign in')
call(owner,'/staff/save',dict(state,active='1'),as_json=True);_,s,_=call(active_staff,'/dashboard');check('login-card' in s,'A dormant session cannot resume after reactivation')
active_staff,_,_=signin(login['staff_email'],reset['password']);edit={'csrf':csrf,'action':'edit','staff_id':'2','name':'Renamed Staff','email':login['staff_email']};call(owner,'/staff/save',edit,as_json=True);_,s,_=call(active_staff,'/dashboard');check('Hello, Renamed Staff' in s,'Name-only edit preserves a valid session')
call(owner,'/staff/save',dict(edit,email='renamed@staff.test'),as_json=True);_,s,_=call(active_staff,'/dashboard');check('login-card' in s,'Email change signs out old session');_,_,s=signin('renamed@staff.test',reset['password']);check('Hello, Renamed Staff' in s,'Edited email works with unchanged password')
# Leave browser fixtures in a known state; all data is in the disposable database.
call(owner,'/staff/save',dict(edit),as_json=True);call(owner,'/staff/save',dict(reset,password=login['password'],password_confirmation=login['password']),as_json=True)
print(f'PASS: {checks} staff HTTP/login/session checks.')
