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
