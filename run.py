"""Run the polling bot and HTTP service together; fail if either child exits."""
import os
from pathlib import Path
import signal
import subprocess
import sys
import time


def main():
    if not os.environ.get('TELEGRAM_BOT_TOKEN') or not os.environ.get('TELEGRAM_ADMIN_IDS'):
        raise SystemExit('TELEGRAM_BOT_TOKEN ve TELEGRAM_ADMIN_IDS gerekli.')
    os.umask(0o077)
    children = []
    stopping = False

    def stop(*_):
        nonlocal stopping
        stopping = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    root = Path(__file__).resolve().parent
    result = 0
    try:
        for script in ('web.py', 'bot.py'):
            children.append(subprocess.Popen([sys.executable, '-u', str(root / script)], cwd=root))
        while not stopping:
            if any(child.poll() is not None for child in children):
                result = 1
                break
            time.sleep(0.5)
    finally:
        for child in children:
            if child.poll() is None:
                child.terminate()
        for child in children:
            try:
                child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait()
    return result


if __name__ == '__main__':
    sys.exit(main())
