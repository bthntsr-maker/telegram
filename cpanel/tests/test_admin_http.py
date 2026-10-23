"""Run ONLY against disposable local PHP/MySQL fixtures; creates an admin account."""
import hashlib
import hmac
from http.cookies import SimpleCookie
import json
import time
import urllib.error
import urllib.request
from urllib.parse import urlencode

BASE = 'http://127.0.0.1:8090'
cookie = ''
csrf = ''


def request(route, body=None, *, token=None, origin='https://example.org', auth=None):
    global cookie
    path = '/admin/api.php?route=' + route
    headers = {'Cookie': cookie}
    if body is not None:
        headers.update({'Content-Type': 'application/json', 'X-CSRF-Token': csrf if token is None else token, 'Origin': origin})
    if auth is not None:
        path = '/app/api.php?route=' + route
        headers['Authorization'] = 'tma ' + auth
    req = urllib.request.Request(BASE + path, headers=headers, data=json.dumps(body).encode() if body is not None else None)
    try:
        response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        raw_cookie = response.headers.get('Set-Cookie')
        if raw_cookie:
            c = SimpleCookie(); c.load(raw_cookie)
            cookie = '; '.join(f'{k}={v.value}' for k,v in c.items())
            assert 'httponly' in raw_cookie.lower() and 'secure' in raw_cookie.lower() and 'samesite=strict' in raw_cookie.lower()
        return response.status, json.load(response)


def check(condition, label):
    assert condition, label
    print('PASS', label)


def signed(uid=123):
    data = {'auth_date':str(int(time.time())), 'user':json.dumps({'id':uid,'first_name':'Fixture'})}
    secret = hmac.new(b'WebAppData', b'test-token', hashlib.sha256).digest()
    data['hash'] = hmac.new(secret, '\n'.join(f'{k}={v}' for k,v in sorted(data.items())).encode(), hashlib.sha256).hexdigest()
    return urlencode(data)


if __name__ == '__main__':
    status,data=request('session'); csrf=data['csrf']
    check(status==200 and data['needs_setup'] and data['user'] is None, 'initial setup and secure cookie')
    check(request('dashboard',{})[0]==401, 'anonymous dashboard blocked')
    check(request('setup',{'setup_key':'wrong','username':'owner','password':'test-fixture-password'})[0]==400, 'wrong setup key blocked')
    setup={'setup_key':'test-setup-key-12345678901234567890','username':'owner','password':'test-fixture-password'}
    check(request('setup',setup)[0]==200, 'owner account created')
    check(request('setup',setup)[0]==400, 'account cannot be overwritten')
    check(request('login',{'username':'owner','password':'wrong'})[0]==400, 'wrong password rejected')
    check(request('login',{'username':'owner','password':'test-fixture-password'},token='wrong')[0]==403, 'CSRF blocked')
    check(request('login',{'username':'owner','password':'test-fixture-password'},origin='https://attacker.invalid')[0]==403, 'cross-origin login blocked')
    previous_cookie=cookie
    status,data=request('login',{'username':'owner','password':'test-fixture-password'})
    check(status==200 and data['user']['id']==999 and cookie!=previous_cookie, 'login regenerates session')
    old_csrf=csrf; csrf=data['csrf']
    check(request('dashboard',{},token=old_csrf)[0]==403, 'old CSRF token invalid after login')
    status,data=request('dashboard',{})
    check(status==200 and 'summary' in data, 'authenticated dashboard works')
    credit={'user_id':123,'amount':2500,'request_id':'http-credit-1234567'}
    check(request('admin/credit',credit)[0]==200 and request('admin/credit',credit)[0]==200, 'session credit retries work')
    check(request('dashboard',{})[1]['summary']['balance']==2500, 'credit applied only once')
    body={'proof':'fixture.user@gmail.com','password':'fixture-account-secret','consent':True,'request_id':'http-submission-123456'}
    status,data=request('submit',body,auth=signed())
    check(status==200, 'Mini App authentication still works')
    sid=data['id']
    check(request('admin/password',{'id':sid})[1]['password']=='fixture-account-secret', 'authorized password reveal')
    approval={'id':sid,'action':'approve','amount':1000}
    check(request('admin/submission',approval)[0]==200 and request('admin/submission',approval)[0]==400, 'session admin approval only once')
    check(request('dashboard',{})[1]['summary']['balance']==3500, 'shared balance updated')
    check(request('logout',{})[0]==200, 'logout works')
    status,data=request('session');csrf=data['csrf']
    check(request('dashboard',{})[0]==401, 'logged-out access blocked')
    for _ in range(5):request('login',{'username':'owner','password':'wrong'})
    status,data=request('login',{'username':'owner','password':'test-fixture-password'})
    check(status==400 and '15 dakika' in data['error'], 'brute-force throttled')
