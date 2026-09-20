# Devir notu — 20 Eylül 2026

Bir sohbet oturumunun sonunda yazıldı. Yeni oturum **önce bunu**, sonra
`docs/UYGULAMA-PLANI.md`'yi okumalı.

Kod durumu: `main` = `dcf443b`, yerel ve uzak eşit. **159 test, 399 doğrulama,
hepsi yeşil.**

---

## 1. Tek açık iş: Vercel dağıtımı

Uygulama **hazır ve çalışıyor** — aynı kod yerelden Supabase'e karşı çalıştırıldığında
`/` isteği 302 ile `/giris`'e gidiyor. Vercel'de hâlâ 500 var ve sebebi **kod değil,
tek bir ortam değişkeni.**

### Kalan hata

`DB_PASSWORD` içinde **satır sonu var**. Kanıt: teşhis başlığı şifre uzunluğunu 15
gösterdi, yerelde çalışan şifre 13 karakter; ayrıca `header()` "new line detected"
uyarısı verdi — yani değerin içinde gerçekten `\n`/`\r` var. Vercel alanına
yapıştırırken satır sonu birlikte gelmiş.

**Yapılacak:** Vercel → Settings → Environment Variables → `DB_PASSWORD` alanını
temizle, şifreyi tırnaksız ve sonunda Enter'a basmadan yapıştır → Deployments →
⋯ → Redeploy (**build cache kapalı**).

### Doğrulanmış değerler (bunlar kesin, tahmin değil)

| Değişken | Değer |
|---|---|
| `DB_HOST` | `aws-0-ap-southeast-1.pooler.supabase.com` |
| `DB_PORT` | `5432` (session pooler) |
| `DB_USERNAME` | `postgres.hxlklrwbeeddbajiectt` |
| `DB_CONNECTION` | `pgsql` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — **açma** |

Bölge bağlanarak bulundu (`ap-southeast-1`, Singapur). DNS işe yaramaz, tüm bölge
uçları çözülüyor. `DB_USERNAME` proje referansını içermek **zorunda**: pooler düz
`postgres`'i reddeder, bu kural yalnızca doğrudan bağlantıda geçerli.

### Migration'lar Supabase'de koştu

Hepsi uygulandı ve Dalga 1–3 gerçek Postgres'te doğrulandı: rol sütunu genişledi
(`coach` yazılabiliyor → CHECK düştü), kısmi tekil indeks gerçekten kısmi,
RLS `study_tables` ve `study_sessions`'ta açık. `.env.supabase` yerelde dolu ve
çalışıyor; migration'lar oradan çalıştırıldı.

### Nasıl teşhis edildi (yöntem işe yaradı, tekrar kullanılabilir)

`APP_DEBUG=false` iken canlı hata görünmüyor. Çözüm: `api/index.php`'ye **sır
içermeyen** geçici bir yanıt başlığı koyup (hangi sürücü, anahtar var mı, host ne,
ham PDO bağlantısı ne diyor) `curl -sI` ile okumak. Laravel bootlanmadan önce
yazıldığı için uygulama 500 verse bile ulaşıyor.

**Uyarı:** başlık değeri satır sonu içeremez. Ortam değişkenini doğrudan başlığa
yazarken `preg_replace('/[^\x20-\x7E]/', ' ', $deger)` ile temizle — aksi halde
teşhis aracının kendisi sayfayı kırar (bu oturumda oldu).

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

**Dalga 6 — Veli (MVP #8):** `student_parent` bağı + salt okunur veli paneli.
Plan notu: **global scope kullanma.** Sonra Dalga 7 (paket/ödeme), Dalga 8
(tüketimi masaya ve pakete bağlama).

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
