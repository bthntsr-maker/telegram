# Telegram görev ve bakiye botu

Python 3.12+; harici bağımlılık gerektirmez. İlk sürüm Telegram özel mesajları üzerinden çalışır.

## Başlatma

BotFather ile bot oluşturun. Tokenı güvenli ortam ayarlarına `TELEGRAM_BOT_TOKEN` olarak ekleyin; kaynak koda veya sohbete koymayın.
`TELEGRAM_ADMIN_IDS`: virgülle ayrılmış sayısal Telegram kullanıcı kimlikleri.
İsteğe bağlı `BOT_DB`: SQLite dosyasının yolu (varsayılan `/workspace/telegram-data/bot.sqlite3`). Veritabanını yedekleyin.

```sh
cd /workspace/telegram
python bot.py
```

Bot uzun sorgulama kullanır; aynı token için tek süreç çalıştırın ve webhook kullanmayın.

Kullanıcı: `/start`, `/basvuru https://kanıt-adresi`, `/bakiye`.
Admin: `/bekleyen`, `/onay BASVURU_NO KURUS`, `/ret BASVURU_NO`.
Onay tutarı kuruştur: 2500 = 25 TL. Her başvuru yalnızca bir kere işlenir.
Şifre veya hesap erişim bilgisi gönderilmemelidir.

## Doğrulama

```sh
python -m unittest discover -s tests -v
```

Mini App, admin paneli ve ödeme talepleri aşağıda açıklanmıştır. Gerçek para transferi otomatik yapılmaz; bakiye uygulama içi kayıttır.
Canlı Telegram doğrulaması için token ve admin kimlikleri gereklidir. Harici kanıt bağlantıları otomatik olarak açılmaz/doğrulanmaz.

## Telegram Mini App

`python web.py` Mini App'i başlatır (varsayılan: 127.0.0.1:8080).
Statik ekran ve API aynı sunucudan sunulur; harici Python bağımlılığı yoktur.
Üretimde HTTPS reverse proxy kullanın; container barındırma için `WEB_HOST=0.0.0.0` ve hizmetin verdiği `PORT` kullanılabilir.
Tokenı veya admin kimliğini istemci dosyalarına koymayın.

HTTPS adresiniz hazır olduğunda güvenli ortam ayarlarına `MINI_APP_URL=https://…` ekleyin ve botu yeniden başlatın.
Kullanıcı `/start` yazınca “Uygulamayı aç” düğmesini görür. Canlı Telegram kimlik doğrulaması için gerçek HTTPS adresi ve Telegram içinden açılış gerekir; normal tarayıcıda yalnızca oturum açılmamış ekran görünür.
Bot ve web servisi aynı SQLite dosyasını kullanmalıdır. İki ayrı işlem olarak, tek kalıcı diskte çalıştırın. Birden fazla replika veya ayrı makineler için paylaşılan bir veritabanına geçilmesi gerekir.

Sunucu Telegram initData imzasını ve bir saatlik oturum yaşını doğrular. Yönetici yetkisi her API isteğinde kontrol edilir.
Para tutarları tamsayı kuruş olarak tutulur. Ödeme talepleri bakiyeden rezerve edilir; ret halinde bir kez iade edilir.
“Ödendi” yalnızca yöneticinin dışarıdan yaptığı transferi kaydeder; banka entegrasyonu yoktur.
Bakiye ekleme ve kullanıcı formları işlem kimliğiyle tekrar gönderime karşı korunur.
Başvuru/ödeme geçmişinin en son 100 kaydı ve ilk 100 bekleyen işlem gösterilir.
Üretim öncesi HTTPS, otomatik süreç yeniden başlatma, kalıcı disk ve düzenli şifreli veritabanı yedekleri ayarlayın. SQLite dosyası ödeme alıcısı/IBAN içerir; erişimini sınırlayın.

## Render kurulumu

`render.yaml` tek ücretli web servisi ve 1 GB kalıcı disk tanımlar. Güncel fiyatı Render'da kontrol edin; Blueprint oluşturulması ücret doğurabilir.

1. Render Dashboard → New → Blueprint ile `bthntsr-maker/telegram` deposunu bağlayın.
2. `TELEGRAM_BOT_TOKEN` ve `TELEGRAM_ADMIN_IDS` değerlerini Render'ın güvenli alanlarına girin.
3. Planı, diski ve fiyatı kontrol edip dağıtımı başlatın. `run.py` web ve bot süreçlerini birlikte yönetir.
4. Render'ın verdiği HTTPS adresini servisin Environment bölümünde `MINI_APP_URL` olarak ekleyip servisi yeniden dağıtın.
5. Aynı tokenı kullanan geliştirme botunu durdurun; yalnızca Render polling yapsın. BotFather'da webhook/başka bir dağıtım varsa önce inceleyin.
6. Telegram'da `/start` yazıp Uygulamayı aç düğmesine basın. Başvuru, admin onayı ve bakiye güncellemesini gerçek Telegram oturumunda doğrulayın.

Kalıcı disk zorunludur; ücretsiz/kalıcı disksiz servis bakiye verilerini güvenle tutmaz. Tek replika kullanın.
Bu yapılandırma mevcut geliştirme verilerini otomatik taşımaz. Render boş veritabanıyla başlar; mevcut bakiye/ödeme kaydı varsa geçişten önce güvenli SQLite yedeği alın ve taşıyın.
