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
| Kafe saatleri | `config/kafe.php`, **09:00–21:00** (kullanıcı onayladı, 19 Eyl 2026) | Kapatma formülü değerin kendisine değil varlığına bağlı |
| Kafe IP kapısı | Sert kapı **kalıcı olarak kapalı**; IP yalnızca anomali işareti | Kafenin IP’si ölçüldü: `88.238.46.100`, whois `TT ADSL-TTnet_dynamic_gay` → **dinamik**. Beyaz liste modem her resetlendiğinde kafeyi kilitler |
| Otomatik kapanış | **Tembel kapatma birincil**, cron yalnızca tazelik | Bitiş `min(kapanış anı, başlangıç + 12s)` ile veriden hesaplanabiliyor; cron kaçarsa sistem kendini onarır |
| Cron saati | `0 19 * * *` UTC | Kapanış 21:00’e çekilince 21 UTC yerel 00:00 olurdu; unutulan oturum 3 saat “açık” görünürdü. 19 UTC + Hobby sapması → yerel 22:00–22:59, her durumda kapanıştan sonra |
| `subscription_status` | Kalır, `subscriptions` paralel yaşar | 7 çağrı noktası + 5 test buna bağlı; erişim anahtarı ile ticari gerçek ayrı kavramlar |
| Paket kapsamı | `Consumption` **her zaman** oluşur, `covered_by_package=true` + `total_price=0` | Kayıt stok düşümünü ve 60 sn geri almayı sürüyor |
| Limit aşımı | Engelleme, ücretlendir — ama onaydan önce ekranda yaz | Engellemek öğrenciyi kasaya yönlendirir |
| `can_view_exams` | Varsayılan `false` | Gizliliği açıkken kapatmak, kapalıyken açmaktan pahalı |
| Koruma yardımcısı | `App\Support\PostgresSecurity::lockDown()` | Tek imza; statik muhafız test onu arayacak |

**İkisi de 19 Eylül 2026’da kapandı.** Kapanış 21:00 olarak onaylandı. IP kafe
ağından ölçüldü ve **dinamik** çıktı, dolayısıyla beyaz liste bir kapı olarak
kullanılamaz — bkz. §2.1.

---

### 2.1 Kafe ağı: ölçüm ve sonuç · 🗄️ RAFTA

> **Rafa kaldırıldı — 19 Eylül 2026, kullanıcı kararı.** Aşağıdaki 3. madde
> (çoğunluk IP'sine göre anomali işareti) **uygulanmayacak**. `config/kafe.php`
> içindeki `izinli_ipler` / `ip_zorunlu` ayarlarını hiçbir kod okumuyor.
> Ölçüm ve gerekçe, konu yeniden açılırsa diye burada duruyor.

19 Eylül 2026, kafe ağından ölçüldü:

```
IPv4      88.238.46.100
whois     TurkTelekom → "TT ADSL-TTnet_dynamic_gay"
ters DNS  PTR kaydı yok
yerel     192.168.1.127 (ev tipi modem)
```

`dynamic` etiketi Türk Telekom’un havuz işareti. IP modem yeniden başlayınca,
hat kopup gelince ve çoğu zaman kendiliğinden değişir. Sonuçlar:

1. **`KAFE_IPLER` beyaz listesi sert kapı olarak kullanılamaz.** Yazıldığı gün
   çalışır; ilk modem resetinde bütün kafe oturum açamaz hale gelir ve sebebi
   görünmez. Alan ve `ip_zorunlu` bayrağı kodda kalıyor — kurumsal sabit IP
   alınırsa tek satırla açılır.

2. **IP yalnızca oturum başlatmayı ilgilendirir.** Giriş, panel, istatistik,
   **veli paneli**, koç notları, paket ve ödeme akışları IP’ye hiç bakmaz. Veli
   evden izler; bu bir istisna değil, kapının kapsamının kendisi.

3. **Karşılaştırma sabit listeye değil çoğunluğa yapılır.** Oturum başlangıcında
   IP kaydedilir ve o gün açık olan oturumların çoğunluk IP’si ile karşılaştırılır;
   ayrık düşen oturum yönetici canlı ekranında işaretlenir. Yapılandırma
   gerektirmez ve TT adresi değiştirdiğinde kendiliğinden uyum sağlar.

4. **Sert engel yine de yok.** Öğrencinin telefonu mobil veride olabilir; masada
   otururken ayrık düşer. Kapıda durdurmak gerçek öğrenciyi cezalandırır,
   sahtekârı durdurmaz — basılı QR bir kez fotoğraflanıp evden okutulabilir.
   Asıl caydırıcı canlı ekran: personel boş masayı gözüyle görür.

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

### Dalga 1 — Roller (MVP #7) · ✅ bitti

Üç katman birden genişletildi; birini atlamak “rol eklendi” hissi verip yönetici
panelini çalışmaz bırakıyordu:

1. **Sütun** — `2026_09_19_180000_widen_users_role_column`: `string(20)`, Postgres’te
   `users_role_check` açıkça düşürülüyor. SQLite’ta da CHECK vardı; test kırmızıyken
   `SQLSTATE[23000] CHECK constraint failed: role` ile doğrulandı.
2. **Doğrulama** — `UserController` store/update: `Rule::enum(Role::class)`.
3. **Formlar** — create/edit/index artık `Role::cases()` üzerinde dönüyor.

Ayrıca: giriş sonrası yönlendirme `routes/web.php` ile `AuthenticatedSessionController`
içinde iki kez yazılmıştı; `Role::homeRoute()` altında birleştirildi ve ikisinin
aynı yere gittiği test edildi. Koç/öğretmen/veli/görevli için henüz panel yok, bu
yüzden hepsi `user.dashboard`’a iner — yönetim paneline yollamak doğrudan 403 olurdu.
Kenar çubuğu artık personele “Pasif” yazmıyor, rol adını gösteriyor.

### Dalga 2 — Masa (MVP #1) · ✅ bitti

`study_tables` + `StudyTable` + yönetici CRUD + QR ekranı + yazdırma sayfası.
Menüye **Masalar** eklendi.

**Basılı etiket kısıtı.** Bu dalganın çıktısı duvara yapıştırılacak; `qr_code`
bir daha değişmemeli. Alan `fillable` değil, yalnızca oluşturulurken üretiliyor
ve testi var — kırılırsa anlamı "masa adını değiştirince duvardaki QR'lar öldü".

**Kaynak belgedeki üç sütun ertelendi**, gerekçeleri migration başlığında:
`status` (oturumdan türetilebilir, ikinci doğruluk kaynağı olurdu),
`assigned_student_id` (anlamı Dalga 7'deki paketle geliyor),
`location_id` (§11 #10 cevaplanmadan tasarlanamaz).

**`QRCodeService` silindi.** Hiçbir yerden çağrılmıyordu: üç blade servisi atlayıp
adresi kendi kuruyordu. Ayrıca `Location`'a bağlıydı ve istek anında
`file_get_contents` ile dış sunucudan indirme yapıyordu. Yerine saf
`App\Support\QrImage` geldi; üç lokasyon blade'i de ona bağlandı ve statik bir
muhafız test elle kurulmuş adresi yakalıyor.

**Yol üstünde bulunanlar:**

- `is_active` `create()` sonrası modelde `null` dönüyordu (DB varsayılanı örneğe
  yansımıyor) — "masa açık mı" sorusu sessizce yanlış cevaplanırdı.
- `welcome.blade.php` **silindi**: tamamen Tailwind sınıflarıyla yazılmıştı, proje
  Tailwind kullanmıyor ve `/` zaten yönlendiriyor — erişilemez, stilsiz bir sayfa.
- `text-end`, `align-items-end`, `ml-2` bu projede tanımsızdı. Elle yazılan CSS'te
  Bootstrap adı yazmak hata vermez, sessizce hiçbir şey yapar. `align-items-end`
  ve `ml-2` eklendi; `text-end` → `text-right`. Buna karşı yeni muhafız test:
  blade'lerdeki her sınıf `app.css` ya da bir `<style>` bloğunda tanımlı olmak
  zorunda (`js-` önekli kancalar muaf). Muhafız sınandı: `float-end` enjekte
  edildi, dosya adıyla yakalandı.

**Yazdırma sayfası uyarısı:** `/masa/{kod}` adresi Dalga 3'te geliyor. O ana kadar
yazdırma ve QR ekranları "henüz yayında değil" uyarısı gösteriyor. Uyarı rotanın
**varlığına** bağlı — Dalga 3 rotayı ekleyince kendiliğinden kaybolur, kimsenin
eski bir metni silmesi gerekmez.

**Erişim:** §7 matrisinde masa yönetimi görevliye de açık ama `AdminMiddleware`
yalnızca yöneticiyi geçiriyor; görevli erişimi kendi paneliyle gelecek.

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
| 1 · Roller | ✅ Bitti |
| 2 · Masa | ✅ Bitti |
| 3 · Oturum + canlı ekran | 🔄 Sırada |
| 4 · Otomatik kapanış | ⬜ |
| 5 · Süre, devamlılık, hedef | ⬜ |
| 6 · Veli | ⬜ |
| 7 · Paket ve ödeme | ⬜ |
| 8 · Tüketim bağlama | ⬜ |
