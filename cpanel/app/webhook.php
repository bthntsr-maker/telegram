<?php
declare(strict_types=1);
require __DIR__.'/common.php';
try {
    if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals(config()['webhook_secret'],$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))jsonResponse(403,['ok'=>false]);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)jsonResponse(413,['ok'=>false]);
    $u=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if(!is_int($u['update_id']??null))jsonResponse(400,['ok'=>false]);
    db()->beginTransaction();
    if(!query('INSERT IGNORE INTO telegram_updates(id) VALUES (?)',[$u['update_id']])->rowCount()){db()->commit();jsonResponse(200,['ok'=>true]);}
    $m=$u['message']??[];$uid=$m['from']['id']??null;
    if(($m['chat']['type']??'')==='private'&&is_int($uid)&&$uid>0) {
        ensureUser($uid);$text=trim($m['text']??'');$command=explode(' ',$text)[0];
        if($command==='/bakiye') {
            $balance=(int)query('SELECT balance FROM users WHERE id=?',[$uid])->fetchColumn();
            telegram('sendMessage',['chat_id'=>$uid,'text'=>'Bakiyeniz: '.number_format($balance/100,2,',','.').' TL']);
        } elseif($command==='/start') {
            $caption="👋 Hoş geldin!\n\n📩 Gmail başvurunu uygulamadan gönder.\n✅ Başvurun onaylandığında kazancın TL bakiyene eklensin.\n💸 Ödeme talebini USDT · TRC20 olarak oluştur.\n\nBaşvurularını, bakiyeni ve ödeme durumunu tek ekrandan takip et.\n\n👇 Başlamak için uygulamayı aç.";
            $markup=['inline_keyboard'=>[[['text'=>'🚀 Uygulamayı aç','web_app'=>['url'=>config()['app_url']]]]]];
            try {
                telegram('sendPhoto',['chat_id'=>$uid,'photo'=>rtrim(config()['app_url'],'/').'/welcome.png','caption'=>$caption,'reply_markup'=>$markup]);
            } catch(RuntimeException) {
                // If the host cannot serve the banner, keep the bot usable.
                telegram('sendMessage',['chat_id'=>$uid,'text'=>$caption,'reply_markup'=>$markup]);
            }
        } elseif($text!=='') {
            telegram('sendMessage',['chat_id'=>$uid,'text'=>'Başvurularını ve bakiyeni uygulamadan yönetebilirsin.', 'reply_markup'=>['inline_keyboard'=>[[['text'=>'🚀 Uygulamayı aç','web_app'=>['url'=>config()['app_url']]]]]]]);
        }
    }
    db()->commit();jsonResponse(200,['ok'=>true]);
}catch(Throwable){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable){}jsonResponse(500,['ok'=>false]);}
