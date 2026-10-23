import os
import tempfile
import unittest
from unittest.mock import patch
import bot

class ApprovalTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.patch = patch.object(bot, 'DB', os.path.join(self.tmp.name, 'db.sqlite3'))
        self.patch.start()

    def tearDown(self):
        self.patch.stop()
        self.tmp.cleanup()

    def test_approval_credits_only_once(self):
        sid = bot.submit(123, 'https://example.com/proof')
        self.assertEqual(bot.decide(sid, True, 2500), 123)
        self.assertIsNone(bot.decide(sid, True, 2500))
        with bot.connect() as con:
            self.assertEqual(con.execute('SELECT balance FROM users WHERE id=123').fetchone()[0], 2500)

    def test_rejection_cannot_be_approved(self):
        sid = bot.submit(123, 'https://example.com/proof')
        self.assertEqual(bot.decide(sid, False, 0), 123)
        self.assertIsNone(bot.decide(sid, True, 2500))
        with bot.connect() as con:
            self.assertEqual(con.execute('SELECT balance FROM users WHERE id=123').fetchone()[0], 0)

    def test_render_url_shows_start_button_without_manual_url(self):
        with patch.dict(os.environ, {'MINI_APP_URL': '', 'RENDER_EXTERNAL_URL': 'https://telegram-gorev.onrender.com'}), patch.object(bot, 'api') as api, patch.object(bot, 'send'):
            bot.handle({'message': {'chat': {'type': 'private'}, 'from': {'id': 123}, 'text': '/start'}})
            button = api.call_args.kwargs['reply_markup']['inline_keyboard'][0][0]
            self.assertEqual(button['web_app']['url'], 'https://telegram-gorev.onrender.com')

    def test_invalid_manual_url_uses_render_url(self):
        with patch.dict(os.environ, {'MINI_APP_URL': 'http://invalid.example', 'RENDER_EXTERNAL_URL': 'https://telegram-gorev.onrender.com'}):
            self.assertEqual(bot.mini_app_url(), 'https://telegram-gorev.onrender.com')

    def test_non_admin_cannot_credit(self):
        sid = bot.submit(123, 'https://example.com/proof')
        with patch.object(bot, 'ADMINS', {999}, create=True), patch.object(bot, 'send'):
            bot.handle({'message': {'chat': {'type': 'private'}, 'from': {'id': 123}, 'text': f'/onay {sid} 2500'}})
        with bot.connect() as con:
            self.assertEqual(con.execute('SELECT balance FROM users WHERE id=123').fetchone()[0], 0)

if __name__ == '__main__':
    unittest.main()
