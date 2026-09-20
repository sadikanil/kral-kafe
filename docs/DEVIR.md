# Devir notu — 20 Eylül 2026 (güncellendi: ikinci oturum)

Bir sohbet oturumunun sonunda yazıldı, ikinci oturumda §1 yeniden yazıldı. Yeni oturum **önce bunu**, sonra
`docs/UYGULAMA-PLANI.md`'yi okumalı.

Kod durumu §1'de.

---

## 1. Vercel dağıtımı — ÇÖZÜLDÜ (20 Eylül 2026, ikinci oturum)

Canlı site açılıyor: `https://kral-kafe-ten.vercel.app/` → giriş ekranı,
havuz logunda `DbHandler: Backend authenticated`, `sessions` tablosuna canlıdan
satır yazılıyor.

**Sebep tek değildi, üçtü** ve önceki oturumun "DB_PASSWORD'de satır sonu"
teşhisi üçünden yalnızca birincisiydi:

1. `DB_PASSWORD` sonunda satır sonu → artık `api/index.php` Laravel bootlanmadan
   tüm değişkenleri kırpıyor (`App\Support\OrtamTemizligi`, 2 test).
2. Şifre düzeltildikten sonra havuz istemciyi doğruladı ama arka plana eski
   şifreyle gitti (`DbHandler: Auth error 28P01`) → panelden **Reset database
   password** ile çözüldü; sıfırlama projeyi yeniden başlatıyor.
3. `DB_DATABASE=POSTGRES` (büyük harf) → `3D000 database "POSTGRES" does not
   exist` → `postgres` yapıldı.

**Nasıl bulundu — bir dahaki sefere ilk yapılacak:** Supabase **Pooler
(Supavisor) logları**. Uygulama havuza ulaşıyor mu, hangi adımda düşüyor,
satır satır yazıyor; başarılı bağlantıları da logluyor. Tablo ve jetonlu
teşhis sayfası yöntemi `DEPLOY.md §2.2`'de. Vercel bağlayıcısı takım
kapsamına yetkili değil (env/log uçları 403) — Vercel tarafı yalnızca
kullanıcı eliyle ya da bağlayıcı yeniden yetkilendirilerek okunabilir.

**Bu oturumda ayrıca:** Dalga 5'in `study_goals` migration'ı canlıda
uygulanmamıştı (18/19). Laravel'in üreteceği birebir SQL (`Blueprint::toSql`)
Supabase MCP ile uygulandı, RLS açık, `migrations` defterine batch 5 yazıldı.
**Bundan sonra her migration'dan sonra canlıda `migrations` tablosu ile
`database/migrations/` karşılaştırılmalı** — Dalga 7+ tabloları da aynı
şekilde unutulabilir.

Kod durumu: `main` = dal = son commit, yerel ve uzak eşit. Test takımı **161
test, 406 doğrulama, yeşil** (159 + OrtamTemizligi'nin 2'si). Yerel `.env`
yok; testler için `cp .env.example .env && php artisan key:generate` yeterli,
`.env`'den `DB_EMULATE_PREPARES` satırı silinmeli (PostgresReadinessTest onu
putenv ile geçemiyor — DEVIR §2'deki env() notuyla aynı sebep).

### Doğrulanmış canlı değerler

| Değişken | Değer |
|---|---|
| `DB_HOST` | `aws-0-ap-southeast-1.pooler.supabase.com` |
| `DB_PORT` | `5432` (session pooler) |
| `DB_DATABASE` | `postgres` — **küçük harf** |
| `DB_USERNAME` | `postgres.hxlklrwbeeddbajiectt` |
| `DB_CONNECTION` | `pgsql` |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` — **açma** |

Üretim ortamının tam dökümü (LOG_CHANNEL, UPLOAD_DISK, AWS_*) bu oturumda
doğrulanamadı; önizleme raporunda `LOG_CHANNEL=stack`, `UPLOAD_DISK=public`
görüldü ama o rapor önizleme ortamındı. `api/index.php` LOG_CHANNEL'ı yalnızca
tanımsızsa `stderr` yapar; panelde `stack` **tanımlıysa** ilk log yazımı
salt-okunur diskte 500 üretir. İlk fırsatta üretimde kontrol edilmeli.

---

## 2. Tekrar eden tuzak: Eloquent ve saat dilimi

**Bu oturumda üç kez ısırdı.** Zaman yazan ya da sorgulayan her yeni kodda önce
bu kontrol edilmeli.

Eloquent bir `Carbon`'u veritabanına yazarken ya da sorgu bağlamasına koyarken
**UTC'ye çevirmez** — kendi saat diliminin duvar saatini biçimler:

```
Carbon("2026-09-14 00:00", "Europe/Istanbul")  →  "2026-09-14 00:00:00"
                                               →  UTC sanılarak saklanır/karşılaştırılır
                                               →  üç saatlik sessiz kayma, hata yok
```

Ortaya çıktığı yerler:
1. **Dalga 4** — `SessionCloser::dueEnd()` kafe saatinde dönüyordu; oturum bitiş
   anları üç saat kayıyordu. Artık açıkça `->utc()`.
2. **Dalga 5** — `LocalDay` sınırları kafe saatinde dönüyordu; gün/hafta/ay
   sorguları kayıyordu. Artık UTC dönüyor, iki test zorunlu kılıyor.
3. **Dalga 4'ün `LiveController`'ı** — aynı hata anomali penceresindeydi, yedi
   günlük pencere maskelediği için ancak Dalga 5'te fark edildi.

Kural: **veritabanına giden her `Carbon` UTC olmalı.** Gösterim için
`->timezone(config('kafe.timezone'))`.

---

## 3. Bitmiş dalgalar ve taşıdıkları kararlar

`docs/UYGULAMA-PLANI.md` her dalganın ayrıntısını ve gerekçesini taşıyor. Özet:

- **Dalga 0** — `PostgresSecurity::lockDown()` (yeni tablolarda RLS zorunlu,
  muhafız test), `config/kafe.php` (09:00–21:00), `LocalDay`, TrustProxies,
  `Role` enum, CSS bileşenleri.
- **Dalga 1** — roller ikiden altıya. Sütun + doğrulama + formlar, **üçü birden**;
  biri atlanırsa "rol eklendi" hissi verip panel çalışmaz kalıyor.
- **Dalga 2** — `study_tables` + QR + yazdırma. `qr_code` **bir daha değişmez**
  (basılı etiketler). `QRCodeService` silindi (ölüydü), `QrImage` geldi.
- **Dalga 3** — `study_sessions`, `/masa/{kod}`, canlı ekran. Çift başlatma
  **veritabanında** engellendi: `UNIQUE (student_id) WHERE ended_at IS NULL`.
  Kısmi olması şart.
- **Dalga 4** — otomatik kapanış. Bitiş anı işin çalışma anına değil **oturumun
  verisine** bağlı: `min(kapanış, başlangıç + 12s)`. Bu yüzden idempotan ve cron
  opsiyonel. Tembel kapatma `SettleStaleSessions` middleware'inde.
- **Dalga 5** — `StudyStats` (gece yarısını aşan oturum hesap tarafında bölünür),
  `study_goals` (hedefin geçerlilik aralığı var — hedefi değiştirmek geçmişi
  yeniden yazmamalı), panelde süre/seri/hedef.

---

## 4. Sıradaki iş

**Dalga 6 — Veli — BİTTİ (20 Eylül 2026, ikinci oturum).** `student_parent`
canlıda uygulandı (batch 6, RLS + REVOKE, ledger satırı var). Kararlar
`UYGULAMA-PLANI.md` Dalga 6 altında. Canlıda kullanmak için: yönetici panelinde
rolü "Veli" olan bir kullanıcı aç → düzenle → "Bağlı Öğrenciler" işaretle. Veli
girişte `/veli`'ye iner.

**Dalga 6b — Deneme takvimi — BİTTİ (aynı oturum).** `exam_events` canlıda
uygulandı (batch 7). Yönetici: Deneme Takvimi menüsünden ekler; öğrenci ve veli
panelinde hatırlatıcı + takvim. Kararlar `UYGULAMA-PLANI.md` Dalga 6b.

**Dalga 6c (self adisyon) ve 6d (deneme PDF + yapay zeka) — BİTTİ (aynı
oturum).** `exam_reports` canlıda (batch 8). Self adisyon tablo istemez;
`Location::selfService()` ilk kullanımda sanal lokasyonu açar. 6d canlıda
çalışması için Vercel'de `OPENAI_API_KEY` ve `UPLOAD_DISK=s3` dolu olmalı
(stok analiziyle aynı anahtar). Takip dosyası: `YOL-HARITASI.md`.

**Sıradaki: Dalga 7 (paket/ödeme)** — `packages`, `package_items`,
`subscriptions`, `payments`; fatura tutarı **her zaman** `subscriptions.price`.
Sonra Dalga 8 (tüketimi masaya ve pakete bağlama; `Consumption::boot`
`total_price`'ı koşulsuz eziyor, kapsam mantığı oraya girmeli).

Açık kalan, kullanıcıya sorulması gereken bir şey yok. İki şey rafta:
- **IP kapısı** — kafenin IP'si ölçüldü, **dinamik** (TT ADSL havuzu). Beyaz
  liste kapı olarak kullanılamaz. Karar ve ölçüm `UYGULAMA-PLANI.md §2.1`'de.
  `config/kafe.php`'deki iki ayarı hiçbir kod okumuyor, bu dosyada yazıyor.
- **Anomali "gördüm" işareti** — şu an yedi günlük pencere var; kalıcı çözüm
  ertelendi.

---

## 5. Bu oturumda yapılan hatalar (tekrarlanmasın)

- **Veritabanı silindi.** "Temiz kurulum doğrulaması" diye `migrate:fresh --seed`
  çalıştırıldı; yerel SQLite'ta gerçek veri vardı ve gitti (kullanıcılar,
  ürünler). Gereksizdi de: test takımı zaten `:memory:` kullanıyor.
  **`migrate:fresh` bir daha çalıştırılmayacak.**
- **İki kez test kırmızıyken push edildi.** Komutlar zincirlendiği için sonuç
  görülmeden commit'e geçti. Test çalıştırma ve commit **ayrı adımlar** olmalı.
- **Vercel yapılandırması iki kez tahminle değiştirildi** ve ikisi de yanlış
  çıktı (`composer` PATH'te sanıldı; filesystem rota sırası). Üçüncüde ölçüldü
  ve otuz saniyede çözüldü. Görüş alanı dışındaki bir şey ölçülmeden
  değiştirilmemeli.
- **Arka plan analiz ajanları `tests/` altına sonda dosyaları bıraktı** ve bunlar
  farkında olmadan commit'lendi. Geçici dosyalar `.scratch/` altına yazılmalı.
  (Ajanların bulduğu iki gerçek hata kalıcı testlere taşındı.)
