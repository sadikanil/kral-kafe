# MVP Uygulama Planı

> [GUNCELLEMELER.md](GUNCELLEMELER.md) **ne** yapılacağını anlatıyor.
> Bu dosya **nasıl ve hangi sırayla** yapılacağını anlatıyor.
>
> Kaynak: kod tabanının beş bağımsız analizi + tamamlayıcılık denetimi.
> Her teknik iddia gerçek dosya okunarak, çoğu gerçek Supabase üzerinde
> çalıştırılarak doğrulandı.

---

## 1. Önce bilinmesi gerekenler

Analiz sırasında, planın kendisini değiştiren beş şey çıktı.

### 1.1 `enum()->change()` Postgres'te migration'ı çökertir

Rol genişletmesinin bariz yolu `$t->enum('role', [...6 rol...])->change()`.
Gerçek Supabase'de denendi (TEMP tablo + ROLLBACK, public şemaya dokunmadan):

```
SQLSTATE[42601]: syntax error at or near "check"
```

Postgres'te `ALTER COLUMN ... TYPE` bir CHECK cümlesi kabul etmiyor. Yani bu
satır `products.unit_type`'taki gibi "sessizce yanlış" değil — **migration
doğrudan patlar ve üretim yarı göçmüş kalır.**

`string('role')->change()` ise komutu geçiriyor ama `users_role_check` olduğu
yerde duruyor; sonra `'coach'` yazmak `SQLSTATE[23514]` veriyor. Sessiz tuzak.

**Karar:** yeni migration `role` sütununu `string(20)` yapar, Postgres'te CHECK
kısıtını **açıkça düşürür**, yerine yenisini koymaz. Doğrulama PHP tarafında
`App\Enums\Role` + `Rule::enum()` ile yapılır.

*Neden kısıt geri konmuyor:* SQLite'ta `->change()` tabloyu baştan yazarken yan
etki olarak komşu `subscription_status` kısıtını da düşürüyor. Kısıtı korumak,
her rol eklemesinde iki sürücü için ayrı elle SQL yazmayı **ve** komşu sütunun
kısıtını elle geri kurmayı zorunlu kılar. Sürekli bakım borcu, karşılığı yok.

### 1.2 Yeni tablolar RLS korumasını miras almıyor

Beş analizin beşi de bağımsız olarak buldu; ben de gerçek veritabanında test
tablosu açarak doğruladım:

| Katman | Yeni tabloda | Neden |
|---|---|---|
| `anon`/`authenticated` yetkileri | ✅ otomatik kapalı | `ALTER DEFAULT PRIVILEGES` geleceğe taşıyor |
| RLS | ❌ **kapalı doğuyor** | Postgres'te "varsayılan RLS" diye bir şey yok |

Planlanan ~15 tablonun en hassasları (`study_sessions`, `student_parent`,
`coach_notes`) korumasız doğacak. Yetki olmadığı için pratikte erişilemez, ama
biri Supabase panelinden tek tıkla yetki verirse tablo anında açılır.

**Bu yüzden ilk iş kod değil, koruma.**

### 1.3 Zaman dilimi hatası mevcut ve büyüyecek

`config/app.php` → `'timezone' => 'UTC'` (env'den bile okunmuyor), ve kod
tabanında `Europe/Istanbul` geçen **tek bir satır yok**. `app/` altında 39 adet
`whereMonth`/`whereYear`/`whereDate` çağrısı var.

Türkiye UTC+3 olduğu için günün %12,5'i (yerel 00:00–03:00) yanlış güne yazılıyor.
Bugün bu bir faturalama hatası; çalışma oturumlarında **günlük toplamları
doğrudan yanlış yapar**.

**Karar:** `config/app.php`'ye dokunulmayacak — orası çerçeve ayarı ve
değiştirilirse Postgres'teki mevcut `timestamp without time zone` satırları
sessizce yeniden yorumlanır. Yeni `config/kafe.php` içinde `timezone`,
`acilis`, `kapanis` tutulur; pencere hesabı Carbon ile yapılıp `whereBetween`
ile sorgulanır.

### 1.4 Oturum 4–6 saat sürüyor, giriş çerezi 2 saat

`SESSION_LIFETIME=120`. Öğrenci 09:00'da oturum açar, 14:00'te bitirmek için
telefonunu açar — giriş çerezi 11:00'de ölmüştür ve `/masa/{qr}` `auth`
arkasındadır. Öğrenci giriş ekranına düşer, oturumunu kapatamaz, kayıt
otomatik kapanmaya kalır.

**Karar:** `SESSION_LIFETIME=900` (15 saat — kafe günü 840 dakika, biraz pay).
`DEPLOY.md` tablosuna eklendi ve bu ilişkiyi koruyan bir test yazıldı:
kafe saatleri uzarsa test uyarır.

### 1.5 Kaynak belgede iki düzeltme gerekiyor

- **§1 "Tailwind 4 + Vite" yanlış.** `resources/css/app.css`'te tek bir
  `@import`/`@apply`/tailwind satırı yok; `@vite` yalnızca hiçbir rotası olmayan
  `welcome.blade.php`'de geçiyor. Gerçek: **elle yazılmış `public/css/app.css`**
  (1371 satır). Yanlış şablon düzenlenirse stiller sessizce hiçbir şey yapmaz.
- **`migrate:fresh --env=supabase` asla çalıştırılmayacak.** Üretim veritabanını
  siler; daha sinsisi, lokasyon `qr_code`'ları migration'da `Str::random(8)` ile
  üretildiği için **kafede asılı basılı QR'lar sessizce geçersiz olur.**
  Üretimde yalnızca `migrate --force` + salt okunur kontrol sorguları.

---

## 2. Verilen kararlar

Belgedeki §11 açık sorularının onunda da ilerlemeyi durduran bir şey yok;
aşağıdaki varsayılanlarla devam ediliyor. Fikrin farklıysa söyle, dönmek ucuz.

| Konu | Karar | Gerekçe |
|---|---|---|
| `users.role` biçimi | `string(20)`, DB kısıtı yok, doğrulama `App\Enums\Role` | §1.1 |
| Çoklu rol | MVP'de tek rol | §7 matrisi tek rollü; iki şapkalı kişiye `admin` verilir |
| Masa tablosu adı | `study_tables` / model `StudyTable` | `Schema::create('tables', function (Blueprint $table)` okunmaz; `Table` modeli HTML tablo kavramıyla karışır |
| Oturum bitiş sebebi | Tek sütun `end_reason` | `switched` ile `auto_closed` aynı anda olamaz; iki boolean temsil edilemez durum üretir |
| Kafe saatleri | `config/kafe.php`, varsayılan 09:00–23:00 | Kapatma formülü değerin kendisine değil varlığına bağlı |
| Kafe IP kapısı | Varsayılan **açık** (fail-open) + anomali işareti | IP bilinmeden sert kapatılırsa ilk gün kimse oturum açamaz |
| Otomatik kapanış | **Tembel kapatma birincil**, cron yalnızca tazelik | Bitiş `min(kapanış anı, başlangıç + 12s)` ile veriden hesaplanabiliyor; cron kaçarsa sistem kendini onarır |
| Cron saati | `0 21 * * *` UTC | Hobby'nin ±59 dk sapması dahil yerel 00:00–00:59'a düşer, her durumda kapanıştan sonra |
| `subscription_status` | Kalır, `subscriptions` paralel yaşar | 7 çağrı noktası + 5 test buna bağlı; erişim anahtarı ile ticari gerçek ayrı kavramlar |
| Paket kapsamı | `Consumption` **her zaman** oluşur, `covered_by_package=true` + `total_price=0` | Kayıt stok düşümünü ve 60 sn geri almayı sürüyor |
| Limit aşımı | Engelleme, ücretlendir — ama onaydan önce ekranda yaz | Engellemek öğrenciyi kasaya yönlendirir |
| `can_view_exams` | Varsayılan `false` | Gizliliği açıkken kapatmak, kapalıyken açmaktan pahalı |
| Koruma yardımcısı | `App\Support\PostgresSecurity::lockDown()` | Tek imza; statik muhafız test onu arayacak |

**Senin onayına değer iki tanesi:** kafe kapanış saati (23:00 varsayıldı) ve
kafenin sabit IP'si olup olmadığı. İkisi de işi durdurmuyor ama doğru değerle
çalışmak daha iyi.

---

## 3. Dalgalar

Her dalga tek başına deploy edilebilir ve sistemi çalışır durumda bırakır.

### Dalga 0 — Temel (kod yazmadan önce)

| | İş | Neden önce |
|---|---|---|
| 0-A | `PostgresSecurity::lockDown()` + statik muhafız test | ~15 tablonun hepsi buradan geçecek; sonraya bırakmak 3. migration'da unutulur |
| 0-B | `config/kafe.php` + `App\Support\LocalDay` + TrustProxies | Zaman ve IP temeli; oturum işinin ön koşulu |
| 0-C | `App\Enums\Role` + Policy iskeleti + `AdminMiddleware` red testi | Bugün tek bir 403 iddiası yok — reddetme kolu kanıtsız |
| 0-D | CSS: `.progress`, `.session-timer`, `.empty-state` | Mevcut CSS'te ilerleme çubuğu, sayaç ve boş durum sınıfı yok |

### Dalga 1 — Roller (MVP #7)

Rol genişletmesi, rol-farkındalı yönlendirme ve menü. `ActiveSubscription`
muafiyeti tersine çevrilir (abonelik yalnızca `student`'ı ilgilendirir).
`UserController`'daki `in:student,admin` doğrulaması genişletilir — unutulursa
yönetici paneli koç oluşturamaz ve hata migration'a yorulur.

### Dalga 2 — Masa (MVP #1) · Dalga 1 ile paralel

`study_tables` + QR. Üç blade'de gömülü olan `api.qrserver.com` adresi tek bir
yardımcıya çıkarılır (masa eklenince beş kopya olurdu). Ölü `QRCodeService`
ya arayüze çıkarılır ya silinir.

### Dalga 3 — Oturum + canlı ekran (MVP #2, #9) · en büyük iş

`study_sessions`, `/masa/{qr}` akışı, yönetici canlı ekranı. Uç durumlar
FEATURE 1 tablosundaki gibi. Çift başlatmaya karşı **kısmi tekil indeks**
(uygulama katmanı yetmez: öğrenci iki kez basar, mobilde POST yeniden gönderilir).

### Dalga 4 — Otomatik kapanış (MVP #3)

`SessionCloser::closeStale()` — deterministik ve idempotent
(`whereNull('ended_at')->update(...)`). Aynı servis hem tembel kapatma, hem
artisan komutu, hem cron ucu tarafından çağrılır.

### Dalga 5 — Süre, devamlılık, hedef (MVP #4, #6, #5)

"Gelinen gün" tanımı: *o yerel güne ≥ `config('kafe.sayilabilir_dakika')`
düşen en az bir oturum.* Gece yarısını aşan oturum tek satır kalır, güne bölme
**hesap tarafında** yapılır.

### Dalga 6 — Veli (MVP #8)

`student_parent` + salt okunur panel. Veli sınırı **global scope ile
kurulmayacak** — görünmez şekilde admin toplamlarına sızar. Tekil kayıt için
policy, listeler için açık scope, ikisi de `accessibleStudentIds()`'e delege eder.

### Dalga 7 — Paket ve ödeme (MVP #10, #11) · 1–6 hattına paralel

`packages`, `package_items`, `subscriptions`, `payments`. Fatura
`package_amount` değerini **her zaman `subscriptions.price`'tan** okur,
`packages.monthly_price`'tan değil — katalog fiyatı değişince geçmiş faturalar
yeniden yazılmasın.

### Dalga 8 — Tüketimin masa ve pakete bağlanması (MVP #12) · en son

`consumptions.table_id` + `covered_by_package`. `Consumption::boot` içindeki
`total_price` hesabı koşulsuz eziyor — kapsam mantığı oraya girmeli.

---

## 4. Her dalgada geçerli kurallar

- **`$table->enum()` kullanılmayacak.** Postgres'te varchar + isimsiz CHECK
  üretir ve `->change()` kısıtı yerinde bırakır.
- **Her yeni tablo `PostgresSecurity::lockDown()`'dan geçecek.** Muhafız test
  bunu zorunlu kılar.
- **`whereMonth`/`whereYear`/`whereDate` yeni kodda kullanılmayacak.**
  Pencere Carbon ile yerel saatte kurulur, `whereBetween` ile sorgulanır.
- **Her yeni tablo ile birlikte factory yazılır.** Bugün yalnızca `UserFactory`
  var; testler `Location::create([...])` ile elle kuruyor.
- **Her yeni form ile birlikte `lang/tr/validation.php` `attributes` girdisi.**
  Yoksa mesajda İngilizce alan adı çıkar.
- **Yeni controller'lar kökte değil alt klasörde.** Muhafız testteki
  `Http/Controllers/**/*.php` deseni kökü taramıyor.
- **Doğrulama:** yerelde `php artisan test` (SQLite), üretimde
  `migrate --force` + salt okunur kontrol. `migrate:fresh` üretime asla.

---

## 5. Durum

| Dalga | Durum |
|---|---|
| 0-A · PostgresSecurity | ✅ Bitti |
| 0-B · Zaman + IP temeli | ✅ Bitti |
| 0-C · Rol enum + yetki reddi testi | ✅ Bitti |
| 0-D · CSS bileşenleri | ✅ Bitti |
| 1 · Roller | 🔄 Sırada |
| 2 · Masa | ⬜ |
| 3 · Oturum + canlı ekran | ⬜ |
| 4 · Otomatik kapanış | ⬜ |
| 5 · Süre, devamlılık, hedef | ⬜ |
| 6 · Veli | ⬜ |
| 7 · Paket ve ödeme | ⬜ |
| 8 · Tüketim bağlama | ⬜ |
