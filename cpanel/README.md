# cPanel geçişi — PHP 8.2+, PDO MySQL ve cURL

Mevcut public_html/index.php ve php.ini dosyalarına dokunmayın.

1. `cpanel-upload.zip` dosyasını public_html içine yükleyip çıkarın. Yalnızca app/ dizini eklenir. Yükleme sonrası ZIP'i silin.
2. `private/bot-config.example.php` örneğini kullanarak `/home/botgmail/bot-config.php` oluşturun (public_html DIŞINDA). DB kullanıcı/veritabanı adlarını cPanel'in tam ön ekli adlarıyla yazın. Gerçek DB şifresi, güncel bot tokenı ve sayısal admin kimliğini sadece özel dosyaya girin. Kurulum ve webhook anahtarları için ayrı, en az 32 karakter rastgele değer üretin. Örnek dosyayı gerçek bilgilerle GitHub'a göndermeyin. Özel dosyaya mümkünse 0600 izni verin.
3. PHP 8.2+ seçin; pdo_mysql, curl, json ve hash etkin olmalı. https://botgmail.xyz/app/setup.php sayfasında özel setup_key ile yalnızca bağlantı testi yapın. MySQL tabloları oluşturulur; bot henüz taşınmaz.
4. Render sürümündeki Mini App → Yönetim → Yedeği indir ile mevcut kayıtları alın. Yedek IBAN ve kullanıcı kayıtları içerir: şifreli/güvenli saklayın, public_html'e yüklemeyin. Geçiş sırasında kullanıcı/admin işlemlerini durdurun; yedek alındıktan sonra yeni işlem olmamalı. Render servisini askıya alın (Suspend; silmeyin). Sonra setup.php ekranından webhook'u etkinleştirin. Bu işlem polling yerine HTTPS webhook kurar, bekleyen Telegram mesajlarını silmez.
5. Telegram /start düğmesi artık yeni /app/ adresini açar. Yönetim → Yedek aktarımı ile yedeği içe aktarın. Aktarım yalnızca yeni boş finansal tablolar ve sıfır bakiyeler varsa mümkündür; mevcut kayıtlarla birleştirmez. Kullanıcı kimlikleri, bakiyeler, başvurular, ödeme talepleri, önceki krediler ve tekrar gönderim kimlikleri korunur. İşlem tek transaction içinde yapılır; başarısızlıkta geri alınır. 2 MB üzerindeki yedekler için ayrı aktarım planı gerekir.
6. Bakiye, başvuru geçmişi ve bekleyen ödeme tutarlarını eski yedekle karşılaştırın. Yeni test başvurusu/onay ve ödeme reddi/iadesini doğrulayın. Bu doğrulamalar bitene kadar Supabase'i silmeyin. Hata halinde yeni sistemde işlem yapmayı durdurun; yeni kayıtlar oluştuysa eski veritabanına gelişigüzel geri dönmeyin.
7. setup.php dosyasını silin. Güncel MySQL veritabanını cPanel'den yedekleyip güvenli yere indirin. Hostingin yedek sıklığını ve geri yükleme kapsamını sağlayıcıyla teyit edin.

Anahtarlar şifre/token değildir ancak güçlü ve gizli tutulmalıdır. Gerçek tokenı sohbete, URL parametresine, GitHub'a veya ekran görüntüsüne koymayın. Yetkilendirme Telegram initData üzerinden sunucuda doğrulanır; admin paneli Telegram Mini App içinde çalışır. Bağımsız tarayıcı admin girişi bu pakette yoktur.

Yerel doğrulama: cpanel/tests/integration.php tek kullanımlık boş MySQL veritabanında çalıştırılmalıdır (test verilerini temizler!). BOT_CONFIG_FILE ile sadece test veritabanını tanımlayan özel bir PHP config dosyasının yolunu verin. Üretimde test dosyalarını yüklemeyin.

## Gmail başvuru alanı güncellemesi

`cpanel-gmail-update.zip` yalnızca app/api.php, app/app.js ve app/view.html içerir. public_html içine çıkarıp bu üç mevcut dosyanın üzerine yazın. Özel yapılandırma, şifreler ve MySQL verileri değişmez; setup.php geri eklenmez. Alan yalnızca Gmail e-posta adresi kabul eder; sunucu da aynı kontrolü uygular. Baş/son boşluklar temizlenir ve harfler küçültülür. Önceki bağlantı başvuruları geçmişte gösterilmeye devam eder. Bu kontrol adresin gerçekten var olduğunu veya kullanıcıya ait olduğunu doğrulamaz.

## Gmail + şifre ve USDT/TRC20 toplu güncellemesi

`cpanel-update.zip` public_html içine çıkarılır; yalnızca app/api.php, app/common.php, app/app.js, app/view.html ve app/style.css güncellenir. Kurulum dosyası veya özel config içermez. Yüklemeden önce MySQL yedeği alın. Var olan bakiyeler, başvurular ve ödeme talepleri korunur. Yeni iki yardımcı tablo ilk yetkili API isteğinde, finansal transaction başlamadan otomatik oluşturulur (DB kullanıcısında CREATE yetkisi gerekir).

Gmail + şifre formunda açık iletim onayı gereklidir. Şifreler AES-256-GCM ile, başvuru numarasına bağlı doğrulanmış şifreleme kullanılarak saklanır. Şifre normal state yanıtlarında veya kullanıcı geçmişinde bulunmaz. Yalnızca yetkili admin tek kayıt üzerinden görüntüleyebilir; görüntü 30 saniye sonra temizlenir. Önceki başvurularda şifre varsa varsayılmaz.

Şifreleme anahtarı ilk şifreli başvuruda `/home/botgmail/bot-encryption.key` dosyasında otomatik oluşur (0600 izin, public_html dışında). Bu dosya ve MySQL veritabanı birlikte güvenli olarak yedeklenmelidir; yalnızca SQL yedeği, anahtar kaybolursa şifreleri kurtarmaz. Anahtarı sohbete/GitHub'a göndermeyin, public_html'e koymayın, yeni bir anahtarla değiştirmeyin. Yerel testte BOT_KEY_FILE yalnızca geçici test yolu için kullanılabilir. OpenSSL PHP eklentisi gereklidir. Kaynak kod güncellemek bu anahtarı veya özel config'i değiştirmez.

Yeni ödeme talepleri yalnızca USDT/TRC20 kabul eder; adres biçimi, ağ öneki ve Base58Check sağlama toplamı sunucuda doğrulanır. Bu, adresin USDT alıcısı olduğunu, kullanıcıya ait olduğunu veya belirli bir borsanın desteklediğini kanıtlamaz. Önceki IBAN talepleri 'Eski ödeme talebi' olarak görünür ve kripto taleplerine otomatik dönüştürülmez.

Bakiyeler ve rezervasyon tutarları tamsayı TL kuruşudur. Kur otomatik alınmaz; admin ödeme anında USDT miktarını hesaplar. Yeni kripto talebini 'Ödendi' yapmak için pozitif USDT tutarı ve 64 hexadecimal karakterli TXID gerekir. Bunlar yapılan harici transferin kaydıdır; zincir sorgulaması veya otomatik transfer yoktur. Ret, ayrılmış TL tutarını yalnızca bir kez iade eder.

Aktarım aracı eski Render verilerinin ilk taşınması içindir. Güncel MySQL'e geçildikten sonra tüm şifreli/kripto kayıtları korumak için cPanel MySQL yedeği + anahtar dosyası kullanılmalıdır. Güncel, dolu veritabanına eski JSON yedeğini tekrar içe aktarmayın.

## Görselli /start mesajı

`cpanel-welcome-update.zip` yalnızca app/webhook.php ve app/welcome.png içerir. public_html içine çıkarıp dosyaların üzerine yazın. Veritabanı ve config değişmez; webhook yeniden kaydı gerekmez. Kullanıcı /start gönderince karşılama görseli, açıklaması ve Uygulamayı aç düğmesi gönderilir. Telegram görseli hostingin HTTPS adresinden alır; welcome.png herkes tarafından okunabilir 0644 izinli olmalıdır. Görsel gönderilemezse metin ve düğmeyle devam edilir. Kurulumdan sonra /start ile canlı doğrulama gerekir.

## Tarayıcı yönetim paneli: /admin

`cpanel-admin-update.zip` dosyasını public_html içine çıkarın. Yeni admin/ klasörü oluşur ve app/api.php paylaşılmış işlem kodunu kullanmak üzere güncellenir. public_html/index.php, özel config, şifreleme anahtarı, setup.php ve webhook değiştirilmez. Dosyalar 0644, klasörler 0755 olmalıdır. Mevcut MySQL verileri korunur.

https://botgmail.xyz/admin/ adresini normal tarayıcıdan açın. İlk açılışta özel bot-config.php dosyasındaki mevcut setup_key değerini girip yeni bir kullanıcı adı ve 12–72 karakter güçlü şifre belirleyin. Şifreyi sohbete göndermeyin. Hesap yalnızca bir kez oluşturulabilir; sonraki girişlerde yalnızca kullanıcı adı/şifre gerekir. Ayrı bir Telegram komutu, BotFather değişikliği veya yeni webhook gerekmez.

Şifre password_hash ile hash olarak `/home/botgmail/bot-admin-auth.json` dosyasına kaydedilir (0600, public_html dışında); düz metin saklanmaz. Hesap, config'teki ilk yetkili admin Telegram kimliğiyle mevcut işlem yetkilerini kullanır. Config'teki bu yetki kaldırılırsa tarayıcı erişimi de kapanır. Mevcut Mini App yönetimi etkilenmez.

Oturum Secure/HttpOnly/SameSite=Strict çereziyle tutulur. Her POST isteğinde CSRF kontrolü yapılır. Girişte oturum kimliği yenilenir; 30 dakika hareketsizlik veya 8 saat sonunda yeniden giriş gerekir (hosting oturum politikasına göre daha erken bitebilir). Her IP adresi için 15 dakikada 5 başarısız/başarısızlıkla sonuçlanabilecek giriş denemesinden sonra bekleme uygulanır. Yanlış giriş sayacı başarılı girişte temizlenir. Proxy arkasındaki gerçek IP yapılandırması hosting sağlayıcısına aittir; istemciden gelen X-Forwarded-For güvenilmez.

Panel başvuru onayı/ret, şifre görüntüleme, TL bakiye ekleme, TRC20 ödeme kayıtları ve kullanıcı listesi içerir. Aramalar ekranda yüklenmiş son 100 kayıt üzerinde çalışır. Giriş ve gerçek finansal akışlar yerel, atılabilir MySQL/PHP ortamında test edilmiştir; canlı hosting doğrulaması yüklemeden sonra yapılır.

Yönetici şifresi unutulursa cPanel yetkisiyle özel bot-admin-auth.json dosyası güvenli biçimde yedeklenip farklı ada taşınarak yeniden ilk kurulum yapılabilir. Mevcut oturumlar kayıtlı hash değiştiğinde geçersizleşir. bot-encryption.key dosyasına dokunmayın; hesap şifresini sıfırlamak uygulama hesap şifrelerini çözmek için gereken anahtarı değiştirmez.

Testler: cpanel/tests/test_admin_http.py yalnızca kendi açıklamasındaki atılabilir, yerel test ortamında çalıştırılır; canlı sisteme karşı çalıştırmayın. Testler ilk hesap oluşturur ve giriş sayacını doldurur.

## Minimum çekim: 100 TL

`cpanel-minimum100-update.zip` yalnızca app/common.php, app/app.js ve app/view.html içerir. public_html içine çıkarıp bu üç dosyanın üzerine yazın. Güncelleme mevcut bakiyeleri, şifre anahtarlarını ve eski ödeme taleplerini değiştirmez; yeni ödeme taleplerinde minimum 100 TL (10.000 kuruş) uygulanır. Ekrandaki sayı alanı, JavaScript ve sunucu aynı sınırı kontrol eder. Önceden oluşturulmuş daha düşük talepler admin tarafından normal şekilde sonuçlandırılabilir. 99,99 TL'nin reddedildiği, bakiyenin değişmediği ve tam 100 TL'nin kabul/iadesi yerel MySQL testinde doğrulanmıştır.
