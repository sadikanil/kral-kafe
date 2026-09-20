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

### Dalga 3 — Oturum + canlı ekran (MVP #2, #9) · ✅ bitti

`study_sessions`, `StudySessionService`, `/masa/{kod}` okutma akışı, öğrenci
panelinde canlı kart, yönetici canlı ekranı (`/yonetim/canli`).

**Çift başlatma veritabanında engellendi.** Uygulama katmanı tek başına yetmez:
iki eşzamanlı istek ikisi de "açık oturum yok" görüp ikisi de insert eder.
Kısmi tekil indeks — `UNIQUE (student_id) WHERE ended_at IS NULL` — hem SQLite
hem Postgres'te çalışıyor. **Kısmi** olması şart: koşulsuz bir tekil indeks
öğrencinin günde yalnızca bir kez çalışmasına izin verirdi. İkisinin de testi
var, ikincisi indeksin tanımını okuyup `ended_at` şartını arıyor.

**Uç durumlar (FEATURE 1 tablosu):**

| Durum | Uygulanan davranış |
|---|---|
| Aynı masada tekrar başlat | Mevcut oturum döner, yeni kayıt yok, hata yok |
| Başka masada QR okutma | Eski kapanır `switched`, yeni açılır — **tek transaction** |
| Yarışı kaybeden eşzamanlı istek | Kısıt hatası yakalanır, kazananın oturumu gösterilir |
| 2 dakikadan kısa oturum | Kaydedilir, `countable()` kapsamı dışında kalır |
| Kapalı masa | Başlatılamaz, hata mesajı |
| Oturumu olan masayı silme | Engellenir; masa silinmez, **kapatılır** |

**Bitiş sebebi tek sütun** (`end_reason`), iki boolean değil: `switched` ile
`auto_closed` aynı anda olamaz, iki bayrak temsil edilemeyen durumlar üretirdi.
`App\Enums\SessionEndReason` dört değeri de tanımlıyor; `auto_closed` ve
`over_limit` Dalga 4'te yazılacak.

**Bitirme masaya bağlı değil** (`POST /oturum/bitir`): spec "aynı QR **veya
panelden**" diyor. İki ayrı rota iki ayrı kural demek olurdu.

Kaynak belgedeki `source` ve `note` sütunları ertelendi — bu dalgada hiçbir şey
yazmıyor ve okumuyor.

**Dalga 2'nin yazdırma uyarısı kendiliğinden kalktı:** `table.scan` rotası
doğunca `@unless(Route::has(...))` sustu. Etiketler artık basılabilir.

### Dalga 4 — Otomatik kapanış (MVP #3) · ✅ bitti

`SessionCloser`, `SettleStaleSessions` middleware, `oturum:kapat` komutu,
yönetici canlı ekranında anomali bölümü.

**Tasarımın tek kritik özelliği:** bitiş anı, işin ne zaman çalıştığına değil
oturumun kendi verisine bağlı — `min(kapanış anı, başlangıç + azami saat)`.
Üç sonucu var: iki kez çalıştırmak ikinci kez hiçbir şey değiştirmez; Vercel
Hobby cron'unun ±59 dk sapması süreyi etkilemez; cron **hiç** çalışmasa bile
bakan ilk kişi aynı sonuca varır. `ended_at = now()` olsaydı üçü de bozulurdu —
öğrencinin süresi işin çalışma anına göre uzardı.

**Planın bir maddesi yanlıştı ve düzeltildi.** Plan `whereNull('ended_at')->update(...)`
diyordu, yani tek toplu UPDATE. Mümkün değil: her satırın bitiş anı kendi
`started_at`'ine bağlı ve "yerel saatle bir sonraki 21:00" ifadesi SQLite ile
Postgres'te bambaşka yazılır. PHP tarafında satır satır hesaplanıyor; açık
oturum sayısı kafe kapasitesiyle sınırlı olduğu için maliyeti yok.

**Testin yakaladığı gerçek hata:** Eloquent bir `Carbon`'u yazarken **UTC'ye
çevirmez**, kendi saat diliminin duvar saatini biçimleyip saklar. `dueEnd()`
kafe saatinde dönüyordu; Istanbul 21:00 taşıyan bir Carbon veritabanına
"21:00" diye yazılıp UTC 21:00 (yerel 00:00) olarak geri okunuyordu — **üç
saatlik sessiz kayma.** `dueEnd()` artık açıkça UTC dönüyor. Dalga 5'te zaman
yazan her yerde aynı tuzak var.

**Tembel kapatma middleware ile,** üç ayrı kontrolcü çağrısıyla değil: açık
oturum okuyan yol sayısı artıyor (canlı ekran, öğrenci paneli, QR ekranı,
Dalga 5'te raporlar) ve biri unutulursa kullanıcı bayat veri görür — hata
mesajı yok, yalnızca yanlış sayı. Middleware ayrıca oturum **başlatmayı**
kurtarıyor: kısmi tekil indeks yüzünden unutulmuş bir oturum öğrencinin yeni
oturum açmasını engelliyordu, ve `start()` onu masa değişimi sanıp `switched`
etiketliyordu. Artık doğru şekilde `auto_closed` oluyor — testi var.

**Anomali sessiz kalmıyor:** `over_limit` kapanışlar canlı ekranda ayrı bir
bölümde. Sessizce kapatmak kuralı uygulamak sayılmaz. Normal kapanışlar orada
görünmüyor — her gün uyarı göstermek uyarıyı öldürür.

**Cron zorunlu değil.** `Schedule::command('oturum:kapat')` yazıldı (`0 19 * * *`
UTC, kafe saatiyle 22:00). Gerçek cron'u olan her sunucuda çalışır. Vercel'de
serverless olduğu için ayrı bir HTTP ucu gerekirdi; deterministik tasarım
sayesinde **gerekmediği** için açılmadı — sırf tazelik uğruna kimliği kontrol
edilmesi gereken yeni bir dış uç eklemek, kazandırdığından fazlasını riske atar.

**Bağımsız analizin bulduğu iki hata (sonradan düzeltildi).** Dalga 4 için arka
planda çalıştırılan çok ajanlı analiz, adversaryal doğrulamayı geçen iki gerçek
kusur buldu:

1. **Bir oturum iki kez kapatılabiliyordu.** İki kapatma yolu da açık oturumu
   önce okuyup sonra yazıyordu. Arada diğeri kapatmış olabilir; koşulsuz UPDATE
   onun kapanışını eziyordu. Manuel kapanış otomatiği ezerse **anomali kaydı
   siliniyor** ve süre şişiyordu (20:00'de kapanmış 720 dakikalık bir süre
   aşımı, 20:30'da gelen bayat modelle 750 dakikalık *normal* kapanışa
   dönüşüyordu). İkisi de sessiz: hata yok, yalnızca yanlış sayı. Karar
   veritabanına taşındı — `StudySession::closeOnce()` yalnızca
   `whereNull('ended_at')` iken yazıyor.

2. **Anomali ertesi gün görünmüyordu.** Süre aşımı bölümü yalnızca *bugün*
   kapanmış oturumları listeliyordu. Yönetici her gün canlı ekrana bakmak
   zorunda değil; hafta sonuna düşen her anomali hiç görülmeden kayboluyordu —
   yani "yöneticiye anomali olarak düşer" kuralı aslında uygulanmıyordu. Pencere
   yedi güne çıkarıldı.

   *Daha doğrusu:* anomaliye "gördüm" işareti koyulabilen bir akış. Yedi günlük
   pencere de sessizce kapanabilir. Kalıcı çözüm Dalga 7 sonrasına bırakıldı;
   mevcut `discrepancy_logs` çözümleme akışı örnek alınabilir.

### Dalga 5 — Süre, devamlılık, hedef (MVP #4, #6, #5) · ✅ bitti

`StudyStats`, `study_goals` + `StudyGoal`, `Duration`, öğrenci panelinde süre /
seri / hedef ilerlemesi, yönetici formunda haftalık hedef alanı.

**Gece yarısını aşan oturum tek satır kalıyor**, güne bölme hesap tarafında:
22:00–01:30 arası bir oturum birinci güne 120, ikinci güne 90 dakika yazıyor.
Veriyi bölerek saklamak oturumu parçalar ve "kaç oturum açtın" sorusunu
cevaplanamaz hale getirirdi.

**Hedefin geçerlilik aralığı var.** Tek satırlık bir `users.haftalik_hedef`
sütununun yapamayacağı şey: koç hedefi yükseltince geçmiş haftaların "tuttu mu"
cevabı değişmemeli. Hedef değiştirilmiyor, **yenisiyle değiştiriliyor** — eski
satır bugünden kapanır, yeni satır açılır. Testi var.

**Yol üstünde bulunan hata — mevcut kodu da etkiliyordu.**

`LocalDay` sınırları kafe saatinde dönüyordu. Eloquent bir `Carbon`'u sorgu
bağlamasına koyarken UTC'ye **çevirmez**, kendi saat diliminin duvar saatini
biçimler: `2026-09-14 00:00+03:00` sorguya `"2026-09-14 00:00:00"` diye gidip
UTC sütunuyla karşılaştırılıyordu — üç saatlik sessiz kayma, hata yok.

Yerel 01:00'de biten bir oturum bu yüzden hiçbir güne sayılmıyordu. Aynı hata
Dalga 4'te gönderilen `LiveController`'ın anomali penceresinde de vardı; orada
yedi günlük pencere maskelediği için fark edilmemişti. Sınırlar artık UTC
dönüyor ve iki test bunu zorunlu kılıyor.

Bu, aynı tuzağın **üçüncü** ortaya çıkışı (Dalga 4'te `dueEnd()`, burada sorgu
bağlamaları). Zaman yazan ya da sorgulayan her yeni kodda önce bu kontrol
edilmeli.

İkinci kusur: sınırlar kapalı aralıktı ve `endOfDay()` 23:59:59 verdiği için
her gün bir dakika eksik sayılıyordu. Pencereler artık yarı açık `[baş, sonraki baş)`
ve sınırlar `LocalDay` üzerinden alındığı için hesap DST'ye de dayanıklı.

**Seri (devamlılık):** bugün henüz gelinmemiş olması seriyi bozmaz — aksi halde
seri her sabah sıfırlanır ve özellik anlamını yitirirdi.

### Dalga 6 — Veli (MVP #8)

`student_parent` + salt okunur panel. Veli sınırı **global scope ile
kurulmayacak** — görünmez şekilde admin toplamlarına sızar. Tekil kayıt için
policy, listeler için açık scope, ikisi de `accessibleStudentIds()`'e delege eder.

**Yapıldı (20 Eylül 2026).** Uygulanan kararlar:

- `student_parent` çoka çok (anne + baba). `UNIQUE (student_id, parent_id)`,
  `created_by` nullable. Pivot modeli `StudentParent` yalnızca factory için var.
- `User::accessibleStudentIds()` tek kaynak: yönetici `null` (sınırsız), veli
  bağlı öğrenciler, öğrenci kendisi, koç/öğretmen/görevli **boş** (panelleri
  gelince genişler). `UserPolicy::viewStudy` ve `User::visibleTo()` buna
  delege eder; ikisi de kuralı tekrar yazmaz.
- `/veli` yalnızca GET; `role:parent` middleware'i (`EnsureRole`, genel).
  Abonelik middleware'i yok — abonelik öğrencinin. Bir test rotaların hiçbirinde
  yazma yöntemi olmadığını doğrular.
- Panelde tüketim/para **yok**; FEATURE 4'ün MVP listesi (geliş/çıkış,
  gün/hafta/ay, seri, hedef). Esikten kısa oturumlar veliye gösterilmez.
- Bağ yönetici formunda kurulur (veli tarafında öğrenci listesi, öğrenci
  tarafında veli listesi). Gizli `student_ids` alanı "hiçbiri seçili" ile
  "bölüm yoktu"yu ayırır; bölüm yoksa bağa dokunulmaz. `sync()` yerine
  attach/detach: mevcut satırın `created_by`'ı ezilmesin.

### Dalga 6b — Deneme sınavı takvimi (eklendi: 20 Eylül 2026)

Kullanıcı isteği: "deneme sınavı takvimi hatırlatıcı ve takvim görünümü".

- `exam_events` kafe geneli, **sonuç tutmaz** (FEATURE 6'nın `mock_exams`'i
  ayrı gelecek). `exam_date` DATE + `starts_at` "HH:MM" string: deneme bir UTC
  anı değil, bir gün ve duvar saati; timestamp olsaydı gün sınırı kayardı.
- "Kaç gün kaldı" kafe gününe göre (`LocalDay::today()`); test UTC 21:30 →
  Istanbul ertesi gün durumunu kapsıyor.
- Yönetici CRUD `/yonetim/denemeler`; öğrenci ve veli **tek kontrolcü, tek
  view** (`exams.calendar`, `@extends($layout)`), iki rota. Takvim ızgarası
  `ExamCalendar::weeks()` — üç ekranda aynı.
- Hatırlatıcı panel içi (`exams._hatirlatici`): deneme yoksa hiç çizilmez;
  `kafe.deneme_hatirlatma_gun` (7) ve altı uyarı rengi. E-posta/WhatsApp yok.
- Bu dalgada `StudyGoalTest` UTC 21:00 sonrası kızarıyordu (`now()` ile kafe
  günü ayrışıyor); test `LocalDay::today()`'e çevrildi.

### Dalga 6c — Self adisyon (eklendi: 20 Eylül 2026)

Öğrenci QR okutmadan panelden sistemde tanımlı ürünü kendi hesabına ekler.

- `consumptions.location_id` NOT NULL ve her ekran `location->name` okuyor;
  sütunu nullable yapmak yerine **sanal lokasyon** `Location::selfService()`
  (`qr_code = SELF-ADISYON`, `is_active = false`). Kapalı olduğu için stok
  sayımı, QR yazdırma ve panel sayaçları onu hiç görmez.
- Self adisyon **stok düşmez** (ProductLocation'a dokunmaz), yalnızca hesaba
  yazar. Aylık fatura, geçmiş, raporlar değişiklik olmadan bunu da sayar.
- Form tabanlı (JSON değil); geri alma `Consumption::canUndo()` (60 sn) ve
  sahiplik kontrolüyle.

### Dalga 6d — Deneme sonuç PDF'i + yapay zeka analizi (eklendi: 20 Eylül 2026)

- `exam_reports`: öğrenci başına PDF (nesne depolamada, uuid yol) + `analysis`
  JSON + `status` (pending|done|failed). Takvimdeki denemeye isteğe bağlı bağ.
- `ExamReportAnalyzer`: PDF'i OpenAI Chat Completions'a **dosya olarak**
  gönderir (metin çıkarımı yok; tablolar ve taranmış sayfalar için). JSON şema
  zorunlu; `normalize()` view'ın her anahtarı varsayabilmesini sağlar. Prompt
  kişilik/motivasyon yorumunu açıkça yasaklar.
- Analiz yükleme isteğinin içinde çalışır (kuyruk sync); başarısızsa dosya
  kalır, durum `failed`, "Yeniden analiz et" ile tekrar. `vercel.json`
  `maxDuration: 60` bu yüzden.
- Öğrenci salt okunur görür; yetki `ExamReportPolicy::view` →
  `User::canViewStudent`. Veli rotası yok (karar #2: `can_view_exams` gelince).
- İndirme controller üzerinden (`Storage::response`); bucket herkese açık olsa
  bile yol tahmin edilemez.

### Dalga 7 — Paket ve ödeme (MVP #10, #11) · ✅ bitti (21 Eylül 2026)

`packages`, `package_items`, `subscriptions`, `payments`,
`monthly_bills.package_amount`. Uygulanan kararlar:

- **Fiyat kopyalanır.** Atama anında `packages.monthly_price` →
  `subscriptions.price`; yönetici formda değiştirebilir. Katalog sonradan
  değişince abonelik ve fatura değişmez (test var).
- **Fatura paket tutarı = o ayda BAŞLAYAN aboneliklerin fiyat toplamı.**
  Aylara bölme yok; çok aylık abonelik başladığı ayda yazılır. `total_amount`
  tüketim toplamı olarak kaldı (CSV ve ekranlar öyle okuyordu); genel toplam
  `grandTotal()`. Raporlar sayfasının okuduğu ama var olmayan
  `formatted_total` / `period_name` accessor'ları eklendi.
- **Ödeme durumu türetilir.** `paid` (bakiye 0), `overdue` (bakiye var ve
  bugün > başlangıç + `kafe.odeme_vadesi_gun`), yoksa `pending`;
  `Subscription::syncPaymentStatus()` liste ve panel açılışında çalışır.
  `cancelled` elle verilir ve dokunulmaz; iptal aboneliğe ödeme yazılmaz.
- **Paket atanan öğrenci içeri alınır:** `users.subscription_status = active`,
  `subscription_start/end` abonelik tarihleri. İptal öğrencinin durumunu
  otomatik kapatmaz; yönetici kullanıcı formundan kapatır.
- **Kapsam kalemleri yalnızca tanım.** `package_items` (ürün, adet, dönem;
  adet boş = sınırsız) Dalga 8'de tüketime uygulanır. `usage_window`
  ertelendi.
- Paket silinmez, kapatılır (`restrictOnDelete`, masalarla aynı karar).
- Öğrenci panelinde paket + ödeme rozeti; veli kartında da (ödemeyi veli
  yapar). Yönetici: Paketler, Ödemeler (durum filtreli), öğrenci başına
  💳 sayfası.

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
| 3 · Oturum + canlı ekran | ✅ Bitti |
| 4 · Otomatik kapanış | ✅ Bitti |
| 5 · Süre, devamlılık, hedef | ✅ Bitti |
| 6 · Veli | ✅ Bitti |
| 6b · Deneme takvimi | ✅ Bitti |
| 6c · Self adisyon | ✅ Bitti |
| 6d · Deneme PDF + yapay zeka | ✅ Bitti |
| 7 · Paket ve ödeme | ✅ Bitti |
| 8 · Tüketim bağlama | ⬜ |
| 9 · Geri sayım + haftalık veli raporu | 🔄 Sırada |
