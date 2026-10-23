"""Telegram task/reward bot. Python 3.12+, standard library only."""
import json
import os
import time
import urllib.request
import urllib.error
from urllib.parse import urlsplit
import database

DB = os.environ.get('BOT_DB', '/workspace/telegram-data/bot.sqlite3')


def connect():
    con = database.connect(DB)
    con.execute('PRAGMA foreign_keys=ON')
    con.executescript('''
    CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, balance INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS submissions (
      id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
      proof TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', amount INTEGER NOT NULL DEFAULT 0);
    ''')
    return con


def submit(user_id, proof):
    with connect() as con:
        con.execute('INSERT OR IGNORE INTO users(id) VALUES (?)', (user_id,))
        return con.execute('INSERT INTO submissions(user_id, proof) VALUES (?, ?)', (user_id, proof)).lastrowid


def decide(submission_id, approve, amount):
    if amount < 0 or (approve and amount == 0):
        raise ValueError('Invalid amount')
    with connect() as con:
        row = con.execute('SELECT user_id FROM submissions WHERE id=?', (submission_id,)).fetchone()
        if not row:
            return None
        changed = con.execute("UPDATE submissions SET status=?, amount=? WHERE id=? AND status='pending'",
                              ('approved' if approve else 'rejected', amount if approve else 0, submission_id)).rowcount
        if not changed:
            return None
        if approve:
            con.execute('UPDATE users SET balance=balance+? WHERE id=?', (amount, row[0]))
        return row[0]


def api(method, **payload):
    req = urllib.request.Request(f'https://api.telegram.org/bot{TOKEN}/{method}',
                                 data=json.dumps(payload).encode(), headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=40) as response:
        result = json.load(response)
    if not result.get('ok'):
        raise RuntimeError('Telegram request failed')
    return result['result']


def send(chat, text):
    return api('sendMessage', chat_id=chat, text=text)


def mini_app_url():
    for name in ('MINI_APP_URL', 'RENDER_EXTERNAL_URL'):
        value = os.environ.get(name, '').strip()
        try:
            parsed = urlsplit(value)
            if parsed.scheme == 'https' and parsed.hostname and not parsed.username and not parsed.password:
                return value
        except ValueError:
            continue
    return ''


def handle(update):
    msg = update.get('message', {})
    if msg.get('chat', {}).get('type') != 'private':
        return
    user = msg.get('from', {})
    uid = user.get('id')
    text = msg.get('text', '').strip()
    if not uid or not text:
        return
    command, _, rest = text.partition(' ')
    if command == '/start':
        app_url = mini_app_url()
        if app_url:
            api('sendMessage', chat_id=uid, text='Görevlerini ve bakiyeni uygulamadan yönet.',
                reply_markup={'inline_keyboard': [[{'text': 'Uygulamayı aç', 'web_app': {'url': app_url}}]]})
        send(uid, 'Görev başvurusu: /basvuru ardından şifre içermeyen kanıt bağlantısını yazın.\nBakiyeniz: /bakiye\nGmail şifresi veya başka erişim bilgisi göndermeyin.')
    elif command == '/bakiye':
        with connect() as con:
            row = con.execute('SELECT balance FROM users WHERE id=?', (uid,)).fetchone()
        send(uid, f'Bakiyeniz: {(row[0] if row else 0) / 100:.2f} TL')
    elif command == '/basvuru':
        if not rest.startswith('https://') or len(rest) > 1000 or any(c.isspace() for c in rest):
            send(uid, 'Tek bir HTTPS kanıt bağlantısı gönderin: /basvuru https://…\nŞifre göndermeyin.')
            return
        sid = submit(uid, rest)
        send(uid, f'Başvurunuz alındı. Numara: {sid}')
        for admin in ADMINS:
            send(admin, f'Yeni başvuru #{sid}\nKullanıcı: {uid}\nKanıt: {rest}\nBekleyenler: /bekleyen')
    elif uid in ADMINS and command == '/bekleyen':
        with connect() as con:
            rows = con.execute("SELECT id,user_id,proof FROM submissions WHERE status='pending' ORDER BY id LIMIT 20").fetchall()
        send(uid, '\n\n'.join(f'#{sid} kullanıcı {u}\n{p}' for sid,u,p in rows) or 'Bekleyen başvuru yok.')
        send(uid, 'Onay: /onay BASVURU_NO KURUS\nÖrnek: /onay 1 2500 → 25 TL\nRet: /ret BASVURU_NO')
    elif uid in ADMINS and command in ('/onay', '/ret'):
        try:
            parts = rest.split()
            if len(parts) != (2 if command == '/onay' else 1):
                raise ValueError()
            sid = int(parts[0])
            amount = int(parts[1]) if command == '/onay' else 0
            target = decide(sid, command == '/onay', amount)
        except ValueError:
            send(uid, 'Biçim: /onay BASVURU_NO KURUS veya /ret BASVURU_NO')
            return
        send(uid, 'İşlem tamamlandı.' if target else 'Başvuru bulunamadı veya daha önce işlendi.')
        if target:
            send(target, f'Başvuru #{sid}: ' + (f'onaylandı. {amount / 100:.2f} TL bakiyenize eklendi.' if command == '/onay' else 'reddedildi.'))
    else:
        send(uid, 'Komutlar için /start yazın.')


def main():
    global TOKEN, ADMINS
    TOKEN = os.environ.get('TELEGRAM_BOT_TOKEN', '')
    ADMINS = {int(x.strip()) for x in os.environ.get('TELEGRAM_ADMIN_IDS', '').split(',') if x.strip()}
    if not TOKEN or not ADMINS:
        raise SystemExit('TELEGRAM_BOT_TOKEN ve TELEGRAM_ADMIN_IDS güvenli ortam ayarlarında tanımlanmalı.')
    with connect():
        pass
    api('getMe')
    with database.PollingLease(DB, TOKEN) as lease:
        offset = 0
        print('Bot başladı.', flush=True)
        while True:
            lease.check()  # Loss of the ownership connection exits polling.
            try:
                for update in api('getUpdates', offset=offset, timeout=25, allowed_updates=['message']):
                    handle(update)
                    offset = update['update_id'] + 1
            except urllib.error.HTTPError as exc:
                reason = 'bilinmiyor'
                if exc.code == 409:
                    try:
                        description = json.loads(exc.read(4096)).get('description', '')
                        if 'other getUpdates' in description:
                            reason = 'başka bir polling süreci etkin'
                        elif 'webhook' in description.lower():
                            reason = 'webhook etkin'
                    except (ValueError, OSError):
                        pass
                print(f'Telegram HTTP durum: {exc.code}; neden: {reason}', flush=True)
                time.sleep(3)
            except Exception as exc:
                # Never log HTTP exception URLs: Telegram embeds the token in the URL.
                print(f'İşlem hatası: {type(exc).__name__}', flush=True)
                time.sleep(3)


if __name__ == '__main__':
    main()
