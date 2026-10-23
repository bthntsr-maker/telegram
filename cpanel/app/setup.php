<?php
declare(strict_types=1);
require __DIR__.'/common.php';
header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');header('X-Frame-Options: DENY');
$message='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!hash_equals(config()['setup_key'],$_POST['key']??''))throw new RuntimeException('Invalid key');
        initialize();
        if(($_POST['action']??'')==='activate') {
            telegram('setWebhook',['url'=>rtrim(config()['app_url'],'/').'/webhook.php','secret_token'=>config()['webhook_secret'],'allowed_updates'=>['message'],'drop_pending_updates'=>false]);
            $message='Webhook etkinleştirildi. Telegram’da /start yazarak deneyin. Şimdi setup.php dosyasını silin.';
        } else {
            query('SELECT 1');telegram('getMe',[]);
            $message='MySQL ve Telegram bağlantısı başarılı. Tablolar hazır. Webhook henüz etkinleştirilmedi.';
        }
    }catch(Throwable){$message='İşlem tamamlanamadı. Kurulum anahtarını, PHP eklentilerini ve özel yapılandırma dosyasını kontrol edin.';}
}
?>
<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Uygulama kurulumu</title><link rel="stylesheet" href="style.css"><main><h1>Uygulama kurulumu</h1><p><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><p>Önce bağlantıyı test edin. Mevcut verileri aktarıp Render botunu durdurmadan webhook’u etkinleştirmeyin.</p><form method="post"><label>Özel dosyadaki kurulum anahtarı<input type="password" name="key" required autocomplete="off"></label><button name="action" value="check">Bağlantıyı test et ve tabloları oluştur</button><button class="danger" name="action" value="activate">Taşıma tamamlandı: webhook’u etkinleştir</button></form></main></html>
