# Dağıtım: Vercel + Supabase

Bu proje bir Laravel 12 uygulaması. Vercel serverless çalıştığı için iki şeyin
uygulama dışına taşınması gerekiyor: **veritabanı** ve **yüklenen dosyalar**.
Her ikisi de Supabase'in ücretsiz katmanıyla karşılanabiliyor.

## 1. Supabase

1. [supabase.com](https://supabase.com) üzerinde yeni bir proje aç.
2. **Veritabanı**: Project Settings → Database → Connection string.
   Serverless'tan bağlanırken **connection pooler** (port `6543`) kullan,
   doğrudan bağlantı (`5432`) değil — serverless her istekte yeni bağlantı
   açar ve doğrudan bağlantı kotası hızla dolar.
3. **Depolama**: Storage → yeni bir bucket oluştur (örn. `kral-kafe`).
   Ardından Project Settings → Storage → S3 access keys'ten bir anahtar üret.

## 2. Vercel ortam değişkenleri

Vercel projesinde Settings → Environment Variables altına gir:

| Değişken | Değer |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` çıktısı |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Vercel'in verdiği alan adı |
| `APP_LOCALE` | `tr` |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Supabase pooler host'u |
| `DB_PORT` | `6543` |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | Supabase'in verdiği kullanıcı |
| `DB_PASSWORD` | Supabase veritabanı şifresi |
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
| `OPENAI_API_KEY` | Yapay zekâ stok analizi için (boş bırakılırsa analiz devre dışı kalır) |

`QUEUE_CONNECTION=sync` bilinçli: uygulama kuyruğa iş atmıyor ve Vercel'de
arka plan işçisi çalıştırılamaz.

## 3. Migration'lar

Vercel build adımında migration çalıştırmak güvenli değil (her dağıtımda
tetiklenir). Yerelden bir kez çalıştır:

```bash
DB_CONNECTION=pgsql DB_HOST=... DB_PORT=6543 DB_DATABASE=postgres \
DB_USERNAME=... DB_PASSWORD=... php artisan migrate --force

# Yönetici kullanıcıyı oluştur
DB_CONNECTION=pgsql ... php artisan db:seed --class=AdminSeeder --force
```

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
