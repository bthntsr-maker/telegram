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

    def test_non_admin_cannot_credit(self):
        sid = bot.submit(123, 'https://example.com/proof')
        with patch.object(bot, 'ADMINS', {999}, create=True), patch.object(bot, 'send'):
            bot.handle({'message': {'chat': {'type': 'private'}, 'from': {'id': 123}, 'text': f'/onay {sid} 2500'}})
        with bot.connect() as con:
            self.assertEqual(con.execute('SELECT balance FROM users WHERE id=123').fetchone()[0], 0)

if __name__ == '__main__':
    unittest.main()
