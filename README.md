# Kral Kafe

Kafe için stok ve tüketim takip sistemi. Kullanıcılar raftaki QR kodu okutup
aldıkları ürünü kaydeder, yönetici tarafı stok sayımını fotoğraftan yapay zekâ
ile çıkarır ve ay sonunda kişi bazlı fatura üretir.

## Neler var

**Kullanıcı tarafı**
- QR kod okutarak lokasyon bazlı tüketim kaydı
- Kişisel tüketim geçmişi ve aylık özet
- Abonelik durumuna göre erişim kontrolü

**Yönetim tarafı**
- Kullanıcı, ürün ve lokasyon yönetimi
- Lokasyonlar için QR kod üretme ve toplu yazdırma
- Stok sayımı: fotoğraf yükleme → OpenAI ile ürün/adet tespiti → inceleme → onay
- Beklenen ve sayılan stok arasındaki tutarsızlıkların kaydı
- Aylık fatura oluşturma, özet ve detay CSV dışa aktarımı

## Teknolojiler

Laravel 12 · PHP 8.2 · Blade · Tailwind 4 + Vite · SQLite (yerel) /
PostgreSQL (üretim) · OpenAI `gpt-4o` görsel analizi

## Kurulum

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=AdminSeeder
npm install && npm run build
php artisan serve
```

Seeder, `admin@kralkafe.com` hesabını oluşturur ve **şifreyi rastgele üretip
konsola yazar** — şifre koda gömülü değildir. Kendi şifrenizi belirlemek için
`ADMIN_PASSWORD=... php artisan db:seed --class=AdminSeeder` kullanın.

Ardından `http://127.0.0.1:8000` adresinden giriş yapabilirsiniz.

### İsteğe bağlı ayarlar

| Değişken | Ne işe yarar |
|---|---|
| `OPENAI_API_KEY` | Stok fotoğrafı analizi. Boş bırakılırsa stok sayfaları çalışır, yalnızca yapay zekâ analizi devre dışı kalır. |
| `UPLOAD_DISK` | Yüklenen dosyaların gideceği disk. Yerelde `public`, serverless ortamda `s3`. |
| `MAIL_MAILER` | Şifre sıfırlama e-postası. Varsayılan `log`; gerçek gönderim için SMTP gerekir. |

## Testler

```bash
php artisan test
```

## Dağıtım

Vercel + Supabase kurulumu için [DEPLOY.md](DEPLOY.md).

## Katkı ve bakım

Bu deponun geçmişi bir kez yeniden yazıldı; eski remote'la ilgili bilinmesi
gereken tuzaklar ve kurtarma reçeteleri için
[docs/GIT-GECMISI.md](docs/GIT-GECMISI.md).

## Lisans

GNU General Public License v2.0 — bkz. [LICENSE](LICENSE).
