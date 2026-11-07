# php2shell (CVE-2026-109618 / CVE-2026-109619): Kral Kafe sertleştirmesi

> **Durum (7 Kasım 2026):** Canlı site **yamasız PHP 8.3.8** ile çalışıyor.
> Bu PR birleşip Vercel'e dağıtılana kadar açık kapanmaz.
> Yama yalnızca PHP'nin kendisinde: `composer update`, `.ini` ayarları ve
> başlıklar **yamanın yerini tutmaz**.

PHP çekirdeğinde kimlik doğrulaması gerektirmeyen iki uzaktan kod çalıştırma
açığı. Yamalar 21 Ekim 2026'da çıktı ve sahada istismar ediliyor.

| PHP dalı | İlk yamalı sürüm |
|---|---|
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | **8.4.27** ← Kral Kafe buna geçiyor |
| 8.5 | 8.5.12 |

---

## 1. Envanter: PHP sürümü nerede belirleniyor?

| Yer | Bulgu |
|---|---|
| Barındırma | **Vercel serverless** (`main`'den otomatik dağıtım), bölge `sin1`; veritabanı Supabase Postgres. Sunucu, VPS, Docker yok |
| PHP'yi belirleyen | `vercel.json` → `functions["api/index.php"].runtime`. Topluluk runtime'ı **vercel-php** PHP ikilisini `@libphp/*` npm paketinden getirir |
| Canlıdaki runtime | `vercel-php@0.7.4` → `@libphp/amazon-linux-2-v83` (son sürüm 0.0.14, Temmuz 2025) → **PHP 8.3.8**. Canlı yanıt başlığında da `X-Powered-By: PHP/8.3.8` görüldü (2 Kasım) |
| 8.3 için yama | **Yok.** vercel-php'nin 8.3 hattı için 21 Ekim'den sonra derleme yayımlanmadı; 8.3'te kalarak kapatmak mümkün değil |
| Yamalı seçenekler | `vercel-php@0.8.0` → `@libphp/amazon-linux-2023-v84` 0.0.9 (23 Ekim) → **PHP 8.4.27**. `vercel-php@0.9.0` → `@libphp/almalinux-9-v85` 0.0.4 (21 Ekim) → PHP 8.5.12. Sürümler paketlerin içindeki ikili dosyalardan okundu |
| PHP çalışma biçimi | Runtime, PHP'nin yerleşik sunucusunu başlatır (`php -c php.ini -S … api/index.php`). Yönlendirici `api/index.php`; her istek Laravel'den geçer |
| php.ini | Runtime kendi php.ini'sini getirir (`display_errors=1`, `expose_php` varsayılan açık). Repodaki `api/php.ini` bunun **sonuna** eklenir |
| Composer | `require.php` `^8.2` idi (8.3.8'e izin veriyordu). Runtime derlemede `composer install --no-dev --no-scripts --ignore-platform-reqs` çalıştırır, sonra `composer run vercel` |
| Dockerfile / docker-compose | Yok |
| CI | Yoktu (`.github/` yok) |
| Dağıtım notları | README §12; "PHP 8.2" yazıyordu (güncellendi) |
| Web sunucusu yapılandırması | `vercel.json` rotaları, `public/.htaccess` (Apache yedeği), `server.php` (yerel yerleşik sunucu). nginx yok |

---

## 2. Bu PR'da yapılanlar

1. **PHP sabitleme**
   - `vercel.json`: `vercel-php@0.7.4` → `vercel-php@0.8.0` (PHP 8.4.27). Sürüm tam yazılı, kayan etiket yok.
   - `package.json`: `"engines": {"node": "22.x"}`. vercel-php 0.8.0 Node 22 istiyor; `engines`, paneldeki Node sürümünü ezer.
   - `composer.json`: `"php": "~8.4.27 || ^8.5.12"`. Ayrıca `config.platform.php = 8.4.27`: lock dosyası canlıdaki PHP'ye göre çözülür. `composer.lock` buna göre yeniden yazıldı, `composer validate` temiz.
   - **Derleme kapısı:** `composer run vercel` sonunda `php artisan kafe:php-surumu --kesin` çalışır.
     - Derlemedeki PHP yamasızsa betik hata verir, runtime derlemeyi durdurur ve canlı eski dağıtımda kalır. Yamasız yeni bir dağıtım yayına giremez.
     - Bu kapı gerekli, çünkü runtime `--ignore-platform-reqs` kullandığı için Composer'ın kendi PHP sürüm denetimi Vercel'de hiç üretilmiyor (yerelde doğrulandı).
2. **Güvenlik güncellemeleri** (major yükseltme yok):
   - **Composer:** `composer audit` 11 bildirim gösteriyordu, şimdi 0.
     - Symfony 7.4.x bileşenleri 7.4.21–7.4.23'e çıktı: http-foundation, http-kernel, routing, mime, filesystem, yaml ve bağımlılıkları. Symfony 7.4 hattında tutuldu, 8.x'e geçilmedi.
     - league/commonmark 2.10.1 → 2.10.7.
   - **npm:** `npm audit` 5 bildirim gösteriyordu (2 kritik, 3 yüksek; hepsi geliştirme bağımlılığı), şimdi 0.
     - vite 7.3.6 → 7.3.7, concurrently 9.2.4 → 9.2.5, follow-redirects 1.16.0 → 1.16.2, shell-quote 1.9.0 → 1.12.0, source-map-js 1.2.1 → 1.2.2.
3. **Web sunucusu**
   - **Vercel:** `vercel.json` zaten her `*.php` isteğini `api/index.php`'ye yönlendiriyor; dosya sistemi salt okunur ve yüklemeler S3'te (Supabase Storage). Başka bir PHP dosyası çalıştırılamıyor; değişiklik gerekmedi.
   - **`public/.htaccess`:** `storage/` ve `uploads/` altında `.php`, `.phtml`, `.phar` ve `.pht` dosyalarına 403 döner. `X-Powered-By` başlığı silinir.
   - **`storage/app/public/.htaccess`:** Apache'de bu dizinde PHP çalışmaz.
   - **`server.php`:** Yerel yerleşik sunucu `.php` dosyalarını doğrudan çalıştırmaz; 403 döner (denendi).
   - **Yüklemeler:** Uygulama yüklenen dosyaya kendi adını veriyor (`uuid.pdf` ya da `hashName()`; `mimes:pdf` / `image` doğrulaması). Kullanıcı `.php` uzantılı bir dosya bırakamıyor.
   - **Güvenlik başlıkları (`SecurityHeaders`):** Her yanıta eklenir.
     - `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`.
     - `Permissions-Policy`: kamera ve konum yalnızca kendi sitemize açık (QR okuyucu, oturum başlatma ve kafe konumu bunları kullanıyor); mikrofon, ödeme ve USB kapalı.
     - `X-Powered-By` silinir.
     - HSTS'yi Vercel zaten gönderiyor. CSP eklenmedi (§6).
4. **php.ini (`api/php.ini`)**
   - `expose_php = Off`.
   - `display_errors = Off` ve `display_startup_errors = Off`; hatalar yine loglanır (`log_errors = On`).
   - `session.upload_progress.enabled = Off`. Bu geçici, derinlemesine bir savunma; **yamanın yerini tutmaz**.
     - Uygulama bu özelliği kullanmıyor: Laravel kendi oturum sürücüsünü (veritabanı) kullanıyor.
     - Kodda `$_SESSION`, `session_start` ve `PHP_SESSION_UPLOAD_PROGRESS` hiç geçmiyor.
5. **Ortam şablonları**
   - **`.env.example`:** Yerel geliştirme için `APP_DEBUG=true` olarak kaldı; üretimde ne olması gerektiği yorum olarak eklendi. Boş `SESSION_SECURE_COOKIE=` yazılmasın: Laravel bunu `false` sayar ve HTTPS algılamasını kapatır.
   - **`DB_EMULATE_PREPARES=false` satırı yoruma alındı:** Varsayılan zaten `false`. Satır dururken `composer setup` ile kurulan yeni ortamda iki test kırmızı kalıyordu; CI aynı şablonu kullanıyor.
   - **`.env.production.example`** (yeni, sır yok):
     - `APP_ENV=production`, `APP_DEBUG=false`.
     - `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `LOG_CHANNEL=stderr`.
6. **Görünürlük ve CI**
   - **Yönetim → Ayarlar → Sistem:** Çalışan PHP sürümü ve "Güvenlik yaması var/yok" rozeti görünür. `expose_php` kapalı olduğu için sürüm artık yanıt başlığında yok.
   - **`.github/workflows/tests.yml`:** PR'larda testler PHP 8.4'te koşar; önce `kafe:php-surumu --kesin` çalışır.

---

## 3. Sadık'ın yapacakları

Sunucu yok; "sunucu" Vercel projesi. Aşağıdaki sırayla:

### 3.1 Birleştirmeden önce

- [ ] **Node.js sürümü:** Vercel → kral-kafe → Settings → **Build and Deployment → Node.js Version** → **22.x**. `package.json` zaten 22.x istiyor; panel farklıysa Vercel derleme logunda uyarı verir.
- [ ] **PR'daki CI** (GitHub Actions, "Testler (PHP 8.4)") yeşil mi? Yeşil değilse birleştirme.

### 3.2 Birleştir ve dağıtımı izle

- [ ] PR'ı `main`'e birleştir. Vercel otomatik dağıtır.
- [ ] Vercel → Deployments → yeni dağıtım → **Build Logs**:
  - `🐘 Installing Composer dependencies` satırları görünmeli.
  - Ardından `PHP 8.4.27: php2shell yamasi var.` satırı gelmeli. 8.4.27'den büyük bir sürüm de olur.
  - Derleme `yamasi YOK` ile **durursa**: canlı eski dağıtımda kalır, site çalışmaya devam eder ama açık kapanmamıştır.
    - **Use existing Build Cache** kutusu kapalıyken **Redeploy** et.
    - Yine durursa runtime eski bir `@libphp` paketi çekiyordur; Claude'a ya da bana yaz.
- [ ] **Instant Rollback / Promote ile eski bir dağıtıma DÖNME.** 7 Kasım öncesi her dağıtım PHP 8.3.8 taşır, yani açık geri gelir. Sorun çıkarsa ileri düzeltme yapılır.

### 3.3 Sürümü doğrula (`php -v` karşılığı)

Vercel'de kabuk yok; `php -v` ya da `php-fpm -v` çalıştırılamaz. Bunların yerine:

1. **Yönetim → Ayarlar → Sistem:** `PHP 8.4.27` ve yeşil **Güvenlik yaması var** rozeti görünmeli.
2. **Başlıklar:** Bilgisayardan aşağıdaki komutu çalıştır.

   ```bash
   curl -sI https://kral-kafe-ten.vercel.app/giris
   ```

   - `X-Powered-By` satırı **olmamalı**. Önceden `PHP/8.3.8` yazıyordu.
   - `x-content-type-options: nosniff`, `x-frame-options: SAMEORIGIN`, `referrer-policy` ve `permissions-policy` satırları **olmalı**.
3. **Derleme logu:** §3.2'deki `php2shell yamasi var` satırı.

### 3.4 "Yeniden başlatma"

Vercel'de ayrı bir yeniden başlatma adımı yok: yeni dağıtım yeni PHP süreçleriyle açılır, eski dağıtımın sıcak örnekleri kısa sürede kapanır. Yeni dağıtım "Ready" olunca trafik onda.

### 3.5 Ortam değişkenleri (panel, Production)

`.env.production.example` ile karşılaştır:

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (README §12.2).
- [ ] `SESSION_SECURE_COOKIE=true`. Boş bir satır olarak duruyorsa sil ya da `true` yap.
- [ ] `SESSION_ENCRYPT=true`. Geçildiği an açık oturumlar bir kez düşer (herkes bir kez yeniden giriş yapar); kafe kapalıyken yap.
- [ ] Değişkenleri değiştirdiysen **Redeploy** et.

### 3.6 Duman testi: QR → Adisyon akışı (5 dakika)

Telefonla, mobil veriyle ve kafe Wi-Fi'ıyla birer kez:

1. [ ] Masadaki QR'ı telefon kamerasıyla okut. Giriş yap; **Adisyonum** masa adıyla açılmalı.
2. [ ] **Ürün ekle** → bir ürün → aynı masanın adisyonuna dönülmeli. Toplam artmalı ve "Geri al" görünmeli; 60 saniye içinde **Geri al**.
3. [ ] **Çalışma oturumuna geç** → **Çalışmaya Başla**. Konum izni sorulmalı, sayaç açılmalı. Ardından oturumu bitir.
4. [ ] Menü → **QR Okut**: kamera açılmalı ve masadaki QR okunmalı. Bu adım `Permissions-Policy: camera=(self)` ayarını doğrular.
5. [ ] Yönetici olarak:
   - [ ] **Adisyonlar** açılmalı.
   - [ ] **Ayarlar → Konumu buradan al** çalışmalı (konum izni).
   - [ ] Bir deneme PDF'i yüklenebilmeli (yükleme yolu değişmedi).
6. [ ] Ertesi sabah Vercel → **Cron Jobs**: `/zamanlanmis/gunluk` ve `/zamanlanmis/gece` başarılı çalışmış olmalı.

Bir adım kırılırsa: Vercel → Logs → ilgili istek. Eski dağıtıma dönme (§3.2); düzeltme ileri yönde yapılır.

### 3.7 Vercel dışına taşınırsa (VPS / paylaşımlı barındırma)

Bugün geçerli değil; ileride lazım olursa:

1. **Sürümü kontrol et:**

   ```bash
   php -v
   php-fpm8.4 -v   # dağıtıma göre php-fpm -v
   ```

   Debian/Ubuntu paketleri yamayı **sürüm numarasını değiştirmeden** geri taşır (örn. `8.3.6-0ubuntu…`). Sürüm numarasından değil, paketin güvenlik bildiriminden (USN/DSA) bak: `apt changelog php8.3-fpm | head` ya da `dpkg -l 'php8*'`. Bu durumda `kafe:php-surumu` yanlış alarm verir.
2. **Yükselt:**

   ```bash
   sudo apt update && sudo apt install --only-upgrade 'php8.4-*'
   ```

   Paylaşımlı barındırmada: panel → PHP sürümü (örn. cPanel MultiPHP) → yamalı sürüm. Yoksa barındırma firmasından yamalı derleme iste.
3. **Sertleştir:** `api/php.ini` içeriğini `/etc/php/8.4/fpm/conf.d/99-kral-kafe.ini` dosyasına kopyala.
4. **Yeniden başlat:** OPcache da bununla sıfırlanır.

   ```bash
   sudo systemctl restart php8.4-fpm && sudo systemctl reload nginx   # ya da apache2
   ```

5. **nginx:** Yükleme dizinlerinde PHP çalıştırma:

   ```nginx
   location ~* ^/(storage|uploads)/.*\.(php\d?|phtml|phar|pht)$ { deny all; }
   ```

   Apache için kural repoda hazır (`public/.htaccess`, `storage/app/public/.htaccess`).
6. Ardından §3.6'daki duman testini yap.

---

## 4. İz kontrolü: 21 Ekim 2026 sonrası (yalnızca tespit)

Canlı runtime 8.3.8 olduğu için site açık yayımlandığı günden beri savunmasızdı; istismar 21 Ekim'den sonra yaygınlaştı. Aşağıdakiler **salt okuma** kontrolleri. Bir şey bulursan §4.6'ya geç.

**Vercel'e özgü:**
- Dosya sistemi salt okunur (yalnız `/tmp` yazılabilir) ve her istek örneği geçici. Diske bırakılmış kalıcı bir "web shell" beklenmez.
- Bir saldırgan kalıcılığı **veritabanında, ortam değişkenlerinde, depolama kovasında veya hesaplarda** arar. Kontroller bu yüzden oralara odaklı.
- PHP sürecinde kod çalıştıran biri, ortam değişkenlerindeki **tüm sırları** okuyabilir.

### 4.1 Vercel

- [ ] **Deployments:** 21 Ekim sonrası, senin ya da bu repodaki PR'ların tetiklemediği bir dağıtım var mı? Oluşturan kişiye ve commit'e bak.
- [ ] **Settings → Environment Variables:** Tanımadığın yeni ya da değişmiş değişken var mı? Değişkenin "Updated" tarihine bak.
- [ ] **Team → Members** ve **Account → Tokens:** Tanımadığın üye ya da erişim jetonu var mı?
- [ ] **Logs (Runtime):** Hobby planda loglar kısa süre tutulur; 21 Ekim'e dönük kayıt büyük ihtimalle yoktur. Elde ne varsa bak:
  - beklenmeyen 5xx sıçramaları;
  - uygulamada olmayan yollara istekler (`*.php`, garip uzun sorgu dizeleri);
  - giriş yapmamış istemcilerden gelen yoğun `multipart/form-data` POST'lar;
  - tek IP'den yoğun istek.

### 4.2 Veritabanı (Supabase → SQL Editor, yalnızca SELECT)

```sql
-- Yonetici/koc hesaplari: tanimadigin, 21 Ekim sonrasi acilmis ya da degismis var mi?
select id, name, email, phone, role, created_at, updated_at
from users
where role in ('admin', 'coach')
order by greatest(created_at, updated_at) desc;

-- 21 Ekim sonrasi rolu ya da sifresi degismis olabilecek hesaplar
select id, name, role, updated_at from users
where updated_at >= '2026-10-21' order by updated_at desc limit 50;

-- Yonetici oturumlari: tanimadigin IP / tarayici
select s.user_id, u.name, s.ip_address, s.user_agent, to_timestamp(s.last_activity)
from sessions s join users u on u.id = s.user_id
where u.role = 'admin' order by s.last_activity desc;

-- Ayarlar tablosunda beklenmeyen degisiklik
select key, updated_at from settings order by updated_at desc;

-- Veritabaninda yeni rol, tetikleyici ya da fonksiyon (kalicilik izi)
select rolname, rolsuper, rolcreaterole, rolcanlogin from pg_roles order by oid desc limit 15;
select event_object_table, trigger_name from information_schema.triggers where trigger_schema = 'public';
select routine_name from information_schema.routines where routine_schema = 'public';
```

- [ ] Tanımadığın yönetici/koç hesabı, rol değişikliği ya da yabancı IP'den yönetici oturumu yok.
- [ ] `public` şemasında uygulamanın oluşturmadığı tetikleyici ya da fonksiyon yok. Migration'larda olmayan her şey şüpheli.
- [ ] Supabase → **Logs** (Postgres/API): 21 Ekim sonrası alışılmadık DDL (`CREATE ROLE/FUNCTION/TRIGGER`, `ALTER USER`) yok.

### 4.3 Depolama (Supabase Storage, kova herkese açık)

- [ ] Kovada 21 Ekim sonrası beklenmeyen dosya yok. Özellikle `.php`, `.phtml`, `.html`, `.svg`, `.js` dosyalarına ya da uygulamanın klasör düzenine uymayan yollara bak. Uygulamanın düzeni: `deneme-raporlari/`, `deneme-aktarimlari/`, `stock_photos/`.

### 4.4 Sağlayıcılar ve sırlar

- [ ] **OpenAI / Anthropic / Gemini** panellerinde 21 Ekim sonrası **kullanım sıçraması** yok.
- [ ] **Supabase** egress ya da bağlantı sayısında sıçrama yok.
- [ ] **GitHub:**
  - `main`'de tanımadığın commit yok.
  - Settings → Deploy keys, Webhooks ve Actions secrets'ta tanımadığın kayıt yok.

### 4.5 Kayıt

- [ ] Ne kontrol edildi, ne zaman, ne bulundu: tarih ve saatle bir yere yaz (Linear yorumu yeterli).

### 4.6 Bir iz bulunursa

1. **Kanıtı koru:** Logları dışa aktar, ekran görüntüsü al. Kayıtları silme.
2. **Sırları yenile.** PHP sürecine erişen biri hepsini okuyabilirdi:
   - `APP_KEY`: herkes bir kez çıkış yapar. Uygulamada şifreli sütun yok.
   - Supabase DB şifresi: panelden **Reset**.
   - Supabase S3 anahtarları.
   - `OPENAI_API_KEY`, `ANTHROPIC_API_KEY` ve varsa Gemini anahtarı.
   - `CRON_SECRET`.
   - Yönetici şifreleri.
   - Web Push VAPID anahtarı: `WEB_PUSH_*` ya da `settings` satırı. Yenilenirse aboneler bildirime yeniden izin verir.
3. **Yeniden dağıt:** Yeni sırlarla dağıt. Tanımadığın hesap, oturum ve jetonları kapat.
4. **KVKK:** Kişisel veriye (üye adı, telefon, e-posta) erişildiğinden şüpheleniliyorsa Kişisel Verileri Koruma Kurumu'na bildirim yükümlülüğü doğar. Kurul kararına göre süre 72 saattir; hukuki değerlendirme için danış.

---

## 5. Doğrulanamayanlar

- **Vercel'in kendi PHP 8.4.27 ikilisiyle test koşulmadı.** Çalışma ortamında PHP 8.3.6 vardı ve indirilen ikiliyi çalıştırma izni verilmedi.
  - Testler yerelde 8.3.6'da yeşil.
  - Testler GitHub Actions'ta, `shivammathur/setup-php` ile kurulan PHP 8.4.27'de de yeşil (1585/1585). `composer install` ve derleme kapısı da orada geçti.
  - Vercel'in Amazon Linux 2023 derlemesi bundan farklı bir ikili. Fark ilk Vercel derlemesinde görülür; yamasızsa derleme kapısı dağıtımı durdurur.
- **Vercel panel ayarları okunmadı** (kural gereği canlıya erişim yok): Node.js sürümü, `APP_DEBUG` ve oturum değişkenlerinin gerçek değerleri. §3.1 ve §3.5'te elle kontrol edilecek.
- **libphp paketinin yamayı gerçekten içermesi:** Paketin içindeki ikili dosyanın `PHP/8.4.27` sürümünü taşıdığı görüldü. Yamanın içeriği ayrıca denetlenmedi.
- **CVE ayrıntıları:** Bu belge yalnızca savunma ve tespit içeriyor; açığın teknik ayrıntısı bağımsız olarak doğrulanmadı.
- **Runtime'ın derleme önbelleği:** Vercel'in önbellekten eski bir `@libphp` sürümü çekip çekmeyeceği bilinmiyor; çekerse derleme kapısı dağıtımı durdurur.

## 6. Sonraki adımlar (bu PR'ın dışında)

**Major yükseltmeler** yapılmadı, yalnız listelendi:

| Paket | Şimdiki | En yeni |
|---|---|---|
| laravel/framework | 12.69.2 | 13.x |
| laravel/tinker | 2.11.1 | 3.x |
| phpunit/phpunit | 11.5.56 | 13.x |
| anthropic-ai/sdk | 0.54.0 | 1.x |
| symfony/* (dolaylı) | 7.4.x | 8.1.x |
| vite | 7.3.7 | 8.x |
| laravel-vite-plugin | 2.x | 3.x |
| concurrently | 9.2.5 | 10.x |

Diğer işler:

- **Content-Security-Policy:** Önce sayfalardaki satır içi betikler nonce'a taşınmalı; acele açılırsa uygulama kırılır.
- **Kendi alan adı** (kralkafe.com) bağlanınca `curl -sI` ile HSTS başlığının orada da geldiğini kontrol et.
- ~~**Dependabot / haftalık `composer audit` + `npm audit`**~~ · ✅ 7 Kasım 2026: `.github/dependabot.yml` (haftalık, küçük sürümler tek PR'da, major kapalı) ve `.github/workflows/audit.yml` (Pazartesi 07:17, açık bulursa kırmızı). GitHub → Settings → Code security'de **Dependabot alerts** ve **security updates** açılmalı.
