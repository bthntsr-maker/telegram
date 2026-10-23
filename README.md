# Telegram görev ve bakiye botu

Python 3.12+. PostgreSQL için `pip install -r requirements.txt` çalıştırın. İlk sürüm Telegram özel mesajları üzerinden çalışır.

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
Statik ekran ve API aynı sunucudan sunulur. PostgreSQL için psycopg bağımlılığı kullanılır.
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

## Render ücretsiz deneme kurulumu

`render.yaml` ücretsiz (`plan: free`) web servisi tanımlar. Ücretli disk talep etmez.
Veritabanı `/tmp/telegram-data/bot.sqlite3` altında geçicidir; yeniden dağıtım/yeniden başlatma sırasında kayıtlar silinebilir. Gerçek para bakiyeleri için kullanmayın. Uygulama deneme uyarısı gösterir.

1. Render Dashboard → New → Blueprint ile `bthntsr-maker/telegram` deposunu ve `main` dalını bağlayın.
2. Planın **Free** olduğunu kontrol edin. Daha önce ücretli olarak oluşturulmuş servisin otomatik olarak ücretsiz hale geldiğini varsaymayın. Var olan bir serviste değişiklikten önce Render planını ayrıca kontrol edin.
3. `TELEGRAM_BOT_TOKEN` ve `TELEGRAM_ADMIN_IDS` değerlerini Render güvenli ortam alanlarına girin.
4. Dağıtım tamamlandığında verilen HTTPS adresini Environment bölümünde `MINI_APP_URL` olarak ekleyip servisi yeniden dağıtın.
5. Aynı tokenla başka bir polling botu çalıştırmayın. Telegram'da `/start` yazıp Uygulamayı aç düğmesini deneyin.

Ücretsiz servis boşta kalınca uyuyabilir. Telegram polling tek başına servisi uyanık tutmaz; uyuyan bot mesaja yanıt vermeyebilir. Önce Render'ın HTTPS adresini tarayıcıda açıp servisin uyanmasını bekleyin, ardından Telegram'a dönün. Sürekli erişim garanti edilmez.
Ödeme talepleri yalnızca deneme içindir; gerçek para transferi otomatik yapılmaz.
Kalıcı gerçek kullanım için ileride güvenilir kalıcı veritabanı ve sürekli çalışan barındırma gerekir.

Render üzerinde bot uygulama adresini otomatik `RENDER_EXTERNAL_URL` değişkeninden alır. İsteğe bağlı `MINI_APP_URL` geçerli bir HTTPS adresiyse önceliklidir. Güncellemeden sonra Telegram özel sohbetinde yeniden `/start` gönderin.

## Ücretsiz kalıcı PostgreSQL

`DATABASE_URL` ayarlanırsa tüm bot ve Mini App kayıtları PostgreSQL'e yazılır; ayarlanmazsa mevcut SQLite davranışı devam eder. Render Docker kurulumu bağımlılığı otomatik yükler.

Supabase'te ücretsiz proje oluşturun. Connect bölümünde PostgreSQL **Session pooler** bağlantısını seçin (IPv4 destekli; genellikle 5432 portu). Render → Environment içinde `DATABASE_URL` anahtarına gerçek bağlantı dizesini güvenli olarak kaydedin. Şifreyi sohbete, GitHub'a veya ekran görüntüsüne koymayın; özel karakterler URL biçiminde kodlanmalıdır. Transaction pooler yerine session pooler kullanın.

Üretim bağlantısında TLS sertifikası ve sunucu adı doğrulanır (`verify-full`). Sistem kök sertifikaları varsayılandır. Proje sertifikası gerekirse resmi Supabase proje ayarlarından indirilmiş CA sertifikasını Render Secret File olarak ekleyip `PGSSLROOTCERT` değişkenini dosya yoluna ayarlayın. Doğrulamayı kapatmayın.

Kaydetme sonrası Render yeniden dağıtılır ve tablolar otomatik oluşur. Yeni veritabanı boş başlar; önceki SQLite deneme kayıtları otomatik taşınmaz. Render'daki mevcut kayıtlar gerekiyorsa yeniden dağıtımdan önce yedeklenmelidir.

Bağlantı sonrası uygulamada yeni test başvurusu onaylayın, servisi yeniden dağıtın ve bakiyenin korunduğunu kontrol edin. Supabase hesabı/bağlantısı sağlanmadan canlı kalıcılık doğrulanmış sayılmaz.
Ücretsiz Supabase kotası ve hareketsizlik politikaları geçerlidir; Render da uyuyabilir. Kalıcı veritabanı tek başına yedek veya 7/24 erişim garantisi sağlamaz. Gerçek paradan önce düzenli dış yedek ve geri yükleme denemesi gerekir.
