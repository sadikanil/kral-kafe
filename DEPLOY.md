# Dağıtım: Vercel + Supabase

Bu proje bir Laravel 12 uygulaması. Vercel serverless çalıştığı için iki şeyin
uygulama dışına taşınması gerekiyor: **veritabanı** ve **yüklenen dosyalar**.
Her ikisi de Supabase'in ücretsiz katmanıyla karşılanabiliyor.

## 1. Supabase

1. [supabase.com](https://supabase.com) üzerinde yeni bir proje aç.
2. **Veritabanı**: Project Settings → Database → Connection string.
   Uygulama için **Session pooler** kullan: `aws-0-<bölge>.pooler.supabase.com`
   port `5432`. Doğrudan bağlantı (`db.<ref>.supabase.co`) yalnızca IPv6
   üzerinden geliyor, transaction pooler (`6543`) ise aşağıda anlatılan
   boolean sorununa yol açıyor.
3. **Depolama**: Storage → yeni bir bucket oluştur (örn. `kral-kafe`).
   Ardından Project Settings → Storage → S3 access keys'ten bir anahtar üret.

## 2. Vercel ortam değişkenleri

Vercel projesinde Settings → Environment Variables altına gir:

| Değişken | Değer |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` çıktısı |
| `APP_ENV` | `production` (bir kez `local` kalmıştı) |
| `APP_DEBUG` | `false` — **uretimde asla true olmasin.** Hata sayfasi TUM istek basliklarini gosteriyor; icinde `x-vercel-oidc-token` ve `x-vercel-sc-headers` altindaki `Bearer` token da var. Siteye o anda giren herkes gorur. |
| `APP_URL` | Vercel'in verdiği alan adı |
| `APP_LOCALE` | `tr` |
| `SESSION_LIFETIME` | `900` (kafe gününden uzun olmalı) |
| `KAFE_TIMEZONE` | `Europe/Istanbul` |
| `KAFE_ACILIS` / `KAFE_KAPANIS` | `09:00` / `21:00` |
| `KAFE_IPLER` | **Boş bırak.** IP kapısı rafta: kafenin IP'si dinamik ölçüldü (bkz. UYGULAMA-PLANI §2.1) |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `aws-0-ap-southeast-1.pooler.supabase.com` — **yer tutucu değil, birebir bu.** Bir kez `<bölge>` olduğu gibi yapıştırıldı ve site `could not translate host name` ile 500 verdi |
| `DB_PORT` | `5432` (session pooler) |
| `DB_DATABASE` | `postgres` — **tamamı küçük harf.** Postgres veritabanı adları büyük/küçük harfe duyarlı; `POSTGRES` yazılınca havuz `database "POSTGRES" does not exist` (3D000) ile düşüyor. 20 Eylül 2026'da yaşandı |
| `DB_USERNAME` | `postgres.hxlklrwbeeddbajiectt` — **pooler kullanıcı adı proje referansını içerir.** Düz `postgres` yalnızca doğrudan bağlantıda geçerli; pooler onu reddeder |
| `DB_PASSWORD` | Supabase veritabanı şifresi. Bilinmiyorsa Supabase → Project Settings → Database → **Reset database password**; sıfırlama projeyi yeniden başlatır (~2 dk) ve havuzun sakladığı şifreyi de günceller. Şifre panel dışından (`ALTER USER`) değiştirilirse havuz eski şifreyle kalır: istemci doğrulanır ama arka plan `DbHandler: Auth error 28P01` verir — çözüm yine panelden sıfırlamak |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `UPLOAD_DISK` | `s3` |
| `AWS_ACCESS_KEY_ID` | Supabase S3 anahtarı |
| `AWS_SECRET_ACCESS_KEY` | Supabase S3 gizli anahtarı |
| `AWS_BUCKET` | Bucket adı |
| `AWS_ENDPOINT` | `https://<proje-ref>.supabase.co/storage/v1/s3` |
| `AWS_DEFAULT_REGION` | Supabase projesinin bölgesi |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |
| `VIEW_COMPILED_PATH` | `/tmp/storage/framework/views` |
| `APP_PACKAGES_CACHE` | `/tmp/bootstrap/cache/packages.php` |
| `APP_SERVICES_CACHE` | `/tmp/bootstrap/cache/services.php` |
| `APP_CONFIG_CACHE` | `/tmp/bootstrap/cache/config.php` |
| `APP_EVENTS_CACHE` | `/tmp/bootstrap/cache/events.php` |
| `APP_ROUTES_CACHE` | `/tmp/bootstrap/cache/routes-v7.php` |
| `AWS_URL` | `https://<proje-ref>.supabase.co/storage/v1/object/public/<bucket>` |
| `DB_SSLMODE` | `require` |
| `OPENAI_API_KEY` | Yapay zekâ stok analizi için (boş bırakılırsa analiz devre dışı kalır) |

`QUEUE_CONNECTION=sync` bilinçli: uygulama kuyruğa iş atmıyor ve Vercel'de
arka plan işçisi çalıştırılamaz.

### Hangi havuz, neden

Supabase üç bağlantı yolu sunuyor ve üçü de aynı değil:

| Yol | Port | Durum |
|---|---|---|
| Doğrudan (`db.<ref>.supabase.co`) | 5432 | Çalışır, ama **yalnızca IPv6**. Migration için yerelden kullanılabilir; Vercel'de güvenilir değil. |
| **Session pooler** (`aws-0-*.pooler.supabase.com`) | 5432 | **Önerilen.** IPv4 var, sunucu tarafı prepared statement'lar çalışır. |
| Transaction pooler | 6543 | Serverless için ideal görünür ama prepared statement taşımaz. |

Transaction pooler'ı kullanmak için `DB_EMULATE_PREPARES=true` gerekiyor, aksi
halde istekler `SQLSTATE[42P05]` ile düşer. **Ancak emülasyon açıkken Postgres'te
boolean yazmaları kırılıyor:** Laravel binding'lerde `true` değerini `1`'e
çeviriyor, emülasyon bunu çıplak bir integer olarak gömüyor ve Postgres
`column is of type boolean but expression is of type integer` (42804) veriyor.
Bu gerçek Supabase üzerinde doğrulandı — `is_active` yazan her migration ve her
kayıt düşüyor.

Bu yüzden emülasyon varsayılan olarak **kapalı** ve önerilen yol session
pooler. Trafik artıp session pooler'ın bağlantı sınırına dayanırsanız,
transaction pooler'a geçmeden önce boolean binding'ini çözmeniz gerekir
(`Connection::resolverFor('pgsql', ...)` ile `prepareBindings` override edilip
boolean'lar `'true'`/`'false'` metni olarak bağlanmalı).

`APP_*_CACHE` değişkenleri **zorunlu**. Vercel'in PHP runtime'ı Composer'ı
`--no-scripts` ile çalıştırdığı için `artisan package:discover` hiç koşmuyor ve
`bootstrap/cache` lambda'ya boş gidiyor. `/var/task` salt-okunur olduğundan
Laravel bu dosyaları üretemez ve ilk istekte *"directory must be present and
writable"* hatasıyla düşer. Bu değişkenler yazımı `/tmp` altına alır.

`AWS_URL` de **zorunlu**. Boş bırakılırsa Laravel adresi S3 protokol ucundan
türetir (`.../storage/v1/s3/...`); o adres SigV4 imzası ister ve `<img src>` ile
açılmaz. Yüklenen hiçbir görsel görünmez. Bucket, Supabase panelinde **Public**
işaretlenmiş olmalı; private kalacaksa `Storage::temporaryUrl()` kullanılmalı.

## 2.1 Derleme ayarları: hepsi boş kalmalı

Vercel panelinde **Settings → Build & Development Settings** altındaki üç alanı
da **boş / kapalı (gri)** bırak. Install Command'a bir şey yazma.

| Alan | Değer |
|---|---|
| Framework Preset | Other (`vercel.json`'daki `"framework": null` bunu zorluyor) |
| Build Command | boş |
| Output Directory | `public` |
| Install Command | **boş** |

### Neden Install Command yazılmıyor

Denendi ve derleme çöktü:

```
Running "install" command: `composer install --no-dev ...`
sh: line 1: composer: command not found
Error: Command "composer install ..." exited with 127
```

Vercel'in derleme konteynerinde **composer yok**. `vercel-php` builder'ı PHP'yi
ve composer'ı kendi adımında kuruyor; Install Command aşaması ondan **önce**
çalışıyor ve o aşamada PATH'te ne `composer` var ne `php`. Yani bu runtime'da
Install Command'a PHP dünyasına ait hiçbir komut yazılamaz.

Bağımlılık kurulumunu runtime kendisi yapıyor (`--no-dev` ile) — bu yüzden
`.vercelignore` `/vendor` dizinini dağıtımdan çıkarıyor.

### Derleme zamanı kancası: `composer.json` → `scripts.vercel`

Bu runtime'ın desteklediği tek derleme kancası, `composer.json` içinde
**`vercel`** adlı composer script'i. Bizimki şunu yapıyor:

```json
"vercel": [
    "@php -r \"... eksik onbellek dizinlerini olustur ...\"",
    "@php artisan package:discover --ansi"
]
```

`mkdir` adımı şart ve sebebi çalıştırılarak doğrulandı: `APP_PACKAGES_CACHE`
`/tmp/bootstrap/cache/packages.php`'yi gösteriyor, o dizin derleme makinesinde
yok ve Laravel şunu atıp **derlemeyi komple çökertiyor**:

```
PackageManifest.php line 179:
The /tmp/bootstrap/cache directory must be present and writable.
```

Dizinler önce açılınca keşif sorunsuz koşuyor ve `packages.php` + `services.php`
üretiliyor.

### Yönlendirme: `index.php` neden indiriliyordu

İlk dağıtımda alan adına girince tarayıcı `index.php` dosyasını **indirdi**.
Sebep, `outputDirectory: "public"` ile `{ "handle": "filesystem" }` birleşimi:
`public/` statik çıktı olarak yayınlanınca `public/index.php` de statik bir
varlık haline geliyor, filesystem aşaması onu buluyor ve ham metin olarak
servis ediyor. PHP fonksiyonuna hiç ulaşılmıyor.

Çözüm: kök isteği ve **her** `.php` isteğini, filesystem aşamasına *varmadan*
fonksiyona yönlendirmek.

```json
"routes": [
    { "src": "^/$",              "dest": "/api/index.php" },
    { "src": "^/(.+\\.php)$",    "dest": "/api/index.php" },
    { "handle": "filesystem" },
    { "src": "/(.*)",            "dest": "/api/index.php" }
]
```

Sıra önemli ve her satırın işi ayrı:

1. `^/$` — ana sayfa. Doğrudan fonksiyona; aksi halde filesystem `index.php`'yi
   bulup indirtiyor.
2. `^/(.+\.php)$` — birinin `/index.php` ya da başka bir `.php` yolunu doğrudan
   istemesi. Kaynak kodun ham servis edilmesi sadece çirkin değil, **sızıntıdır**.
3. `handle: filesystem` — buraya yalnızca gerçek varlıklar ulaşır:
   `/css/app.css`, `/favicon.ico`, `/robots.txt`, `/build/*`.
4. Kalan her şey Laravel'e.
### npm neden yok

Hiçbir blade `@vite` kullanmıyor; `vite build` kimsenin yüklemediği varlıklar
üretiyordu. Stiller elle yazılan `public/css/app.css`'te ve `outputDirectory: public`
sayesinde doğrudan servis ediliyor. `vite.config.js` ve `package.json` yerinde
duruyor — ileride arayüz yenilenirse kullanılabilir, sadece dağıtımda
çalıştırılmıyor.

## 2.2 Sorun çıkınca: önce Supabase logları, sonra tahmin

`APP_DEBUG=false` iken Laravel'in 500 sayfası hiçbir şey söylemez. Vercel'in
çalışma anı loglarına erişim yoksa bile iki kaynak var ve ikisi 20 Eylül 2026'da
sorunu tahminsiz çözdü:

1. **Supabase → Logs → Pooler (Supavisor).** Uygulamanın veritabanına ulaşıp
   ulaşmadığını ve nerede düştüğünü satır satır söyler:

   | Log satırı | Anlamı |
   |---|---|
   | hiç kayıt yok | İstek havuza hiç ulaşmıyor: `DB_HOST` yanlış/boş, DNS, ya da hata bağlantıdan önce (APP_KEY, oturum sürücüsü, PHP hatası) |
   | `ClientHandler: Exchange error: password authentication failed` | Vercel'deki `DB_PASSWORD` yanlış |
   | `ClientHandler: Connection authenticated` + `DbHandler: Auth error 28P01` | Şifre doğru ama havuzun sakladığı şifre eski → panelden sıfırla |
   | `DbHandler: Auth error 3D000 database "X" does not exist` | `DB_DATABASE` yanlış (büyük harf dahil) |
   | `ClientHandler: Connection authenticated` + `DbHandler: Backend authenticated` | Bağlantı tamam; sorun varsa artık uygulama katmanında |

   Havuz **başarılı** bağlantıları da logluyor; "kayıt yok" ile "başarısız"
   farklı teşhislerdir.

2. **Jetonla kilitli teşhis sayfası** (gerekirse geçici olarak eklenir, iş
   bitince kaldırılır — `690e0f1` commit'inde örneği var): `api/index.php`
   içinde, sha256 özeti depoda duran bir jetonla `/?teshis=<jeton>` isteğine
   düz metin rapor döner: sır içermeyen ortam özeti (sırlar yalnızca uzunluk
   ve "satır sonu var mı"), ham PDO bağlantı sonucu ve Laravel'in aynı isteği
   işlerken ürettiği istisna (sınıf, mesaj, dosya:satır). `curl -sI` ile başlık
   okumaktan üstün: kullanıcı tarayıcıdan açıp çıktıyı yapıştırabilir ve
   Laravel'in kendi istisnası da görünür.

**Önizleme (preview) dağıtımlarına dikkat:** Vercel'de değişkenler ortam
başına tanımlanır. Yalnızca Production için girilen `DB_*` değerleri bir dalın
önizleme dağıtımında **tanımsızdır**; oradan alınan rapor üretimi anlatmaz.
Raporun `APP_URL` satırına değil, hangi adresten açıldığına bakın.

**Satır sonu kırpma:** `api/index.php`, Laravel bootlanmadan önce bütün ortam
değişkenlerinin baş ve sonundaki `\r\n`'i kırpar (`App\Support\OrtamTemizligi`).
Vercel alanına yapıştırıp Enter'a basmak değerin sonuna satır sonu ekliyor ve
`DB_PASSWORD`'de 13 karakterlik şifre canlıda 15 ölçüldü. Kırpma bu sınıf
hatanın tamamını kapatır; ama büyük/küçük harf ya da yanlış değer gibi
hataları kapatmaz.

## 3. Migration'lar

Vercel build adımında migration çalıştırmak güvenli değil (her dağıtımda
tetiklenir). Yerelden bir kez çalıştır:

Bağlantı bilgilerini `.env.supabase` dosyasına yaz (bu dosya `.gitignore`'da,
şablonu `.env.supabase.example`), sonra:

```bash
cp .env.supabase.example .env.supabase   # doldur
php artisan migrate --force --env=supabase
php artisan db:seed --class=AdminSeeder --force --env=supabase
```

> **Her yerde `DB_PORT=5432` (session pooler).** Hem migration sırasında hem
> uygulama çalışırken. Transaction pooler (`6543`) `PDO::ATTR_EMULATE_PREPARES`
> gerektiriyor, o da boolean yazmalarını `SQLSTATE[42804]` ile kırıyor — gerçek
> Supabase üzerinde görüldü. Ayrıca DDL ve uzun migration transaction'ları
> transaction pooler'da güvenilir çalışmaz.
>
> (§2'deki tabloyla çelişen eski bir not buradaydı: "Vercel ortamında 6543
> kullan". Yanlıştı, kaldırıldı.)
>
> **Doğrudan bağlantı yalnızca IPv6 üzerinden geliyor.** `db.<proje-ref>.supabase.co`
> adresinin IPv4 (A) kaydı yok, sadece AAAA kaydı var — Supabase ücretsiz katmanda
> IPv4'ü kaldırdı. Ağınızda IPv6 çıkışı yoksa bağlantı kurulamaz; o durumda
> migration'ları da pooler üzerinden (`6543`) çalıştırmak ya da IPv4 eklentisini
> satın almak gerekir. Kontrol:
>
> ```bash
> curl -6 -sS -o /dev/null -w '%{http_code}\n' https://ipv6.google.com
> ```

> Postgres yolu yerel makinede doğrulanamadı (Postgres/Docker kurulu değil).
> İlk migration'da hata çıkarsa buradan devam edilmeli.

## 4. E-posta

Şifre sıfırlama e-posta gönderir. `MAIL_MAILER=log` olduğu sürece mail
gerçekten gitmez, log'a yazılır. Gerçek kullanım için bir SMTP sağlayıcısı
(Resend, Postmark, Mailgun) tanımlanmalı.

## Bilinen kısıtlar

- **Vercel Hobby katmanı ticari kullanıma kapalı.** Kafe gerçekten bu sistemi
  işletmede kullanacaksa ücretli plana geçmek gerekir.
- **Supabase ücretsiz katmanı 7 gün hareketsizlikten sonra projeyi duraklatır**;
  panelden elle uyandırmak gerekir.
- **`vercel-php` resmî değil**, topluluk tarafından sürdürülüyor.
- PHP serverless'ta soğuk başlangıçlar yavaştır.
