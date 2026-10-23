"""Telegram Mini App HTTP service, designed to sit behind an HTTPS reverse proxy."""
import hashlib
import hmac
import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qsl, urlsplit
import bot


def authenticate(raw):
    pairs = parse_qsl(raw, keep_blank_values=True, strict_parsing=True)
    data = dict(pairs)
    if len(data) != len(pairs):
        raise ValueError('Geçersiz oturum')
    signature = data.pop('hash', '')
    secret = hmac.new(b'WebAppData', os.environ['TELEGRAM_BOT_TOKEN'].encode(), hashlib.sha256).digest()
    expected = hmac.new(secret, '\n'.join(f'{k}={v}' for k,v in sorted(data.items())).encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(signature, expected):
        raise ValueError('Telegram kimliği doğrulanamadı')
    age = time.time() - int(data.get('auth_date', 0))
    if not -30 <= age <= 3600:
        raise ValueError('Oturum süresi doldu. Uygulamayı yeniden açın.')
    user = json.loads(data['user'])
    if type(user.get('id')) is not int or user['id'] <= 0:
        raise ValueError('Geçersiz kullanıcı')
    return user


def admins():
    return {int(x.strip()) for x in os.environ.get('TELEGRAM_ADMIN_IDS','').split(',') if x.strip()}


def connect():
    con = bot.connect()
    con.row_factory = __import__('sqlite3').Row
    con.executescript('''
    CREATE TABLE IF NOT EXISTS withdrawals (
      id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
      amount INTEGER NOT NULL CHECK(amount>0), destination TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending', request_id TEXT NOT NULL,
      UNIQUE(user_id,request_id));
    CREATE TABLE IF NOT EXISTS credits (
      request_id TEXT PRIMARY KEY, admin_id INTEGER NOT NULL, user_id INTEGER NOT NULL REFERENCES users(id),
      amount INTEGER NOT NULL, created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS web_submissions (
      user_id INTEGER NOT NULL, request_id TEXT NOT NULL, submission_id INTEGER NOT NULL,
      PRIMARY KEY(user_id,request_id));
    ''')
    return con


def amount(value):
    if type(value) is not int or not 1 <= value <= 100000000:
        raise ValueError('Tutar 0 ile 1.000.000 TL arasında olmalı.')
    return value


def request_id(body):
    value = body.get('request_id','')
    if not isinstance(value,str) or not 16 <= len(value) <= 100:
        raise ValueError('İşlem kimliği eksik')
    return value


def dispatch(path, user, body):
    uid = user['id']
    admin = uid in admins()
    with connect() as con:
        con.execute('INSERT OR IGNORE INTO users(id) VALUES (?)',(uid,))
        if path == '/api/state':
            result = {'name':user.get('first_name','Kullanıcı'), 'admin':admin,
                      'balance':con.execute('SELECT balance FROM users WHERE id=?',(uid,)).fetchone()[0]}
            for table in ('submissions','withdrawals'):
                fields = 'id,proof,status,amount' if table=='submissions' else 'id,amount,destination,status'
                result[table] = [dict(r) for r in con.execute(f'SELECT {fields} FROM {table} WHERE user_id=? ORDER BY id DESC LIMIT 100',(uid,))]
            if admin:
                result['pending_submissions'] = [dict(r) for r in con.execute("SELECT * FROM submissions WHERE status='pending' ORDER BY id LIMIT 100")]
                result['pending_withdrawals'] = [dict(r) for r in con.execute("SELECT * FROM withdrawals WHERE status='pending' ORDER BY id LIMIT 100")]
            return result
        if path == '/api/submit':
            proof = body.get('proof','')
            if not isinstance(proof,str) or len(proof)>1000 or any(c.isspace() for c in proof):
                raise ValueError('Geçerli HTTPS kanıt bağlantısı girin')
            parsed = urlsplit(proof)
            if parsed.scheme!='https' or not parsed.hostname or parsed.username or parsed.password:
                raise ValueError('Şifre içermeyen HTTPS kanıt bağlantısı girin')
            rid = request_id(body)
            old = con.execute('SELECT submission_id FROM web_submissions WHERE user_id=? AND request_id=?',(uid,rid)).fetchone()
            if old: return {'id':old[0]}
            sid = con.execute('INSERT INTO submissions(user_id,proof) VALUES (?,?)',(uid,proof)).lastrowid
            con.execute('INSERT INTO web_submissions VALUES (?,?,?)',(uid,rid,sid))
            return {'id':sid}
        if path == '/api/withdraw':
            cents = amount(body.get('amount'))
            destination = body.get('destination','')
            if not isinstance(destination,str) or not 5 <= len(destination.strip()) <= 200:
                raise ValueError('Ödeme alıcısı ve IBAN bilgisini girin')
            rid = request_id(body)
            old = con.execute('SELECT id FROM withdrawals WHERE user_id=? AND request_id=?',(uid,rid)).fetchone()
            if old: return {'id':old[0]}
            # Atomic balance reservation prevents overlapping requests spending the same funds.
            if not con.execute('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?',(cents,uid,cents)).rowcount:
                raise ValueError('Yetersiz bakiye')
            wid = con.execute('INSERT INTO withdrawals(user_id,amount,destination,request_id) VALUES (?,?,?,?)',(uid,cents,destination.strip(),rid)).lastrowid
            return {'id':wid}
        if path.startswith('/api/admin/'):
            if not admin: raise PermissionError('Admin yetkisi gerekli')
            if path == '/api/admin/credit':
                cents = amount(body.get('amount'))
                target = body.get('user_id')
                if type(target) is not int or target<=0: raise ValueError('Sayısal kullanıcı kimliği gerekli')
                rid = request_id(body)
                if con.execute('SELECT 1 FROM credits WHERE request_id=?',(rid,)).fetchone(): return {'ok':True}
                con.execute('INSERT OR IGNORE INTO users(id) VALUES (?)',(target,))
                con.execute('INSERT INTO credits VALUES (?,?,?,?,?)',(rid,uid,target,cents,int(time.time())))
                con.execute('UPDATE users SET balance=balance+? WHERE id=?',(cents,target))
                return {'ok':True}
            sid = body.get('id')
            if type(sid) is not int: raise ValueError('İşlem numarası gerekli')
            action = body.get('action')
            if path == '/api/admin/submission':
                if action not in ('approve','reject'): raise ValueError('Geçersiz işlem')
                cents = amount(body.get('amount')) if action=='approve' else 0
                row = con.execute("SELECT user_id FROM submissions WHERE id=? AND status='pending'",(sid,)).fetchone()
                if not row: raise ValueError('Başvuru zaten işlendi veya bulunamadı')
                if not con.execute("UPDATE submissions SET status=?,amount=? WHERE id=? AND status='pending'",('approved' if action=='approve' else 'rejected',cents,sid)).rowcount:
                    raise ValueError('Başvuru zaten işlendi')
                if cents: con.execute('UPDATE users SET balance=balance+? WHERE id=?',(cents,row[0]))
                return {'ok':True}
            if path == '/api/admin/withdrawal':
                if action not in ('paid','reject'): raise ValueError('Geçersiz işlem')
                row = con.execute("SELECT * FROM withdrawals WHERE id=? AND status='pending'",(sid,)).fetchone()
                if not row: raise ValueError('Talep zaten işlendi veya bulunamadı')
                if not con.execute("UPDATE withdrawals SET status=? WHERE id=? AND status='pending'",('paid' if action=='paid' else 'rejected',sid)).rowcount:
                    raise ValueError('Talep zaten işlendi')
                if action=='reject': con.execute('UPDATE users SET balance=balance+? WHERE id=?',(row['amount'],row['user_id']))
                return {'ok':True}
        raise ValueError('İşlem bulunamadı')


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass  # No request/authentication data in logs.

    def reply(self, status, payload, content_type='application/json; charset=utf-8'):
        raw = payload if isinstance(payload,bytes) else json.dumps(payload,ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type',content_type)
        self.send_header('Content-Length',str(len(raw)))
        self.send_header('Cache-Control','no-store')
        self.send_header('X-Content-Type-Options','nosniff')
        self.send_header('Referrer-Policy','no-referrer')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self' https://telegram.org; style-src 'self'; connect-src 'self'; frame-ancestors https://web.telegram.org https://*.telegram.org")
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        path = self.path.split('?',1)[0]
        if path=='/health': return self.reply(200,{'ok':True})
        files={'/':('index.html','text/html; charset=utf-8'),'/app.js':('app.js','application/javascript'),'/style.css':('style.css','text/css')}
        if path not in files: return self.reply(404,{'error':'Bulunamadı'})
        name, mime = files[path]
        self.reply(200,(Path(__file__).parent/'static'/name).read_bytes(),mime)

    def do_POST(self):
        try:
            user = authenticate(self.headers.get('Authorization','').removeprefix('tma '))
        except (ValueError,KeyError,TypeError,json.JSONDecodeError):
            return self.reply(401,{'error':'Telegram üzerinden açın veya uygulamayı yeniden başlatın.'})
        try:
            size = int(self.headers.get('Content-Length','0'))
            if not 0 < size <= 8192: raise ValueError('Geçersiz istek boyutu')
            body = json.loads(self.rfile.read(size))
            if not isinstance(body,dict): raise ValueError('Geçersiz istek')
            self.reply(200,dispatch(self.path,user,body))
        except PermissionError as exc: self.reply(403,{'error':str(exc)})
        except (ValueError,TypeError,KeyError): self.reply(400,{'error':'İşlem yapılamadı. Alanları, bakiyeyi ve işlem durumunu kontrol edin.'})
        except Exception: self.reply(500,{'error':'Sunucu hatası. Lütfen tekrar deneyin.'})


if __name__=='__main__':
    if not os.environ.get('TELEGRAM_BOT_TOKEN') or not admins():
        raise SystemExit('Token ve admin kimlikleri gerekli')
    connect().close()
    server = ThreadingHTTPServer((os.environ.get('WEB_HOST','127.0.0.1'),int(os.environ.get('PORT','8080'))),Handler)
    server.daemon_threads=True
    print('Mini App sunucusu hazır.',flush=True)
    server.serve_forever()
