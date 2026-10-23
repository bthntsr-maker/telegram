<?php
// Rename to /home/botgmail/bot-config.php (OUTSIDE public_html).
return [
    'db_host' => 'localhost',
    'db_name' => 'botgmail_bot',
    'db_user' => 'botgmail_botuser',
    'db_password' => 'REPLACE_DATABASE_PASSWORD',
    'bot_token' => 'REPLACE_BOT_TOKEN',
    'admin_ids' => [123456789],
    'app_url' => 'https://botgmail.xyz/app/',
    // Generate independent random values (at least 32 characters) for these two keys.
    'webhook_secret' => 'REPLACE_RANDOM_WEBHOOK_SECRET',
    'setup_key' => 'REPLACE_RANDOM_SETUP_KEY',
];
