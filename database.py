"""Use PostgreSQL when DATABASE_URL is configured; retain SQLite for local development."""
import os
import re
import sqlite3


class Row(dict):
    def __getitem__(self, key):
        return tuple(self.values())[key] if isinstance(key, int) else super().__getitem__(key)


class Cursor:
    def __init__(self, cursor, generated=False):
        self.cursor = cursor
        self.rowcount = cursor.rowcount
        self.lastrowid = cursor.fetchone()[0] if generated else None

    def convert(self, row):
        if row is None: return None
        return Row(zip((col.name for col in self.cursor.description), row))

    def fetchone(self): return self.convert(self.cursor.fetchone())
    def fetchall(self): return [self.convert(row) for row in self.cursor.fetchall()]
    def __iter__(self): return iter(self.fetchall())


class Postgres:
    row_factory = None

    def __init__(self, url):
        import psycopg
        # TLS certificate and hostname verification stay enabled.
        self.connection = psycopg.connect(url, sslmode='verify-full', sslrootcert=os.environ.get('PGSSLROOTCERT', 'system'), connect_timeout=15)

    def execute(self, sql, params=()):
        if sql.startswith('PRAGMA '): return None
        ignore = 'INSERT OR IGNORE INTO' in sql
        sql = sql.replace('INSERT OR IGNORE INTO', 'INSERT INTO').replace('?', '%s')
        if ignore: sql += ' ON CONFLICT DO NOTHING'
        generated = bool(re.match(r'INSERT INTO (submissions|withdrawals)\(', sql))
        if generated: sql += ' RETURNING id'
        return Cursor(self.connection.execute(sql, params), generated)

    def executescript(self, script):
        self.connection.execute('SELECT pg_advisory_xact_lock(73184621)')
        script = script.replace('INTEGER PRIMARY KEY', 'BIGSERIAL PRIMARY KEY').replace('INTEGER', 'BIGINT')
        for statement in script.split(';'):
            if statement.strip(): self.connection.execute(statement)

    def close(self): self.connection.close()
    def __enter__(self): return self
    def __exit__(self, kind, value, traceback):
        try:
            if kind is None: self.connection.commit()
            else: self.connection.rollback()
        finally: self.connection.close()


def connect(sqlite_path):
    url = os.environ.get('DATABASE_URL','')
    if url: return Postgres(url)
    os.makedirs(os.path.dirname(os.path.abspath(sqlite_path)), exist_ok=True)
    return sqlite3.connect(sqlite_path)


class PollingLease:
    """One polling owner per database/token; requires a session pooler."""
    def __init__(self, sqlite_path, token):
        import hashlib
        self.key = int.from_bytes(hashlib.sha256(token.encode()).digest()[:8], 'big', signed=True)
        self.path = sqlite_path + '.polling.lock'
        self.pg = None
        self.file = None

    def __enter__(self):
        import time
        if os.environ.get('DATABASE_URL'):
            self.pg = Postgres(os.environ['DATABASE_URL']).connection
            self.pg.autocommit = True
            while not self.pg.execute('SELECT pg_try_advisory_lock(%s)', (self.key,)).fetchone()[0]:
                print('Diğer bot sürecinin kapanması bekleniyor.', flush=True)
                time.sleep(5)
        else:
            import fcntl
            os.makedirs(os.path.dirname(os.path.abspath(self.path)), exist_ok=True)
            self.file = open(self.path, 'a')
            fcntl.flock(self.file.fileno(), fcntl.LOCK_EX)
        return self

    def check(self):
        if self.pg:
            self.pg.execute('SELECT 1').fetchone()

    def __exit__(self, *_):
        if self.pg:
            self.pg.close()
        if self.file:
            self.file.close()
