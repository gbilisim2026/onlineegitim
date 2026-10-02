# Prompt

İçerisinde öğrenci kayıt/giriş sistemi; bilişim teknolojileri alanındaki dallarla(siber güvenlik ve yazılım geliştirme) ilgili sınıfların,ders ve video listeleri; sınav ve testler; öğrencinin ilerleme dururmlarını içeren; sınıf,öğretmen/öğrenci,ders ekleme/çıkarma/güncelleme,eşleştirme yönetim paneline ve dashboarduna sahip; bu dashboardda içerik(video,resim,dosya,döküman) ekleme/silme/update yapabilen;  php backend ve mysql database i kullanan online eğitim sitesini  Ui/ux dizaynını da modern şekilde geliştirerek oluşturur musun?(Backend ve diğer gerekli araçları da yükle.)


# Pusula Akademi

PHP 8.2 ve MySQL/MariaDB ile çalışan, bağımlılıksız çevrimiçi eğitim platformu. Arayüz Türkçedir; PHP PDO, MySQL ve modern tarayıcı yeterlidir.

## Gereksinimler

- PHP 8.1 veya üstü (`pdo_mysql`, `fileinfo`, `mbstring` eklentileri)
- MySQL 8 veya MariaDB 10.4+

Node.js, Composer veya ek PHP paketi gerekmez.

## Windows / XAMPP kurulumu

1. XAMPP Control Panel'den Apache ve MySQL servislerini başlatın.
2. phpMyAdmin'de `database/schema.sql` dosyasını içe aktarın veya proje klasöründe:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < database\schema.sql
   ```

3. Gerekirse `config.php` içindeki veritabanı bağlantısını ya da `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` ortam değişkenlerini düzenleyin. Varsayılan ayarlar XAMPP'ın yerel `root` / boş şifre kurulumuna uygundur.
4. Mevcut kurulumunuzu güncelliyorsanız çoklu ders atama tablosunu ve mevcut sınıf öğrencilerinin ders kayıtlarını ekleyin:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < database\migrations\20261002_multi_assignments.sql
   ```

5. Yönetici hesabını güçlü ve benzersiz bir parolayla oluşturun:

   ```powershell
   php database\seed_admin.php yonetici@ornek.com "GucluBirParola123!"
   ```

6. Proje klasöründen yerel geliştirme sunucusunu çalıştırın:

   ```powershell
   php -S 127.0.0.1:8000
   ```

   Ardından `http://127.0.0.1:8000` adresini açın. XAMPP Apache kullanıyorsanız proje klasörünü `htdocs` içine koyup `http://localhost/onlineegitim` adresini ziyaret edin.

Bu çalışma alanında oluşturulmuş yerel deneme yönetici hesabı: `admin@pusula.local` / `PusulaAdmin!2026`. Gerçek kullanım öncesinde `php database\seed_admin.php` komutunu kendi e-posta adresiniz ve güçlü parolanızla çalıştırarak bu hesabı değiştirin.

## Özellikler

- Öğrenci kaydı, oturum açma ve rol tabanlı öğrenci / öğretmen / yönetici alanları
- Siber Güvenlik ve Yazılım Geliştirme alanları için sınıf ve ders yönetimi
- Öğretmen/öğrenci oluşturma, güncelleme, silme ve sınıf eşleştirmeleri
- Öğretmenleri birden fazla sınıfa eşleştirme; öğretmenin yalnızca atanmış sınıfların derslerinde içerik ve sınav yönetmesi
- Öğrencileri sınıflarına ait birden fazla derse eşleştirme ve öğrenci ekranlarında yalnızca atanmış dersleri gösterme
- Video bağlantısı veya video, görsel ve belge dosyası ekleme, düzenleme, silme
- Çoktan seçmeli sınav oluşturma, yayınlama, cevapları puanlama ve öğrenci sonuç takibi
- Ders tamamlama ve öğrenci ilerleme göstergeleri
- CSRF koruması, PDO prepared statements, parola hashleme ve dosya türü/boyutu doğrulama

İlk kez kayıt olan hesaplar öğrenci rolüyle açılır. Yönetici hesabı yalnızca komut satırındaki `seed_admin.php` betiği ile oluşturulur.
