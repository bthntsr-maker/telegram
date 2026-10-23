import hashlib
import hmac
import json
import os
import tempfile
import threading
import time
import unittest
import urllib.request
import urllib.error
from urllib.parse import urlencode
from unittest.mock import patch
from http.server import ThreadingHTTPServer
import bot
import web


def signed(uid=123,age=0,key='test-token'):
    d={'auth_date':str(int(time.time())-age),'user':json.dumps({'id':uid,'first_name':'Deneme'})}
    secret=hmac.new(b'WebAppData',key.encode(),hashlib.sha256).digest()
    d['hash']=hmac.new(secret,'\n'.join(f'{k}={v}' for k,v in sorted(d.items())).encode(),hashlib.sha256).hexdigest()
    return urlencode(d)


class WebTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.db=patch.object(bot,'DB',os.path.join(self.tmp.name,'db.sqlite3'));self.db.start()
        self.env=patch.dict(os.environ,{'TELEGRAM_BOT_TOKEN':'test-token','TELEGRAM_ADMIN_IDS':'999'});self.env.start()
        self.server=ThreadingHTTPServer(('127.0.0.1',0),web.Handler)
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
        self.base=f'http://127.0.0.1:{self.server.server_port}'

    def tearDown(self):
        self.server.shutdown();self.server.server_close();self.thread.join()
        self.env.stop();self.db.stop();self.tmp.cleanup()

    def call(self,path,body=None,uid=123,auth=None):
        req=urllib.request.Request(self.base+'/api/'+path,data=json.dumps(body or {}).encode(),headers={'Authorization':'tma '+(signed(uid) if auth is None else auth),'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req) as r:return r.status,json.load(r)
        except urllib.error.HTTPError as e:return e.code,json.load(e)

    def test_auth_signature_expiry_and_forgery(self):
        self.assertEqual(self.call('state',auth='')[0],401)
        self.assertEqual(self.call('state',auth=signed(key='wrong'))[0],401)
        self.assertEqual(self.call('state',auth=signed(age=4000))[0],401)
        self.assertEqual(self.call('state',auth=signed(age=-100))[0],401)
        self.assertEqual(self.call('state')[0],200)

    def test_complete_workflow_and_duplicate_protection(self):
        body={'proof':'https://example.org/proof','request_id':'submission-123456789'}
        status,s=self.call('submit',body);self.assertEqual(status,200)
        self.assertEqual(self.call('submit',body)[1]['id'],s['id'])
        self.assertEqual(self.call('admin/submission',{'id':s['id'],'action':'approve','amount':2500})[0],403)
        approval={'id':s['id'],'action':'approve','amount':2500}
        self.assertEqual(self.call('admin/submission',approval,999)[0],200)
        self.assertEqual(self.call('admin/submission',approval,999)[0],400)
        self.assertEqual(self.call('state')[1]['balance'],2500)
        wd={'amount':2000,'destination':'Test alıcı TR12345678','request_id':'withdraw-123456789'}
        status,w=self.call('withdraw',wd);self.assertEqual(status,200)
        self.assertEqual(self.call('withdraw',wd)[1]['id'],w['id'])
        self.assertEqual(self.call('state')[1]['balance'],500)
        self.assertEqual(self.call('withdraw',{**wd,'request_id':'another-123456789'})[0],400)
        self.assertEqual(self.call('admin/withdrawal',{'id':w['id'],'action':'reject'},999)[0],200)
        self.assertEqual(self.call('admin/withdrawal',{'id':w['id'],'action':'reject'},999)[0],400)
        self.assertEqual(self.call('state')[1]['balance'],2500)
        credit={'user_id':123,'amount':1000,'request_id':'credit-123456789'}
        self.assertEqual(self.call('admin/credit',credit,999)[0],200)
        self.assertEqual(self.call('admin/credit',credit,999)[0],200)
        self.assertEqual(self.call('state')[1]['balance'],3500)
        self.assertEqual(len(self.call('state',uid=456)[1]['submissions']),0)
        wd['request_id']='paid-withdraw-123456789'
        w=self.call('withdraw',wd)[1]
        self.assertEqual(self.call('admin/withdrawal',{'id':w['id'],'action':'paid'},999)[0],200)
        self.assertEqual(self.call('state')[1]['balance'],1500)

    def test_validation_and_static(self):
        self.assertEqual(self.call('submit',{'proof':'javascript:alert(1)','request_id':'submission-123456789'})[0],400)
        self.assertEqual(self.call('withdraw',{'amount':-100,'destination':'test account','request_id':'withdraw-123456789'})[0],400)
        self.assertEqual(self.call('withdraw',{'amount':1.5,'destination':'test account','request_id':'withdraw-123456789'})[0],400)
        for path in ('/','/app.js','/style.css','/health'):
            with urllib.request.urlopen(self.base+path) as r:self.assertEqual(r.status,200)

if __name__=='__main__':unittest.main()
