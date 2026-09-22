# Kral Kafe

Öğrenciler için çalışma kafesi yönetim sistemi. Öğrenci masadaki QR'ı okutup
çalışma oturumu başlatır, yönetici süreyi onaylar, veli çocuğunun çalışmasını ve
deneme gelişimini tek panelden izler. Kafe tarafında stok sayımı, self adisyon ve
aylık fatura akışı çalışır.

**Bu dosya projenin tek dokümanıdır.** Ürün kararları, yol haritası, teknik karar
kaydı, tuzaklar, kurulum ve dağıtım — hepsi burada. Gelişim buradan takip edilir.

_Son güncelleme: 22 Eylül 2026 · Laravel 12 · 213 test / 715 doğrulama yeşil._

---

## İçindekiler

1. [Hedef akış](#1-hedef-akış) — sistemin bütünü, uçtan uca
2. [Akış değişiklikleri](#2-akış-değişiklikleri-22-eylül-2026) — hangi eski karar değişti
3. [Bugün canlıda ne var](#3-bugün-canlıda-ne-var)
4. [Yol haritası](#4-yol-haritası) — durum, sıra, teknik borç
5. [Verilen kararlar](#5-verilen-kararlar)
6. [Gizlilik ve yetki](#6-gizlilik-ve-yetki)
7. [Özellik kartları](#7-özellik-kartları) — yapılmamışların gerekçesi
8. [Veri modeli](#8-veri-modeli)
9. [Uygulama kararları](#9-uygulama-kararları--dalga-dalga) — yapılmış işin kaydı
10. [Tuzaklar ve çalışma kuralları](#10-tuzaklar-ve-çalışma-kuralları)
11. [Kurulum ve testler](#11-kurulum-ve-testler)
12. [Dağıtım: Vercel + Supabase](#12-dağıtım-vercel--supabase)
13. [Git geçmişi](#13-git-geçmişi)

---

## 1. Hedef akış

Sistemin tamamlandığında çalışacağı akış. Bugün canlıda olan kısım §3'te,
eksik kısmın sırası §4'te.

### 1.1 Günlük çalışma döngüsü

1. **Öğrenci kafeye gelir**, masadaki QR'ı **uygulama içi okuyucuyla** okutur.
2. Oturum başlar; sayaç, öğrenci durdurana kadar akar. Başlangıçta **konum
   bilgisi** de kaydedilir (doğrulama amaçlı, sert kapı değil — §7-N).
3. Öğrenci çalışmayı bitirir.
4. **Yöneticiye onay bildirimi düşer:** "Bu öğrenci bu kadar çalıştı mı?"
5. Yönetici onaylar → süre **öğrenci ve veli panelinde görünür** hale gelir.
   Onaylanmamış oturum istatistiklere girmez.

Unutulan oturumlar kapanış saatinde kendiliğinden kapanır (bugün çalışıyor);
otomatik kapanan oturum da onay kuyruğuna düşer.

### 1.2 Deneme döngüsü

1. **Yönetici deneme takvimine** denemeyi girer (ad, tür, tarih, saat, not).
2. **Denemeden bir gün önce** öğrenci ve veliye bildirim gider.
3. **Gün sonunda**, denemeye girmeyen ya da o gün kafeye hiç gelmeyen
   öğrencilerin velileri bilgilendirilir.
4. **Sonuçlar açıklanınca** yönetici, öğrenci başına deneme sonucunu girer:
   ders bazlı doğru/yanlış/net **ve** kurum, ilçe, il, Türkiye geneli sıralaması.
5. Yönetici denemeyle gelen **detaylı PDF raporunu** sisteme yükler; sistem
   özet çıkarır (yapay zekâ — bugün çalışıyor).
6. Yönetici özeti okur ve sonucun altına **kendi notunu** yazar: geliştirilmesi
   gereken alanlar, öneriler.
7. **Profile işlenen her şey veliye görünür** — netler, sıralamalar, yönetici/koç
   notu.

### 1.3 Haftalık takip

- **Yönetici, öğrencinin haftalık yapması gereken çalışmaları belirler.**
  Öğrenci panelinde bu hafta ne yapması gerektiğini görür, tamamladıkça işaretler.
- Yönetici en yetkili kişidir ve **aynı zamanda koç yetkilerine sahiptir**;
  sistemde ayrıca koç rolünde başka kişiler de olur.

### 1.4 Adisyon ve stok

- **Öğrenci ürünü kendi hesabına panelden ekler.** Tüketim için QR okutma **yok**.
- **Stok takibi yönetici tarafında aktif kalır:** raf, buzdolabı, dolap
  lokasyonları, beklenen/sayılan stok, fotoğraftan sayım, tutarsızlık kaydı.
- Yani QR yalnızca **masa** için vardır: oturum başlatmak.

### 1.5 Arayüz

- **Mobil tam uyum.** Alt menü (bottom navigation) ile yönlendirme; öğrenci,
  veli ve yönetici telefondan rahat kullanabilmeli.
- **Uygulama içi QR okuyucu** — harici kamera uygulamasına çıkmadan okutma.
- Erişim şimdilik web üzerinden; mobil uygulama yok, web mobile-first yeterli.

---

## 2. Akış değişiklikleri (22 Eylül 2026)

Yukarıdaki akış, daha önce verilmiş beş kararı değiştiriyor. Sessiz bırakmamak
için burada:

| # | Eski karar | Yeni karar | Sonucu |
|---|---|---|---|
| 1 | Deneme sonuçları veliye **varsayılan kapalı**, öğrenci başına `can_view_exams` bayrağı | **Profile işlenen her şey veliye açık** | `can_view_exams` sütunu gereksizleşti, planlanmayacak. Koç notunun özel kalabilmesi ayrı soru → §4.4 |
| 2 | Oturum bitince süre doğrudan görünür | **Yönetici onayından sonra görünür** | Yeni durum sütunu, onay kuyruğu, "onay bekliyor" ekranı. Onaylanmamış oturum istatistiklere girmez |
| 3 | Cron **zorunlu değil**; tembel kapatma yeterli (Dalga 4) | **Cron zorunlu** | Deneme hatırlatması ve gün sonu devamsızlık bildirimi kullanıcı paneli açmasa da gitmeli. Vercel Cron + kimlik doğrulamalı uç nokta gerekiyor |
| 4 | Tüketim QR okutularak kaydedilir (`/tuketim/{qr}`) | **Tüketim yalnızca panelden** | QR tüketim akışı kaldırılacak; lokasyon QR'ları stok tarafında kalır. Masa QR'ı etkilenmez |
| 5 | Geolocation "yedek" (⚠️), IP kapısı rafta | **Konum oturum başlangıcında kaydedilir** | Sert kapı değil: iç mekânda sapma var, izin reddedilebilir. Kayıt + anomali işareti olarak kullanılır |

Değişmeyen kararlar: sıralama/rekabet yok, sistem yorum üretmez (yorumu insan
yazar), veli salt okunur.

---

## 3. Bugün canlıda ne var

### Kafe işletmesi

| Özellik | Kim kullanır | Nerede |
|---|---|---|
| QR ile tüketim kaydı, 60 sn geri alma | Öğrenci | `/tuketim/{qr}` — *kaldırılacak, bkz. §2-4* |
| Self adisyon: panelden ürün ekleme | Öğrenci | Panel |
| Tüketim geçmişi ve aylık özet | Öğrenci | `/kullanici/gecmis` |
| Ürün ve lokasyon yönetimi, lokasyon QR yazdırma | Yönetici | Yönetim → Ürünler / Lokasyonlar |
| Fotoğrafla stok sayımı (OpenAI), tutarsızlık kaydı ve çözümü | Yönetici | Yönetim → Stok Sayım |
| Aylık fatura, kullanıcı raporu, CSV dışa aktarım | Yönetici | Yönetim → Raporlar |
| Yönetici paneli: bugünkü tüketim, ayın ürünleri, açık tutarsızlıklar | Yönetici | `/yonetim` |
| Paket kataloğu + kapsam kalemleri | Yönetici | Yönetim → Paketler |
| Öğrenciye paket atama (fiyat atama anında kopyalanır), ödeme kaydı, vade/gecikmiş takibi | Yönetici | Yönetim → Ödemeler |
| Paket ve ödeme rozeti | Öğrenci, veli | Panel |
| Giriş, şifre sıfırlama, Türkçe hata mesajları | Herkes | `/giris`, `/sifremi-unuttum` |

### Çalışma takibi (Dalga 0–6d)

| Özellik | Kim kullanır | Nerede |
|---|---|---|
| Altı rol; abonelik yalnızca öğrenciyi kilitler | Yönetici | Kullanıcı formu |
| Masa tanımı, kalıcı masa QR'ı, toplu yazdırma | Yönetici | Yönetim → Masalar |
| Masa QR'ı okut → çalışma başlat; panelden ya da QR ile bitir | Öğrenci | `/masa/{kod}`, panel |
| Canlı ekran: kim hangi masada ne kadardır, boş masa, anomaliler | Yönetici | Yönetim → Canlı Ekran |
| Onay kuyruğu: biten oturumu onayla/reddet, toplu onay | Yönetici | Yönetim → Canlı Ekran |
| Henüz sayılmayan oturumlar (onay bekleyen / reddedilen, sebebiyle) | Öğrenci | Panel |
| Unutulan oturumların otomatik kapanması (tembel, cron opsiyonel) | Sistem | — |
| Gün/hafta/ay süre, üst üste gelme serisi | Öğrenci | Panel |
| Haftalık hedef ve ilerleme; hedef geçmişi korunur | Yönetici koyar, öğrenci görür | Kullanıcı formu, panel |
| Veli–öğrenci bağı (çoka çok) | Yönetici | Kullanıcı formu |
| Veli paneli (salt okunur): süre, seri, hedef, son 14 gün, geliş-çıkış | Veli | `/veli`, `/veli/ogrenci/{id}` |
| Deneme takvimi + "sıradaki deneme, N gün kaldı" hatırlatıcısı | Yönetici planlar; öğrenci, veli görür | Yönetim → Deneme Takvimi, `/kullanici/denemeler`, `/veli/denemeler` |
| Deneme sonuç PDF'i + yapay zekâ analizi | Yönetici yükler, öğrenci görür | Öğrenci sayfası |

**Altyapı:** Laravel 12 · PHP 8.2 · Vercel (`main`'den otomatik dağıtım) ·
Supabase Postgres (session pooler) · elle yazılmış CSS (Tailwind yok).

| Dalga | İçerik | Canlıda |
|---|---|---|
| 0 | RLS kilidi, kafe saat dilimi, rol enum'u, CSS bileşenleri | ✅ |
| 1 | Altı rol; abonelik yalnızca öğrenciyi kilitler | ✅ |
| 2 | Masalar + kalıcı QR + yazdırma | ✅ |
| 3 | Masa QR'ı ile çalışma oturumu; canlı ekran | ✅ |
| 4 | Unutulan oturumların otomatik kapanması | ✅ |
| 5 | Süre (gün/hafta/ay), seri, haftalık hedef | ✅ |
| 6 | Veli–öğrenci bağı + salt okunur veli paneli | ✅ |
| 6b | Deneme sınavı takvimi + panel hatırlatıcısı | ✅ |
| 6c | Self adisyon: panelden ürün ekleme, 60 sn geri alma | ✅ |
| 6d | Deneme sonuç PDF'i + yapay zekâ analizi | ✅ |
| 7 | Paket kataloğu, abonelik, ödeme takibi, faturada paket tutarı | ✅ |

---

## 4. Yol haritası

### 4.1 Sıradaki dalgalar

Sıra, §1'deki akışı tamamlamaya göre kuruldu: önce günlük döngü (onay + mobil),
sonra bildirimler, sonra deneme sonucu. Para tarafı (paket/ödeme) akış
oturduktan sonra.

| Sıra | Dalga | İçerik | Büyüklük | Bağımlılık | Durum |
|---|---|---|---|---|---|
| 1 | **10** | **Mobil kabuk** — alt menü, uygulama içi QR okuyucu, oturum başlangıcında konum kaydı (§7-M, §7-N) | M | 3 | 🔄 sırada |
| 2 | **11** | **Bildirim altyapısı** — e-posta + Vercel Cron; deneme öncesi hatırlatma, gün sonu devamsızlık bildirimi (§7-K) | L | 6b | ⬜ |
| 3 | **12** | **Deneme sonucu ve sıralamalar** — ders bazlı D/Y/net, kurum/ilçe/il/TR sıralaması, PDF özetinin altına yönetici notu, veliye açık profil (§7-C) | L | 6b, 6d | ⬜ |
| 5 | **13** | **Haftalık çalışma planı** — yönetici belirler, öğrenci tamamlar, tamamlama oranı (§7-D) | M | — | ⬜ |
| 6 | **8** | **Tüketim sadeleştirme** — QR tüketim akışının kaldırılması, tüketimin `package_items` kapsamına bağlanması (`covered_by_package`) | M | 7 ✅ | ⬜ |
| 7 | **14** | **Koç rolü aktif** — koç–öğrenci atama, koç paneli, notlar, görüşme kaydı (§7-B, §7-I) | L | 6 | ⬜ |
| 8 | **15** | **Haftalık veli raporu** — tembel üretim, yönetici/koç yorumu (§7-A) + sınava geri sayım (§7-G) | M | 9, 12 | ⬜ |
| 9 | **16** | **Devamlılık düşüş sinyalleri** — önce koça (§7-E) | S | 14 | ⬜ |
| 10 | **17** | **Ders etiketi + zayıf konu listesi** (§7-F, §7-H) | M | 12 | ⬜ |

S ≈ bir oturum · M ≈ iki-üç oturum · L ≈ dört ve üzeri.

**Neden bu sıra:** Dalga 9 (onay akışı) 22 Eylül'de bitti. Dalga 10 akışın
günlük kullanımını taşıyor (öğrenci telefonla
okutacak). Dalga 11 olmadan §1.2'nin 2. ve 3. adımları hiç çalışmaz. Dalga 12
denemenin sonuç tarafını kapatır. Paket ve ödeme (Dalga 7) 21 Eylül'de
bitmişti; yan dalda kalmıştı, 22 Eylül'de main'e alındı.

### 4.2 Bakım ve teknik borç

| İş | Büyüklük | Not |
|---|---|---|
| Üretimde `LOG_CHANNEL` kontrolü | XS | Panelde `stack` duruyorsa salt okunur diske log yazılıp **ikinci bir 500** üretiliyor; üretim ortam dökümü hiç doğrulanmadı — §12 "Doğrulanmamış" |
| `brashlab` remote'unu kaldır | XS | Yerel depoyu 21 MiB'de tutuyor, kazara merge yolu açık — §13 |
| `Consumption::boot` fiyat ezmesi | S | `total_price` koşulsuz yeniden hesaplanıyor; paket kapsamı girince yanlış fatura üretir. Dalga 8'in önkoşulu |
| Anomaliye "gördüm" işareti | S | Yedi günlük pencere sessizce kapanıyor; `discrepancy_logs` çözümleme akışı örnek alınabilir |
| `study_tables` ertelenen sütunları | S | `status`, `assigned_student_id`, `location_id` — anlamları paket ve raf/masa kararıyla geliyor |
| Görevliye masa yönetimi erişimi | S | Yetki matrisi açık diyor, `AdminMiddleware` kapalı tutuyor |
| Gerçek e-posta sağlayıcısı | S | `MAIL_MAILER=log`; Dalga 11'in önkoşulu |

### 4.3 Rafta

| Konu | Neden |
|---|---|
| IP kapısı | Kafe IP'si dinamik ölçüldü (TT ADSL havuzu); beyaz liste modem resetinde kafeyi kilitler. Konum takibi (§7-N) bunun yerine geçiyor |
| Öğrencinin günlük notu | Yük ekler; isteğe bağlı, koçluk hattı bitince |
| WhatsApp bildirimi | Resmî API ücretli ve onaylı şablon ister; e-postadan sonra |
| Pomodoro, geri bildirim, ortak kafe hedefi, online ödeme, çalışma↔net korelasyonu | Fikir düzeyinde; kart yazılmadı |
| Öğretmene fotoğraflı soru sorma | Öğretmen rolü var, paneli yok |
| Yardım / çağrı butonu | `help_requests`; masadan kalkmadan görevli çağırma |

### 4.4 Karar bekleyenler

Dalga 9'a başlamadan önce cevaplanması gerekenler. *(Dördü 22 Eylül'de kapandı
ve §5'e geçti.)*

| # | Soru | Öneri |
|---|---|---|
| 1 | Onaylanmamış oturum **ne kadar bekler**? Yönetici üç gün bakmazsa süre kaybolur mu, kendiliğinden onaylanır mı? | Kaybolmaz, kuyrukta bekler; bekleyen sayısı ve en eski bekleyenin yaşı panelde görünür. Otomatik onay yok — onayın anlamı kalmaz |
| 2 | Konum izni **reddedilirse** oturum başlar mı? | Başlar, "konum yok" işaretiyle. Engellemek gerçek öğrenciyi cezalandırır, sahtekârı durdurmaz (§7-N) |
| 3 | Devamsızlık bildiriminin **eşiği** ne olmalı? | Her gelmediği gün veliye mesaj gitmesi kısa sürede gürültüye dönüşür. Kafe gününde hiç oturum yoksa ve öğrenci o hafta en az bir kez geldiyse gönder — tatildeki öğrenciye her gün mesaj gitmesin |

## 5. Verilen kararlar

| # | Karar | Tarih |
|---|---|---|
| 1 | Deneme sonucunu **yönetici girer** (sıralamalarla birlikte); PDF raporunu da yönetici yükler | 22 Eyl 2026 |
| 2 | **Profile işlenen her şey veliye görünür** — netler, sıralamalar, yönetici notu | 22 Eyl 2026 |
| 3 | Oturum süresi **yönetici onayından sonra** veliye görünür | 22 Eyl 2026 |
| 4 | Onay beklerken **öğrenci kendi ham süresini** "onay bekliyor" etiketiyle görür. Seri, hedef ilerlemesi ve tüm istatistikler **yalnızca onaylanmış** süreyi sayar | 22 Eyl 2026 |
| 5 | Onay bildirimi **panel içi kuyruk** — rozet + toplu onay ekranı. E-posta ya da cron gerektirmez, böylece Dalga 9 bildirim altyapısını beklemez | 22 Eyl 2026 |
| 6 | Koç/yönetici notunda **"veliyle paylaş" varsayılan işaretli**; özel not seçeneği kalır | 22 Eyl 2026 |
| 7 | Deneme sıralaması **sıra + katılımcı sayısı** olarak tutulur ("1.240 kişide 87."); yüzdelik dilimi sistem hesaplamaz | 22 Eyl 2026 |
| 8 | Tüketim için QR okutma yok; **panelden manuel ekleme** tek yol. Stok takibi yönetici tarafında aktif kalır | 22 Eyl 2026 |
| 9 | Oturum başlangıcında **konum kaydedilir**; sert kapı değil, doğrulama verisi | 22 Eyl 2026 |
| 10 | Haftalık çalışma planını **yönetici belirler** | 22 Eyl 2026 |
| 11 | Yönetici en yetkili kişidir ve **koç yetkilerini de taşır**; sistemde ayrıca koç rolü vardır | 22 Eyl 2026 |
| 12 | **Uygulama içi QR okuyucu** ve alt menüyle mobil tam uyum | 22 Eyl 2026 |
| 13 | **Cron zorunlu** — bildirimler kullanıcı paneli açmasa da gitmeli | 22 Eyl 2026 |
| 14 | Haftalık rapor pazar 23:59 kafe saatinde kapanır | 20 Eyl 2026 |
| 15 | İlk bildirim kanalı e-posta; WhatsApp sonra | 20 Eyl 2026 |
| 16 | YKS tarihi takvime "resmî sınav" olarak girilir | 20 Eyl 2026 |
| 17 | `mock_exams` tablosu açılmaz; `exam_events` onun yerine geçti | 20 Eyl 2026 |

Teknik kararların tamamı ve gerekçeleri §9'da.

---

## 6. Gizlilik ve yetki

### 6.1 Gizlilik kuralları

1. **Veli salt okunur.** Hiçbir veli rotası POST/PUT/DELETE taşımaz (test var).
2. **Veli, öğrencinin profiline işlenen her şeyi görür:** onaylanmış çalışma
   süresi, deneme netleri ve sıralamaları, yönetici/koç notları. *(22 Eylül'de
   değişti; eskiden deneme sonuçları veliye kapalıydı.)*
   Tek istisna: not yazarken **"veliyle paylaş" varsayılan işaretlidir** ama
   koç isterse notu özel tutabilir (karar 6). Gözlem notuyla veliye giden
   değerlendirme aynı şey değil; özel seçeneği olmayan bir sistemde koç ham
   gözlemini hiç yazmaz.
3. **Öğrenci, veliye ne gittiğini kendi panelinde görür.** Gizli izleme yok.
4. **Sıralama, karşılaştırma, yüzdelik dilim yok** — öğrenciler birbiriyle
   karşılaştırılmaz. Denemenin kurum/il/TR sıralaması bunun istisnası değil:
   o, sınavın kendi verisidir, sistemin ürettiği bir kıyas değil.
5. **Düşüş sinyalleri önce koça/yöneticiye**, veliye ancak yorumla birlikte.
6. **Yorum üreten tek kişi insandır.** Sistem ve yapay zekâ sayı ve alan
   gösterir, sıfat üretmez. Deneme PDF'i analizinin prompt'u kişilik ve
   motivasyon yorumunu açıkça yasaklar.
7. **Konum verisi yalnızca oturum doğrulaması için** tutulur; panelde harita
   ya da geçmiş konum izi gösterilmez.

### 6.2 Yetki matrisi

| | Yönetici | Koç | Öğretmen | Öğrenci | Veli | Görevli |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Kendi oturumunu başlat/bitir | — | — | — | ✅ | — | — |
| Oturum onaylama | ✅ | — | — | — | — | — |
| Oturumları gör | tümü | kendi öğrencileri | — | kendi | kendi çocuğu (onaylı) | canlı ekran |
| Masa yönetimi | ✅ | — | — | — | — | ✅ |
| Ürün/stok/sayım | ✅ | — | — | — | — | ✅ |
| Paket ve ödeme | ✅ | — | — | kendi (salt) | kendi çocuğu (salt) | — |
| Haftalık plan belirleme | ✅ | ✅ | — | — | — | — |
| Deneme sonucu girişi | ✅ | ✅ | — | — | — | — |
| Deneme notu yazma | ✅ | ✅ | — | — | — | — |
| Öğrenci sorularını cevaplama | ✅ | — | ✅ | — | — | — |

**Kural:** Yetkilendirme controller içinde `if` ile değil **Policy** sınıfıyla
yapılır. Rol sayısı altı; dağınık kontroller kısa sürede güvenlik açığına döner.
`AdminMiddleware` korunur ama tek başına yetmez.

Bugünkü karşılığı: `User::accessibleStudentIds()` tek kaynak — yönetici `null`
(sınırsız), veli bağlı öğrenciler, öğrenci kendisi, koç/öğretmen/görevli **boş**.
Koç ataması gelince tek satırla genişler; global scope **yok** (görünmez şekilde
yönetici toplamlarına sızardı).

---

## 7. Özellik kartları

Sıra ve bağımlılıklar §4.1'de; burada **ne olduğu ve neden öyle tasarlandığı**.

### L · Oturum onay akışı — Dalga 9 · ✅ bitti (22 Eylül 2026)

- **Problem:** Bugün oturum bitince süre doğrudan görünüyor. Yönetici "bu
  öğrenci gerçekten bu kadar çalıştı mı" sorusunu ancak canlı ekranda, o an
  bakıyorsa cevaplayabiliyor. Veli raporu doğrulanmamış veriye dayanıyor.
- **Çözüm:** Oturum bitince **onay kuyruğuna** düşer. Yönetici onaylar →
  süre öğrenci ve veli panelinde, istatistiklerde, hedef ilerlemesinde sayılır.
- **Durum sütunu:** `approval_status` (pending|approved|rejected) +
  `approved_by`, `approved_at`. Reddedilen oturum **silinmez** — iz kalır,
  istatistiğe girmez.
- **Neden ayrı sütun, `end_reason`'a eklenmiyor:** `end_reason` oturumun nasıl
  bittiğini söyler (öğrenci bitirdi / masa değişti / otomatik kapandı / limit
  aşıldı); onay ondan bağımsız bir eksen. İkisini tek sütunda birleştirmek
  "otomatik kapandı **ve** onaylandı" durumunu temsil edilemez yapar — Dalga
  3'te `switched`/`auto_closed` için verilen kararın aynısı.
- **Otomatik kapanan oturum da kuyruğa düşer:** asıl doğrulanması gereken o.
- **Toplu onay** şart: yönetici günde 20 oturumu tek tek onaylamaz. Liste
  ekranında seç-onayla.
- **Kararlar (22 Eyl):** Onay beklerken öğrenci kendi ham süresini "onay
  bekliyor" etiketiyle görür; veli göremez. Seri, hedef ilerlemesi ve tüm
  istatistikler yalnızca onaylanmış süreyi sayar (karar 4). Bildirim **panel
  içi kuyruk** — rozet + toplu onay ekranı; e-posta ya da cron gerektirmez,
  bu yüzden Dalga 9 Dalga 11'i beklemez (karar 5).
- **Açık soru:** §4.4 #1 — onaylanmamış oturum ne kadar bekler?

### M · Mobil kabuk: alt menü + uygulama içi QR okuyucu — Dalga 10

- **Problem:** Öğrenci QR'ı okutmak için telefonun kamera uygulamasına çıkıyor,
  tarayıcıya geri dönüyor; her gün iki kez yaşanan sürtünme. Menü de masaüstü
  için kurulmuş.
- **Çözüm:** (a) Alt menü — öğrenci, veli ve yönetici için ayrı sekme setleri;
  (b) uygulama içi QR okuyucu: `getUserMedia` + `BarcodeDetector`, desteklemeyen
  tarayıcıda kitaplık yedeği.
- **Kritik kısıt:** Kamera erişimi **yalnızca HTTPS'te** (ve `localhost`'ta)
  çalışır. Üretim Vercel'de HTTPS; yerelde `php artisan serve` ile test
  edilemez, `localhost` kullanılmalı ya da tünel açılmalı.
- **Yedek yol her zaman kalır:** kamera izni reddedilirse ya da tarayıcı
  desteklemiyorsa, masadaki QR'ın altındaki **kısa kodu elle yazma** alanı.
  Tek yol olarak kameraya bağlanmak, izni kapalı bir telefonu sistem dışına atar.
- **Stil kısıtı:** proje Tailwind kullanmıyor; alt menü `public/css/app.css`'e
  elle yazılacak ve muhafız test sınıfların tanımlı olmasını zorunlu kılıyor
  (§10.6).

### N · Konum kaydı — Dalga 10

- **Amaç:** Oturumun gerçekten kafede başladığını doğrulamak. Basılı QR bir kez
  fotoğraflanıp evden okutulabilir; IP kapısı dinamik IP yüzünden rafa kalktı.
- **Nasıl:** Oturum başlarken tarayıcıdan konum istenir; `latitude`, `longitude`,
  `accuracy` oturum satırına yazılır. Kafe koordinatına uzaklık hesaplanır,
  eşiği aşan oturum **anomali olarak işaretlenir** — engellenmez.
- **Neden sert kapı değil:**
  - İç mekânda GPS sapması 50–100 metreyi bulur; gerçek öğrenci dışarıda görünür.
  - Konum izni reddedilebilir; reddeden öğrenciyi sistem dışına atmak,
    sahtekârı durdurmaktan çok gerçek kullanıcıyı cezalandırır.
  - Asıl doğrulama katmanı zaten **yönetici onayı** (§7-L) ve canlı ekran:
    personel boş masayı gözüyle görür.
- **Gizlilik:** Yalnızca oturum başlangıcı kaydedilir, süre boyunca takip **yok**.
  Panelde harita gösterilmez; yalnızca "kafede / uzak / konum yok" işareti.
- **Açık soru:** §4.4 #5.

### K · Bildirim altyapısı — Dalga 11

- **Ne gönderilecek:**
  1. **Denemeden bir gün önce** — öğrenciye ve veliye hatırlatma.
  2. **Gün sonunda** — denemeye girmeyen ya da o gün kafeye hiç gelmeyen
     öğrencinin velisine bilgilendirme.
  3. (Sonra) haftalık veli raporu hazır bildirimi.
- **Neden cron zorunlu:** Bu üçü de kullanıcının paneli açmasına bağlı olamaz.
  Dalga 4'ün "tembel üretim" çözümü burada çalışmaz — oradaki iş oturumu
  *kapatmaktı* ve sonucu veriden hesaplanabiliyordu; bildirim gönderilmiş ya da
  gönderilmemiştir, sonradan türetilemez.
- **Vercel'de:** Vercel Cron → kimlik doğrulamalı `/api/cron/...` ucu.
  Hobby katmanında cron sapması ±59 dk; "bir gün önce" penceresi buna dayanıklı
  kurulmalı (saat değil **gün** karşılaştırması, `LocalDay` ile).
- **Gönderilen her bildirim kaydedilir** (`notifications` tablosu: tür, alıcı,
  ilgili kayıt, gönderim anı). İki sebep: cron iki kez çalışırsa aynı bildirim
  iki kez gitmesin (idempotans), ve "veliye gitti mi" sorusu cevaplanabilsin.
- **Kanal:** e-posta. `MAIL_MAILER=log` olduğu sürece gerçekten gitmez; gerçek
  gönderim SMTP/Resend ister (§4.2). WhatsApp resmî API ücretli, sonraya.
- **Devamsızlık bildiriminin eşiği** düşünülmeli: öğrencinin her gelmediği gün
  veliye mesaj gitmesi, kısa sürede görmezden gelinen bir gürültüye dönüşür.

### C · Deneme sonucu, sıralamalar ve yönetici notu — Dalga 12

- **Ne:** Takvimdeki bir denemeye (Dalga 6b) öğrenci başına sonuç girişi:
  - Ders bazlı **doğru / yanlış / boş**, net otomatik hesaplanır.
  - **Kurum, ilçe, il, Türkiye geneli sıralaması** — her biri için sıra
    **ve katılımcı sayısı**. "1.240 kişide 87." anlamlı, çıplak "87." değil:
    sıranın anlamı denemeden denemeye değişir. Yüzdelik dilimi sistem
    hesaplamaz (karar 7).
  - Denemeyle gelen **detaylı PDF** (Dalga 6d ile zaten yükleniyor) → yapay zekâ
    özeti → özetin altına **yöneticinin kendi notu**: geliştirilmesi gereken
    alanlar, öneriler.
- **Kim girer:** Yönetici (ve koç). Öğrenci girişi yok — sonuçlar kuruma toplu
  geliyor, öğrenciden girmesini beklemek hem gecikme hem hata kaynağı.
- **Kim görür:** Öğrenci, veli, koç, yönetici (§6.1-2).
- **Ders listesi veriden gelir** (`subjects`), koda gömülmez: TYT, AYT ve LGS
  ders listeleri farklı ve kurumdan kuruma değişebiliyor.
- **Sıralama sütunları nullable:** kurum sıralaması denemenin ertesi günü,
  Türkiye geneli bir hafta sonra açıklanabiliyor. Sonuç girişi eksik veriyle
  başlayıp tamamlanabilmeli.
- **Net grafiği:** ders bazlı seri, deneme türüne göre ayrı. Çalışma süresiyle
  yan yana gösterim sonraki iş; **nedensellik iddiası yok**.
- **`mock_exams` tablosu açılmaz** — `exam_events` onun yerine geçti (karar 13).
- **Sıralama gizlilik kuralına takılmaz:** §6.1-4 öğrencileri birbiriyle
  karşılaştırmayı yasaklar; kurum/il/TR sıralaması ise sınavın kendi verisidir,
  sistemin ürettiği bir kıyas değil.

### D · Haftalık çalışma planı — Dalga 13

- **Ne:** Yönetici (ya da koç) öğrencinin haftalık yapması gerekenleri belirler:
  başlık, ders, hedef gün. Öğrenci panelinde bu haftanın listesini görür,
  tamamladıkça işaretler. Tamamlama oranı yönetici ve veli panelinde.
- **Haftalık hedef saatten farkı:** hedef **ne kadar**, plan **ne** sorusunu
  cevaplar. İkisi birlikte anlamlı: 20 saat çalışıp hiç matematik yapmamak
  bugün görünmüyor.
- **Teknik:** `study_plan_items`. Hafta sınırı `LocalDay::weekBounds` (pazartesi
  başlar, pazar 23:59 kafe saatinde kapanır).
- **Geçmiş hafta yeniden yazılmaz:** plan değiştirilmez, yeni hafta için yenisi
  açılır — `study_goals`'ta verilen kararın aynısı (§9, Dalga 5).

### A · Haftalık veli raporu — Dalga 15

- **Ne:** Her pazar üretilen tek sayfa: geliş gün sayısı, **onaylanmış** toplam
  süre, geçen haftaya göre değişim, hedef tutma, plan tamamlama oranı, çözülen
  deneme ve net değişimi, yönetici/koç yorumu.
- **Teknik:** `weekly_reports` (student_id, week_start, payload json,
  coach_comment). **Tembel üretim:** rapor ilk açıldığında hesaplanır ve saklanır.
  Bildirim tarafı cron'a bağlı (§7-K), ama raporun kendisi paneli açan ilk kişide
  üretilebilir.

### B · Koç rolü, atama ve notlar — Dalga 14

- **Ne:** Koç paneli: öğrenci listesi (haftalık süre, hedef %, seri, son deneme),
  düşüş gösteren öğrenci vurgusu, öğrenci detayı, not bırakma.
- **Yönetici zaten koçtur** (karar 7): koç panelindeki her şeye yöneticinin de
  erişimi var; ayrı bir "koç" hesabı açmak zorunda değil.
- **Not görünürlüğü:** `visibility` iki değer — `parent` (varsayılan, veli
  görür) ve `private` (yalnız koç/yönetici). Varsayılanın açık olması §6.1-2'nin
  gereği; özel seçeneğinin kalması koçun ham gözlemini yazabilmesi için
  (karar 6).
- **Teknik:** `coach_assignments`, `coach_notes`.

### I · Koç–veli görüşme kaydı — Dalga 14

Koç görüşme sonrası kısa özet bırakır ("Ekim planı konuşuldu, hedef 20 saat").
Sohbet/mesajlaşma **değil** — tek yönlü kayıt; WhatsApp'ın yerini almaya
çalışmaz, kayıt altına alır. `coach_notes` içinde `kind = meeting`; ek tablo yok.

### E · Devamlılık düşüş sinyali — Dalga 16

Kural tabanlı, **yorumsuz** sinyal: "son 7 günde 2 geliş, önceki 7 günde 5",
"hedefin %40'ı", "3 gündür gelmedi". Koç panelinde vurgu. Tablo yok; `StudyStats`
üzerinden hesaplanır, eşikler `config/kafe.php`. Dalga 11'in gün sonu devamsızlık
bildiriminden farkı: o **tek güne** bakar ve veliye gider, bu **eğilime** bakar
ve önce koça gider.

### F · Ders bazlı çalışma kırılımı — Dalga 17

Oturum başlarken ya da biterken tek dokunuşla ders etiketi. Zorunlu değil;
etiketsiz oturum "genel". Değeri: "12 saat çalıştı" yerine "8 saat matematik,
0 saat Türkçe" — planla (§7-D) ve zayıf konuyla bağlanır.
`study_sessions.subject_id` nullable; `subjects` C ile ortak.

### H · Zayıf konu listesi — Dalga 17

Deneme sonucundan (düşük net) ya da yönetici/koç girişiyle konu listesi; her
konuya plan maddesi bağlanır, konu "kapandı" işaretlenir. 6d'nin PDF analizinden
de beslenebilir.

### G · Sınava geri sayım — Dalga 15 ile

Deneme takvimine "resmî sınav" türü (YKS, LGS tarihi); panelde büyük geri sayım,
takvimde işaretli. `ExamType::Official` yeter; `exam_events` üstüne bayrak
gerekmez. Dalga 6b altyapısıyla bir günlük iş.

---

## 8. Veri modeli

### 8.1 Mevcut tablolar

`users` · `locations` · `products` · `product_locations` · `consumptions` ·
`stock_records` · `stock_photos` · `monthly_bills` · `discrepancy_logs` ·
`study_tables` · `study_sessions` · `study_goals` · `student_parent` ·
`exam_events` · `exam_reports` · `packages` · `package_items` ·
`subscriptions` · `payments`

### 8.2 Eklenecek sütunlar

| Tablo | Sütun | Dalga | Sebep |
|---|---|---|---|
| ~~`study_sessions`~~ | ~~`approval_status`, `reviewed_by`, `reviewed_at`, `rejection_reason`~~ | 9 | ✅ Yapıldı. `approved_by/at` yerine `reviewed_by/at`: red de bir incelemedir, onu "approved_at"te tutmak yanıltıcı olurdu |
| `study_sessions` | `latitude`, `longitude`, `accuracy` | 10 | Konum kaydı (§7-N) |
| `study_sessions` | `subject_id` (null) | 17 | Ders etiketi |
| `consumptions` | `covered_by_package` (bool) | 8 | Paket kapsamı faturaya yansısın |
| ~~`monthly_bills`~~ | ~~`package_amount`~~ | 7 | ✅ Yapıldı — `total_amount` tüketim toplamı olarak kaldı, genel toplam `grandTotal()` |
| `exam_events` | `exam_type`'a `official` | 15 | Sınava geri sayım |
| `study_tables` | `status`, `assigned_student_id`, `location_id` | 7 | Dalga 2'de bilerek ertelendi |

`student_parent.can_view_exams` **planlanmıyor** — §2-1 ile gereksizleşti.

### 8.3 Eklenecek tablolar

```
notifications         id, type, user_id, related_type, related_id,
                      channel (mail|panel), sent_at, error
                      -- idempotans: (type, user_id, related_id) tekil

subjects              id, exam_type (tyt|ayt|lgs), name, sort_order

exam_results          id, exam_event_id, student_id,
                      rank_institution, total_institution,
                      rank_district,    total_district,
                      rank_city,        total_city,
                      rank_country,     total_country,
                      note (yönetici yorumu), entered_by, created_at
                      -- siralama sutunlarinin hepsi nullable: kurum siralamasi
                      -- ertesi gun, TR geneli bir hafta sonra aciklanabiliyor

exam_result_subjects  id, exam_result_id, subject_id,
                      correct, wrong, blank, net

study_plan_items      id, student_id, subject_id?, title, week_start,
                      due_date?, status (open|done|cancelled),
                      completed_at, created_by

coach_assignments     id, coach_id, student_id, assigned_at, is_active

coach_notes           id, coach_id, student_id, kind (note|meeting), body,
                      visibility (parent|private), created_at
                      -- varsayilan parent: veli gorur (karar 6)

weekly_reports        id, student_id, week_start, payload (json),
                      coach_comment, generated_at

weak_topics           id, student_id, subject_id, topic, source, status

-- packages, package_items, subscriptions, payments: Dalga 7'de eklendi (§9.4)
```

Şema kuralları §10.10'da: `enum()` yasak, her tablo `PostgresSecurity::lockDown()`
ve bir factory ile birlikte gelir.

---

## 9. Uygulama kararları — dalga dalga

> **Yapılmış işin karar kaydı.** Her dalganın ne yaptığı, neyi bilerek
> yapmadığı ve yol üstünde hangi hatanın bulunduğu burada. Aynı tartışmayı
> yeniden açmadan önce ilgili dalgayı oku.

### 9.1 Planı değiştiren beş bulgu

Analiz sırasında, planın kendisini değiştiren beş şey çıktı.

#### 9.1.1 `enum()->change()` Postgres'te migration'ı çökertir

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

#### 9.1.2 Yeni tablolar RLS korumasını miras almıyor

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

#### 9.1.3 Zaman dilimi hatası mevcut ve büyüyecek

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

#### 9.1.4 Oturum 4–6 saat sürüyor, giriş çerezi 2 saat

`SESSION_LIFETIME=120`. Öğrenci 09:00'da oturum açar, 14:00'te bitirmek için
telefonunu açar — giriş çerezi 11:00'de ölmüştür ve `/masa/{qr}` `auth`
arkasındadır. Öğrenci giriş ekranına düşer, oturumunu kapatamaz, kayıt
otomatik kapanmaya kalır.

**Karar:** `SESSION_LIFETIME=900` (15 saat — kafe günü 840 dakika, biraz pay).
§12 tablosuna eklendi ve bu ilişkiyi koruyan bir test yazıldı:
kafe saatleri uzarsa test uyarır.

#### 9.1.5 Ürün belgesinde iki düzeltme gerekti

- **"Tailwind 4 + Vite" yanlıştı.** `resources/css/app.css`'te tek bir
  `@import`/`@apply`/tailwind satırı yok; `@vite` yalnızca hiçbir rotası olmayan
  `welcome.blade.php`'de geçiyor. Gerçek: **elle yazılmış `public/css/app.css`**
  (1371 satır). Yanlış şablon düzenlenirse stiller sessizce hiçbir şey yapmaz.
- **`migrate:fresh --env=supabase` asla çalıştırılmayacak.** Üretim veritabanını
  siler; daha sinsisi, lokasyon `qr_code`'ları migration'da `Str::random(8)` ile
  üretildiği için **kafede asılı basılı QR'lar sessizce geçersiz olur.**
  Üretimde yalnızca `migrate --force` + salt okunur kontrol sorguları.

---

### 9.2 Teknik kararlar

Ürün belgesindeki on açık sorunun hiçbiri ilerlemeyi durdurmuyordu;
aşağıdaki varsayılanlarla devam ediliyor. Fikrin farklıysa söyle, dönmek ucuz.

| Konu | Karar | Gerekçe |
|---|---|---|
| `users.role` biçimi | `string(20)`, DB kısıtı yok, doğrulama `App\Enums\Role` | §9.1.1 |
| Çoklu rol | MVP'de tek rol | §6.2 matrisi tek rollü; iki şapkalı kişiye `admin` verilir |
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
kullanılamaz — bkz. §9.3.

---

### 9.3 Kafe ağı: ölçüm ve sonuç · 🗄️ RAFTA

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
### 9.4 Dalgalar

Her dalga tek başına deploy edilebilir ve sistemi çalışır durumda bırakır.

#### Dalga 0 — Temel (kod yazmadan önce)

| | İş | Neden önce |
|---|---|---|
| 0-A | `PostgresSecurity::lockDown()` + statik muhafız test | ~15 tablonun hepsi buradan geçecek; sonraya bırakmak 3. migration'da unutulur |
| 0-B | `config/kafe.php` + `App\Support\LocalDay` + TrustProxies | Zaman ve IP temeli; oturum işinin ön koşulu |
| 0-C | `App\Enums\Role` + Policy iskeleti + `AdminMiddleware` red testi | Bugün tek bir 403 iddiası yok — reddetme kolu kanıtsız |
| 0-D | CSS: `.progress`, `.session-timer`, `.empty-state` | Mevcut CSS'te ilerleme çubuğu, sayaç ve boş durum sınıfı yok |

#### Dalga 1 — Roller (MVP #7) · ✅ bitti

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

#### Dalga 2 — Masa (MVP #1) · ✅ bitti

`study_tables` + `StudyTable` + yönetici CRUD + QR ekranı + yazdırma sayfası.
Menüye **Masalar** eklendi.

**Basılı etiket kısıtı.** Bu dalganın çıktısı duvara yapıştırılacak; `qr_code`
bir daha değişmemeli. Alan `fillable` değil, yalnızca oluşturulurken üretiliyor
ve testi var — kırılırsa anlamı "masa adını değiştirince duvardaki QR'lar öldü".

**Ürün belgesindeki üç sütun ertelendi**, gerekçeleri migration başlığında:
`status` (oturumdan türetilebilir, ikinci doğruluk kaynağı olurdu),
`assigned_student_id` (anlamı Dalga 7'deki paketle geliyor),
`location_id` (raf ile masa fiziksel olarak örtüşüyor mu sorusu
cevaplanmadan tasarlanamaz).

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

**Erişim:** §6.2 matrisinde masa yönetimi görevliye de açık ama `AdminMiddleware`
yalnızca yöneticiyi geçiriyor; görevli erişimi kendi paneliyle gelecek.

#### Dalga 3 — Oturum + canlı ekran (MVP #2, #9) · ✅ bitti

`study_sessions`, `StudySessionService`, `/masa/{kod}` okutma akışı, öğrenci
panelinde canlı kart, yönetici canlı ekranı (`/yonetim/canli`).

**Çift başlatma veritabanında engellendi.** Uygulama katmanı tek başına yetmez:
iki eşzamanlı istek ikisi de "açık oturum yok" görüp ikisi de insert eder.
Kısmi tekil indeks — `UNIQUE (student_id) WHERE ended_at IS NULL` — hem SQLite
hem Postgres'te çalışıyor. **Kısmi** olması şart: koşulsuz bir tekil indeks
öğrencinin günde yalnızca bir kez çalışmasına izin verirdi. İkisinin de testi
var, ikincisi indeksin tanımını okuyup `ended_at` şartını arıyor.

**Uç durumlar :**

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

Ürün belgesindeki `source` ve `note` sütunları ertelendi — bu dalgada hiçbir şey
yazmıyor ve okumuyor.

**Dalga 2'nin yazdırma uyarısı kendiliğinden kalktı:** `table.scan` rotası
doğunca `@unless(Route::has(...))` sustu. Etiketler artık basılabilir.

#### Dalga 4 — Otomatik kapanış (MVP #3) · ✅ bitti

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

#### Dalga 5 — Süre, devamlılık, hedef (MVP #4, #6, #5) · ✅ bitti

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

#### Dalga 6 — Veli (MVP #8)

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

#### Dalga 6b — Deneme sınavı takvimi (eklendi: 20 Eylül 2026)

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

#### Dalga 6c — Self adisyon (eklendi: 20 Eylül 2026)

Öğrenci QR okutmadan panelden sistemde tanımlı ürünü kendi hesabına ekler.

- `consumptions.location_id` NOT NULL ve her ekran `location->name` okuyor;
  sütunu nullable yapmak yerine **sanal lokasyon** `Location::selfService()`
  (`qr_code = SELF-ADISYON`, `is_active = false`). Kapalı olduğu için stok
  sayımı, QR yazdırma ve panel sayaçları onu hiç görmez.
- Self adisyon **stok düşmez** (ProductLocation'a dokunmaz), yalnızca hesaba
  yazar. Aylık fatura, geçmiş, raporlar değişiklik olmadan bunu da sayar.
- Form tabanlı (JSON değil); geri alma `Consumption::canUndo()` (60 sn) ve
  sahiplik kontrolüyle.

#### Dalga 6d — Deneme sonuç PDF'i + yapay zeka analizi (eklendi: 20 Eylül 2026)

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

#### Dalga 7 — Paket ve ödeme · ✅ bitti (21 Eylül 2026)

`packages`, `package_items`, `subscriptions`, `payments`,
`monthly_bills.package_amount`. Uygulanan kararlar:

- **Fiyat kopyalanır.** Atama anında `packages.monthly_price` →
  `subscriptions.price`; yönetici formda değiştirebilir. Katalog sonradan
  değişince abonelik ve geçmiş faturalar değişmez (test var).
- **Fatura paket tutarı = o ayda BAŞLAYAN aboneliklerin fiyat toplamı.**
  Aylara bölme yok; çok aylık abonelik başladığı ayda yazılır. `total_amount`
  tüketim toplamı olarak kaldı (CSV ve ekranlar öyle okuyordu), genel toplam
  `grandTotal()`. Raporlar sayfasının okuduğu ama var olmayan
  `formatted_total` / `period_name` accessor'ları da eklendi.
- **Ödeme durumu türetilir**, sütunda tutulmaz: `paid` (bakiye 0), `overdue`
  (bakiye var ve bugün > başlangıç + `kafe.odeme_vadesi_gun`), yoksa
  `pending`. `Subscription::syncPaymentStatus()` liste ve panel açılışında
  çalışır. `cancelled` elle verilir ve dokunulmaz; iptal aboneliğe ödeme
  yazılmaz.
- **Paket atanan öğrenci içeri alınır:** `users.subscription_status = active`,
  `subscription_start/end` abonelik tarihlerinden. İptal, öğrencinin durumunu
  otomatik kapatmaz — yönetici kullanıcı formundan kapatır.
- **Kapsam kalemleri yalnızca tanım.** `package_items` (ürün, adet, dönem;
  adet boş = sınırsız) Dalga 8'de tüketime uygulanacak. `usage_window`
  ertelendi.
- Paket silinmez, kapatılır (`restrictOnDelete`) — masalarla aynı karar.
- Öğrenci panelinde paket + ödeme rozeti; veli kartında da, çünkü ödemeyi
  veli yapar.

**Merge notu (22 Eylül):** Bu dalga 21 Eylül'de `claude/inspiring-pasteur-hul4gt`
dalında yazıldı ve şeması canlıya uygulandı (batch 9), ama `main`'e alınmamıştı
— canlı veritabanında tablolar ve iki paket kaydı varken canlı site o kodu
taşımıyordu. 22 Eylül'de merge edildi. Migration gerekmedi, defter zaten
doluydu. Bu, §10.4'teki defter/dosya karşılaştırmasının **ters yönde** ısırması:
canlı depodan ileri gidebiliyor.

#### Dalga 8 — Tüketimin masa ve pakete bağlanması (MVP #12) · en son

`consumptions.table_id` + `covered_by_package`. `Consumption::boot` içindeki
`total_price` hesabı koşulsuz eziyor — kapsam mantığı oraya girmeli.

#### Dalga 9 — Oturum onay akışı · ✅ bitti (22 Eylül 2026)

`study_sessions.approval_status` + `reviewed_by` + `reviewed_at` +
`rejection_reason`; yönetici onay kuyruğu canlı ekranın içinde. Kararlar:

- **`approval_status` ayrı sütun**, `end_reason`'a eklenen bir değer değil.
  `end_reason` oturumun *nasıl* bittiğini söyler; onay ondan bağımsız bir
  eksen. Birleştirmek "otomatik kapandı **ve** onaylandı" durumunu temsil
  edilemez yapardı — Dalga 3'ün `switched`/`auto_closed` kararının aynısı.
- **`approved_by/at` değil `reviewed_by/at`.** Red de bir incelemedir;
  reddin anını "approved_at"te tutmak yanıltıcı olurdu.
- **Karar WHERE'de, PHP'de değil** (`closeOnce` ile aynı gerekçe). Açık
  oturum onaylanamaz: onay, bitmiş bir sürenin doğrulanmasıdır.
- **Red sebebi zorunlu, kayıt silinmez.** Sessiz red, öğrencinin süresinin
  neden kaybolduğunu anlamasını imkânsız kılardı.
- **Açık oturum artık toplamlara girmiyor.** Eskiden o ana kadarki süresi
  sayılıyordu; onayla birlikte bu savunulamaz hale geldi — çalışmayı
  bitirmek bugünün toplamını **düşürürdü**. Açık oturum panelde ayrı bir
  canlı kart. (`StudyStatsTest`'teki eski test bu gerekçeyle yeniden yazıldı.)
- **Veli açık oturumu onaysız da görür**: "şu an içeride" bir varlık
  bilgisi, kredilendirilmiş süre iddiası değil. Kapanan oturum onaya kadar
  velinin listesine girmez — süre `StudyStats`'ten süzülüyordu ama liste
  modele doğrudan gidiyordu, tanım `scopeCountable`'da tek yere toplandı.
- **Mevcut satırlar onaylı yazıldı.** Varsayılan `pending` yeni oturumlar
  için doğru ama geçmişe uygulanırsa görünen süreler kaybolur, seri ve
  hedef çubuğu sebepsiz sıfırlanırdı. Onay ileriye dönük bir kural.
- **Toplu onay şart.** Günde yirmi oturumu tek tek onaylamak, özelliğin
  kullanılmaması demek; kuyruk birikir ve öğrencinin süresi donar.
  `approveMany()` filtreyi SQL'de uygular — istemciden gelen id listesine
  güvenmek, reddedilmiş bir oturumu sessizce geri almak olurdu.
- **Öğrenci kendi ham süresini görür** ("Henüz sayılmayan oturumlar"),
  reddedilen sebebiyle birlikte. Görmezse "iki saat çalıştım ama panelde
  sıfır yazıyor" olur ve öğrenci sisteme güvenmeyi bırakır.
- Onay yalnızca yöneticide: kafede fiilen bulunmayı gerektiren bir
  doğrulama (§6.2).

**Yol üstünde bulunan hata:** migration, SQLite'ta tabloyu yeniden yazarken
Dalga 3'ün kısmi tekil indeksini bozdu — §10.5.

---

---

## 10. Tuzaklar ve çalışma kuralları

> Bu bölümdeki her madde **en az bir kez gerçekten ısırdı**. Çoğu sessiz:
> hata vermez, yalnızca yanlış sayı üretir. Yeni kod yazmadan önce ilgili
> başlığa bak.

### 10.1 Eloquent bir `Carbon`'u UTC'ye çevirmez — üç kez ısırdı

Veritabanına yazarken **ve** sorgu bağlamasına koyarken Eloquent kendi saat
diliminin duvar saatini biçimler:

```
Carbon("2026-09-14 00:00", "Europe/Istanbul")  →  "2026-09-14 00:00:00"
                                               →  UTC sanılarak saklanır/karşılaştırılır
                                               →  üç saatlik sessiz kayma, hata yok
```

Ortaya çıktığı üç yer:

1. **Dalga 4** — `SessionCloser::dueEnd()` kafe saatinde dönüyordu; oturum bitiş
   anları üç saat kayıyordu.
2. **Dalga 5** — `LocalDay` sınırları kafe saatinde dönüyordu; gün/hafta/ay
   sorguları kayıyordu. Yerel 01:00'de biten oturum hiçbir güne sayılmıyordu.
3. **Dalga 4'ün `LiveController`'ı** — aynı hata anomali penceresindeydi; yedi
   günlük pencere maskelediği için ancak Dalga 5'te fark edildi.

**Kural:** veritabanına giden her `Carbon` **UTC** olmalı. Gösterim için
`->timezone(config('kafe.timezone'))`. Zaman yazan ya da sorgulayan her yeni
kodda önce bu kontrol edilir.

**Yan kural:** `whereMonth` / `whereYear` / `whereDate` yeni kodda
kullanılmayacak — sunucunun saat dilimine göre çalışırlar. Pencere Carbon ile
yerel saatte kurulur, `whereBetween` ile sorgulanır. Pencereler yarı açık
(`[baş, sonraki baş)`); kapalı aralık + `endOfDay()` her günü bir dakika eksik
sayar.

### 10.2 `enum()` Postgres'te migration'ı çökertir

`$t->enum('role', [...])->change()` gerçek Supabase'de denendi:

```
SQLSTATE[42601]: syntax error at or near "check"
```

Postgres `ALTER COLUMN ... TYPE` ile CHECK cümlesi kabul etmiyor — migration
**doğrudan patlar ve üretim yarı göçmüş kalır.** `string()->change()` ise komutu
geçirir ama eski `users_role_check` yerinde durur; sonra yeni değeri yazmak
`SQLSTATE[23514]` verir. Sessiz tuzak.

**Kural:** `$table->enum()` kullanılmayacak. Sütun `string(20)`, doğrulama PHP
tarafında (`App\Enums\*` + `Rule::enum()`). Postgres'te eski CHECK açıkça
düşürülür, yerine yenisi konmaz — SQLite'ta `->change()` tabloyu baştan yazarken
**komşu sütunun** kısıtını da düşürüyor; kısıtı korumak her rol eklemesinde iki
sürücü için elle SQL yazmayı zorunlu kılardı.

### 10.3 `migrate:fresh` asla çalıştırılmaz

İki ayrı sebeple:

- **Yerelde:** bir kez "temiz kurulum doğrulaması" diye çalıştırıldı ve SQLite'taki
  gerçek veri gitti (kullanıcılar, ürünler). Gereksizdi de — test takımı zaten
  `:memory:` kullanıyor.
- **Üretimde:** lokasyon `qr_code`'ları migration'da `Str::random(8)` ile
  üretiliyor. Fresh, **kafede asılı basılı QR'ları sessizce geçersiz kılar.**

Üretimde yalnızca `migrate --force` + salt okunur kontrol sorguları.

### 10.4 Migration'lar çalıştırılmadan kalıyor — hem canlıda hem yerelde

İki kez oldu, ikisi de farklı yerde:

- **Canlıda (20 Eylül 2026):** Dalga 5'in `study_goals` migration'ı uygulanmamıştı
  (18/19). Fark edilmesi için canlı `migrations` defteriyle
  `database/migrations/` elle karşılaştırıldı.
- **Yerelde (21 Eylül 2026):** üç migration hiç çalışmamıştı; `/yonetim/kullanicilar`
  `no such table: student_parent` ile 500 verdi. Canlıda sorun yoktu, çünkü
  orada uygulanmışlardı.

- **Canlı depodan ileride (22 Eylül 2026):** `migrations` defterinde 25 satır
  vardı, depoda 22 dosya. Fazla üç satır Dalga 7'nindi — şema canlıya
  uygulanmış ama kod `main`'e merge edilmemişti. Karşılaştırma **iki yönlü**
  yapılmalı: eksik dosya kadar fazla defter satırı da sinyaldir.

**Kural:** her migration'dan sonra canlı `migrations` tablosu ile
`database/migrations/` karşılaştırılır. `git pull` sonrası ilk refleks:

```bash
php artisan migrate:status
```

### 10.5 SQLite tabloyu yeniden yazarken ham SQL indeksi kaybolur

`Schema::table()` ile `foreignId()->constrained()` eklemek SQLite'ta ALTER
TABLE ile yapılamaz; Laravel **tabloyu baştan yazar** ve yalnızca *kendi
bildiği* indeksleri geri kurar.

22 Eylül 2026'da Dalga 9'un migration'ı bu yüzden Dalga 3'ün kısmi tekil
indeksini bozdu:

```
CREATE UNIQUE INDEX ... ON study_sessions (student_id) WHERE ended_at IS NULL
                                                      ^^^^^^^^^^^^^^^^^^^^^^^
                                                      yeniden yazmada kayboldu
```

Sonuç sessiz ve ağır: "öğrenci başına tek **açık** oturum" kuralı "öğrenci
başına tek oturum" oldu — öğrenci ikinci kez hiç çalışmaya başlayamazdı. Dört
test yakaladı.

**Kural:** SQLite'ta tabloyu yeniden yazan her migration, ham SQL ile
kurulmuş her şeyi (kısmi indeks, CHECK, trigger) açıkça geri koymak zorunda.
Postgres'te ALTER TABLE indekse dokunmaz; orada aynı adım zararsız bir
yeniden oluşturmadır. Aynı aile: §10.2'de `->change()`'in komşu sütunun CHECK
kısıtını düşürmesi.

### 10.6 Bu proje Tailwind da Bootstrap da kullanmıyor

Stiller **elle yazılmış** `public/css/app.css`'te (1464 satır). Hiçbir blade
`@vite` kullanmıyor; `resources/css/app.css` boş bir kabuk.

Alışkanlıkla yazılan bir sınıf adı (`text-end`, `ms-2`, `float-end`) **hata
vermez, sessizce hiçbir şey yapmaz.** Buna karşı muhafız test var: blade'lerdeki
her sınıf `app.css`'te ya da bir `<style>` bloğunda tanımlı olmak zorunda
(`js-` önekli kancalar muaf). `@apply` ya da `tailwind` geçen bir satır da
ayrı bir testle engelleniyor.

### 10.7 Test yeşil görülmeden commit yok

İki kez test kırmızıyken push edildi; sebebi her seferinde aynıydı: komutlar
zincirlendiği için sonuç görülmeden commit'e geçildi.

**Kural:** `php artisan test` ve `git commit` **ayrı adımlar**. Çıktı okunur,
sonra commit edilir.

### 10.8 Görüş alanı dışındaki şey ölçülmeden değiştirilmez

Vercel yapılandırması iki kez tahminle değiştirildi, ikisi de yanlış çıktı
(`composer` PATH'te sanıldı; filesystem rota sırası yanlış kuruldu). Üçüncüde
ölçüldü ve otuz saniyede çözüldü. Teşhis yolları §12.2.2'de.

### 10.9 Geçici dosyalar `.scratch/` altına

Arka plan analiz ajanları bir kez `tests/` altına sonda dosyaları bıraktı ve
bunlar farkında olmadan commit'lendi. Tek seferlik test/hata ayıklama betikleri
gitignore'lu `.scratch/` altına yazılır.

**İkili dosya depoya girmez.** `cloudflared.tgz` ve `composer.phar` bir kez kök
commit'e girdi; depo 21 MB'tan 332 KB'a inerken geçmişin yeniden yazılması
gerekti ve arkasında hâlâ temizlenmemiş bir tuzak bıraktı — bkz.
§13.

### 10.10 Her yeni dalgada kontrol listesi

- [ ] Migration + `PostgresSecurity::lockDown()` — **yeni tablolar RLS'i miras
      almaz.** Postgres'te "varsayılan RLS" diye bir şey yok; yetkiler kapalı
      doğar ama RLS açık değildir. Muhafız test bunu zorunlu kılar.
- [ ] Factory — testler `Model::create([...])` ile elle kurmasın.
- [ ] Test (`php artisan test` yeşil) → **ayrı adımda** commit → `main`'e push.
- [ ] Canlı `migrations` defteri ile `database/migrations/` karşılaştırması (§4).
- [ ] Yeni form → `lang/tr/validation.php` `attributes` girdisi; yoksa mesajda
      İngilizce alan adı çıkar.
- [ ] Yeni controller kökte değil alt klasörde — muhafız testteki
      `Http/Controllers/**/*.php` deseni kökü taramıyor.
- [ ] Yeni ortam değişkeni → §12 tablosu.

---

## 11. Kurulum ve testler

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=AdminSeeder
php artisan serve
```

Seeder `admin@kralkafe.com` hesabını oluşturur ve **şifreyi rastgele üretip
konsola yazar** — şifre koda gömülü değildir. Kendi şifreni belirlemek için:
`ADMIN_PASSWORD=... php artisan db:seed --class=AdminSeeder`.

Ardından `http://127.0.0.1:8000`. Hepsini birden (sunucu + kuyruk + log + vite)
çalıştırmak için `composer run dev`.

> **`git pull` sonrası ilk iş `php artisan migrate:status`.** Yeni migration
> gelmişse sayfalar `no such table` ile 500 verir ve sebebi görünmez (§10.4).

### 11.1 İsteğe bağlı ayarlar

| Değişken | Ne işe yarar |
|---|---|
| `OPENAI_API_KEY` | Stok fotoğrafı ve deneme PDF'i analizi. Boş bırakılırsa sayfalar çalışır, yalnızca yapay zekâ analizi devre dışı kalır. |
| `UPLOAD_DISK` | Yüklenen dosyaların gideceği disk. Yerelde `public`, serverless ortamda `s3`. |
| `MAIL_MAILER` | Şifre sıfırlama e-postası. Varsayılan `log`; gerçek gönderim için SMTP gerekir. |

### 11.2 Testler

```bash
php artisan test
```

213 test, 715 doğrulama. Testler `:memory:` SQLite kullanır, yerel veritabanına
dokunmaz — bu yüzden `migrate:fresh` çalıştırmak için hiçbir sebep yok (§10.3).

**Stil uyarısı:** proje Tailwind ya da Bootstrap kullanmıyor; stiller elle
yazılmış `public/css/app.css`'te. Tanımsız bir sınıf adı hata vermez, sessizce
hiçbir şey yapmaz — muhafız test bunu yakalar (§10.6).

---

## 12. Dağıtım: Vercel + Supabase

Bu proje bir Laravel 12 uygulaması. Vercel serverless çalıştığı için iki şeyin
uygulama dışına taşınması gerekiyor: **veritabanı** ve **yüklenen dosyalar**.
Her ikisi de Supabase'in ücretsiz katmanıyla karşılanabiliyor.

### 12.1 Supabase

1. [supabase.com](https://supabase.com) üzerinde yeni bir proje aç.
2. **Veritabanı**: Project Settings → Database → Connection string.
   Uygulama için **Session pooler** kullan: `aws-0-<bölge>.pooler.supabase.com`
   port `5432`. Doğrudan bağlantı (`db.<ref>.supabase.co`) yalnızca IPv6
   üzerinden geliyor, transaction pooler (`6543`) ise aşağıda anlatılan
   boolean sorununa yol açıyor.
3. **Depolama**: Storage → yeni bir bucket oluştur (örn. `kral-kafe`).
   Ardından Project Settings → Storage → S3 access keys'ten bir anahtar üret.

### 12.2 Vercel ortam değişkenleri

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
| `KAFE_DENEME_HATIRLATMA_GUN` | Kaç gün kala deneme hatırlatıcısı uyarı rengine döner. Varsayılan `7`; tanımlamak zorunlu değil |
| `KAFE_ODEME_VADESI_GUN` | Abonelik başlangıcından kaç gün sonra ödeme "gecikmiş" sayılır. Varsayılan `7`; tanımlamak zorunlu değil |
| `KAFE_IPLER` | **Boş bırak.** IP kapısı rafta: kafenin IP'si dinamik ölçüldü (bkz. §9.3) |
| `LOG_CHANNEL` | `stderr` — **panelde `stack` tanımlıysa sil.** `api/index.php` bu değeri yalnızca *tanımsızsa* `stderr` yapar; panelde `stack` duruyorsa çerçeve `storage/logs`'a yazmaya çalışır, orası salt okunur ve uygulama loglarken **ikinci bir 500** üretir |
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

#### Hangi havuz, neden

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

#### Deneme raporu analizi ve süre sınırı

`vercel.json` içinde `maxDuration: 60`: deneme PDF'inin yapay zeka analizi
yükleme isteğinin içinde çalışır ve 10 saniyelik varsayılan sınırı aşabilir.
Analiz `OPENAI_API_KEY` ister (stok analiziyle aynı anahtar); anahtar yoksa
dosya yine kaydedilir, durum "Analiz başarısız" olur ve panelden yeniden
denenebilir. PDF'ler `UPLOAD_DISK` üzerinde `deneme-raporlari/<öğrenci>/<uuid>.pdf`
yolunda durur.

### 12.2.1 Derleme ayarları: hepsi boş kalmalı

Vercel panelinde **Settings → Build & Development Settings** altındaki üç alanı
da **boş / kapalı (gri)** bırak. Install Command'a bir şey yazma.

| Alan | Değer |
|---|---|
| Framework Preset | Other (`vercel.json`'daki `"framework": null` bunu zorluyor) |
| Build Command | boş |
| Output Directory | `public` |
| Install Command | **boş** |

#### Neden Install Command yazılmıyor

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

#### Derleme zamanı kancası: `composer.json` → `scripts.vercel`

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

#### Yönlendirme: `index.php` neden indiriliyordu

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
#### npm neden yok

Hiçbir blade `@vite` kullanmıyor; `vite build` kimsenin yüklemediği varlıklar
üretiyordu. Stiller elle yazılan `public/css/app.css`'te ve `outputDirectory: public`
sayesinde doğrudan servis ediliyor. `vite.config.js` ve `package.json` yerinde
duruyor — ileride arayüz yenilenirse kullanılabilir, sadece dağıtımda
çalıştırılmıyor.

### 12.2.2 Sorun çıkınca: önce Supabase logları, sonra tahmin

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

### 12.3 Migration'lar

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

### 12.4 E-posta

Şifre sıfırlama e-posta gönderir. `MAIL_MAILER=log` olduğu sürece mail
gerçekten gitmez, log'a yazılır. Gerçek kullanım için bir SMTP sağlayıcısı
(Resend, Postmark, Mailgun) tanımlanmalı.

### 12.5 Doğrulanmamış

Üretim ortamının tam dökümü (`LOG_CHANNEL`, `UPLOAD_DISK`, `AWS_*`) canlıda
satır satır doğrulanmadı. 20 Eylül 2026'daki önizleme raporunda `LOG_CHANNEL=stack`
ve `UPLOAD_DISK=public` görüldü, ama o rapor **önizleme** ortamındandı ve Vercel'de
değişkenler ortam başına tanımlanır. İlk fırsatta üretimde kontrol edilmeli;
`LOG_CHANNEL=stack` üretimde duruyorsa yukarıdaki tuzak aktif demektir.

### 12.6 Bilinen kısıtlar

- **Vercel Hobby katmanı ticari kullanıma kapalı.** Kafe gerçekten bu sistemi
  işletmede kullanacaksa ücretli plana geçmek gerekir.
- **Supabase ücretsiz katmanı 7 gün hareketsizlikten sonra projeyi duraklatır**;
  panelden elle uyandırmak gerekir.
- **`vercel-php` resmî değil**, topluluk tarafından sürdürülüyor.
- PHP serverless'ta soğuk başlangıçlar yavaştır.

---

## 13. Git geçmişi

> Bu deponun geçmişi bir kez yeniden yazıldı. Kalan tuzaklar ve kurtarma
> reçeteleri burada.

Bu deponun geçmişi **yeniden yazıldı**. Bu belge neden yazıldığını, hangi
tuzakların kaldığını ve bir daha aynı kazaların yaşanmaması için ne yapılması
gerektiğini anlatır.

### 13.1 Özet

Depo `brashlab/kral-kafe` altındayken kök commit'e iki yardımcı araç
commit'lenmişti: `cloudflared.tgz` (20,1 MiB) ve `composer.phar` (3,1 MiB).
Deponun neredeyse tamamı bu iki dosyaydı. `sadikanil/kral-kafe` adresine
taşınırken `git filter-branch` ile geçmişten temizlendiler; depo 21 MB'tan
332 KB'a indi.

Geçmiş yeniden yazıldığı için **eski ve yeni commit SHA'ları farklı**. Bu,
aşağıdaki tuzağın kaynağı.

### 13.2 Kalan tuzak: `brashlab` remote'u

`brashlab` remote'u silinmedi, yalnızca adı değiştirildi. Dolayısıyla depoda
**yeniden yazılmadan önceki geçmişe işaret eden canlı bir ref** duruyor:

```
refs/remotes/brashlab/main -> e509af4   (eski geçmiş, iki büyük dosya İÇİNDE)
refs/remotes/origin/main   -> da5f269   (yeni geçmiş, temiz)
```

Bu iki geçmiş **ortak ataya sahip** (`33c8a20`). Sonuçları:

1. **Otomatik fetch temizliği geri alır.** VS Code'un `git.autofetch` ayarı tüm
   remote'ları çeker. Bir kez çektiğinde `brashlab/main` eski değerine
   *forced-update* olur ve silinen 24 MB nesne yerele geri iner. Bu bir kez
   yaşandı; yerel depo hâlâ 21 MiB.
2. **Kazara merge mümkün.** Ortak ata bulunduğu için git
   `--allow-unrelated-histories` istemez, yani koruma bariyeri devreye girmez.
   Böyle bir merge 18 add/add çakışması üretir, silinen iki dosyayı geri ekler
   ve çalışma ağacına çakışma işaretleri bulaştırır. Bu da bir kez yaşandı.

#### Şu an alınmış önlem

```bash
git config remote.brashlab.skipFetchAll true
```

`git fetch --all` (ve VS Code'un otomatik fetch'i) artık `brashlab`'a
dokunmuyor. Elle `git fetch brashlab` hâlâ çalışır.

#### Kalıcı çözüm

`brashlab` ile işiniz bittiyse remote'u tamamen kaldırın; tuzak da yerel
şişkinlik de ortadan kalkar:

```bash
git remote remove brashlab
git reflog expire --expire=now --all
git gc --prune=now
```

Geri almak tek komut: `git remote add brashlab https://github.com/brashlab/kral-kafe.git`

> Not: `brashlab/kral-kafe` deposunun kendisi temizlenmedi; iki büyük dosya
> orada hâlâ duruyor.

### 13.3 Kurallar

**Depoya ikili dosya girmez.** Yardımcı araçlar (`cloudflared`, `composer.phar`)
`~/bin` ya da gitignore'lu bir `.scratch/` altına inmeli. İkisi de artık
`.gitignore`'da; çalışma dizininde duruyorlar ama sürüm kontrolüne girmiyorlar.

**İlk push'tan önce büyük dosya taraması yapın:**

```bash
git rev-list --objects --all |
  git cat-file --batch-check='%(objecttype) %(objectname) %(objectsize) %(rest)' |
  awk '$1=="blob" && $3 > 1000000 {print $3, $4}' | sort -rn
```

**Geçmişi yeniden yazmadan önce yedek alın** ve yedeği doğrulayana kadar
silmeyin:

```bash
git clone --mirror . ../kral-kafe-yedek.git
```

Geçen sefer `refs/original` ve reflog, işlem doğrulanmadan hemen silindi; geri
dönüş noktası kalmadı. `git filter-branch` artık git tarafından önerilmiyor,
`git-filter-repo` daha güvenli.

**Geçmiş yeniden yazıldıktan sonra eski remote'u derhal kaldırın.** Yukarıdaki
iki kazanın ikisi de bu adım atlanmasaydı imkânsız olurdu.

**Merge etmeden önce hedefi gözle doğrulayın:**

```bash
git log --oneline -1 <hedef>
```

### 13.4 Kurtarma reçeteleri

**Yarım kalmış merge çalışma ağacını bozduysa** — çakışma işaretleri PHP
dosyalarına bulaşır ve testler sözdizimi hatası verir:

```bash
git status                    # MERGE_HEAD var mı, hangi commit'i gösteriyor
git merge --abort
git status --porcelain        # boş olmalı
php artisan test              # yeşile dönmeli
```

`merge --abort` takip edilmeyen ama sahnelenmiş dosyaları siler; yukarıdaki iki
araç dosyası bu şekilde iki kez silindi ve elle geri konuldu.

**Yerel depo beklenmedik şekilde şiştiyse:**

```bash
git count-objects -vH                            # size-pack'e bak
git for-each-ref refs/remotes                    # hangi ref eski geçmişi tutuyor
```

### 13.5 Doğrulanmış mevcut durum

- Yerel `HEAD` ile `origin/main` eşit
- Uzaktaki geçmişte büyük dosya yok, çakışma işareti yok
- Hiçbir commit'te `.env` ya da API anahtarı bulunmuyor (tüm geçmiş tarandı)
- Yerel depo `brashlab` refleri nedeniyle hâlâ ~21 MiB; uzaktaki temiz

---

## Lisans

GNU General Public License v2.0 — bkz. [LICENSE](LICENSE).
