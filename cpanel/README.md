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
