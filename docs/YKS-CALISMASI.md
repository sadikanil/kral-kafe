# Kral Kafe — Yapılanlar, Öneriler ve YKS Dönemi Çalışması

_Hazırlanma: 20 Eylül 2026. Kaynaklar: `GUNCELLEMELER.md` (ürün vizyonu),
`UYGULAMA-PLANI.md` (dalga kararları), `DEVIR.md` (durum), kod tabanı._

Bu belge üç şeyi tek yerde toplar:

1. **Bugün canlıda ne var** — kim kullanır, nerede.
2. **Önerilmiş ama yapılmamış ne var** — yol haritasının tamamı.
3. **YKS dönemi çalışması** — öğrencinin ihtiyaçları, velinin koçla birlikte
   gözlemde tutacağı özellikler ve önerilen sıra.

Ürün felsefesi değişmiyor: **veri gösterilir, yorum üretilmez.** Sıralama
tablosu, rozet, otomatik psikolojik/akademik yorum yok. Veli salt okunur.

---

## 1. Bugün canlıda olanlar

### 1.1 Kafe işletmesi (özgün uygulama)

| Özellik | Kim kullanır | Nerede |
|---|---|---|
| QR ile tüketim kaydı (raf/dolap/buzdolabı QR'ı → ürün seç → kaydet, 30 sn geri alma) | Öğrenci | `/tuketim/{qr}` |
| Tüketim geçmişi ve aylık özet | Öğrenci | `/kullanici/gecmis` |
| Ürün ve lokasyon yönetimi, lokasyon QR yazdırma | Yönetici | Yönetim → Ürünler / Lokasyonlar |
| Fotoğrafla stok sayımı (OpenAI görsel analizi), tutarsızlık kaydı ve çözümü | Yönetici | Yönetim → Stok Sayım |
| Aylık fatura üretimi, kullanıcı raporu, CSV dışa aktarım (özet + detay) | Yönetici | Yönetim → Raporlar |
| Yönetici paneli: bugünkü tüketimler, ayın ürünleri, açık tutarsızlıklar | Yönetici | `/yonetim` |
| Giriş, şifre sıfırlama, Türkçe hata mesajları | Herkes | `/giris`, `/sifremi-unuttum` |

### 1.2 Çalışma takibi (Dalga 0–6b, Eylül 2026)

| Dalga | Özellik | Kim kullanır | Nerede |
|---|---|---|---|
| 0 | Postgres RLS kilidi (her yeni tablo), kafe saat dilimi, `LocalDay`, rol enum'u | Altyapı | — |
| 1 | Altı rol: yönetici, koç, öğretmen, öğrenci, veli, görevli. Abonelik yalnızca öğrenciyi kilitler | Yönetici | Kullanıcı formu |
| 2 | Masa tanımı, kalıcı masa QR'ı, toplu yazdırma | Yönetici | Yönetim → Masalar |
| 3 | Masa QR'ı okut → çalışma başlat; panelden ya da QR ile bitir; aynı anda tek açık oturum (DB kısıtı) | Öğrenci | `/masa/{kod}`, panel |
| 3 | Canlı ekran: içeride kim, hangi masada, ne kadardır; boş masa sayısı; 12 saati aşan anomaliler | Yönetici | Yönetim → Canlı Ekran |
| 4 | Unutulan oturumların kapanışta otomatik kapanması (tembel kapatma, cron opsiyonel) | Sistem | — |
| 5 | Bugün / bu hafta / bu ay süre, üst üste gelme serisi | Öğrenci | Panel |
| 5 | Haftalık çalışma hedefi (saat) ve ilerleme çubuğu; hedef geçmişi korunur | Yönetici koyar, öğrenci görür | Kullanıcı formu, panel |
| 6 | Veli–öğrenci bağı (çoka çok); yönetici formundan kurulur | Yönetici | Kullanıcı formu |
| 6 | Veli paneli (salt okunur): çocukları, "şu an içeride / son geliş", gün-hafta-ay süre, seri, hedef, son 14 gün, geliş-çıkış listesi | Veli | `/veli`, `/veli/ogrenci/{id}` |
| 6b | Deneme sınavı takvimi: yönetici planlar (tür, tarih, saat, not) | Yönetici | Yönetim → Deneme Takvimi |
| 6b | Aylık takvim görünümü + "sıradaki deneme, N gün kaldı" hatırlatıcısı | Öğrenci, veli | Panel üstü, `/kullanici/denemeler`, `/veli/denemeler` |

Altyapı: Laravel 12, Vercel (`main` dalından otomatik dağıtım), Supabase
Postgres (session pooler), 192 test / 552 iddia.

---

## 2. Önerilmiş ama yapılmamış olanlar

### 2.1 Planlı dalgalar (UYGULAMA-PLANI)

| Dalga | İçerik | Not |
|---|---|---|
| 7 | Paket tanımı (`packages`, `package_items`), öğrenciye paket atama (`subscriptions`), ödeme kayıtları (`payments`), ödendi/bekliyor/gecikmiş | Fatura tutarı **her zaman** `subscriptions.price`'tan; katalog fiyatı değişince geçmiş değişmesin |
| 8 | Tüketimin masaya ve pakete bağlanması (`consumptions.table_id`, `covered_by_package`) | `Consumption::boot` `total_price`'ı koşulsuz eziyor; kapsam mantığı oraya girmeli |

### 2.2 V1 önerileri (GUNCELLEMELER §V1)

| # | Özellik | Kategori |
|---|---|---|
| 13 | Deneme sonucu girişi (ders bazlı D/Y/net) | Sonuç |
| 14 | Net gelişim grafikleri | Sonuç |
| 15 | Koç paneli: öğrenci listesi + özet + not | İlişki |
| 16 | Görev atama ve tamamlama | Düzen |
| 17 | Otomatik haftalık veli raporu (panelde) | İlişki |
| 18 | Ders bazlı çalışma kırılımı (oturuma etiket) | Düzen |
| 19 | Yardım/çağrı butonu + yönetici bildirimi | İşletme |
| 20 | Devamlılık düşüş uyarısı (koç/yönetici) | İlişki |

### 2.3 V2 önerileri

| # | Özellik |
|---|---|
| 21 | Zayıf konu takibi + konuya görev bağlama |
| 22 | Öğretmene soru sorma (fotoğraflı) + öğretmen programı |
| 23 | Haftalık raporun PDF / e-posta / WhatsApp ile gönderimi |
| 24 | Pomodoro / odak seansları |
| 25 | Geri bildirim (anonim seçenekli) |
| 26 | Ortak kafe hedefi (kolektif) |
| 27 | Online ödeme entegrasyonu |
| 28 | Çalışma ↔ net korelasyon görünümü (nedensellik iddiası olmadan) |

### 2.4 Rafa kaldırılanlar ve açık sorular

- **IP kapısı** — kafenin IP'si dinamik (TT ADSL havuzu); beyaz liste kapı olamaz.
  `config/kafe.php`'deki iki ayarı hiçbir kod okumuyor.
- **Anomali "gördüm" işareti** — bugün yedi günlük pencere; kalıcı çözüm ertelendi.
- **Açık soru #4** — deneme sonuçlarını kim girer: öğrenci mi, koç mu?
- **Açık soru #5** — veli deneme sonuçlarını doğrudan görsün mü (gizlilik varsayılanı)?

### 2.5 Bilinçli olarak yapılmayacaklar

Sıralama/liderlik tablosu, rozet, mobil uygulama, otomatik psikolojik/akademik
yorum, dinamik QR.

---

## 3. YKS dönemi çalışması

### 3.1 Dönemin doğası

YKS öğrencisinin yılı üç ritimle akar ve ürün bu ritimleri görünür kılmalı:

- **Günlük ritim** — kafeye geliş, oturum, ders kırılımı, kısa tekrar. Ölçülebilir
  olan bugün: geliş/çıkış ve süre. Ölçülmeyen: hangi derse çalıştığı.
- **Haftalık ritim** — hedef saat, deneme (genelde hafta sonu), koçla görüşme,
  görev listesi. Ölçülebilir olan bugün: haftalık süre ve hedef, deneme takvimi.
  Ölçülmeyen: deneme sonucu, görevler, koçun gözlemi.
- **Dönemsel ritim** — TYT/AYT dengesi, konu bitirme, net eğrisi, sınava geri
  sayım. Bugün hiçbiri yok.

Üç aktörün ihtiyacı farklı ve çatışabilir:

| Aktör | En çok istediği | Tuzak |
|---|---|---|
| Öğrenci | Düzen ve küçük, ulaşılabilir hedefler; dikkat dağıtmayan araç | Gözetim hissi → sistemi "kandırma" (QR okutup oturma) |
| Veli | "Gerçekten çalışıyor mu?" sorusuna güvenilir cevap; koçla ortak dil | Ham veriyi yorumlayıp baskı kurmak |
| Koç | Düşüşü erken görmek, görev ve not bırakmak, veliyle ne paylaşılacağını seçmek | Not/rapor yükü; her şeyi elle girmek |

Tasarım ilkesi buradan çıkıyor: **koç yorumlar, veli okur, öğrenci bilir.**
Veli paneli ham veriyi değil, koçun **paylaşmayı seçtiği** yorumla birlikte
veriyi görür. Öğrenci hangi bilginin veliye gittiğini görür.

### 3.2 Velinin koçla birlikte gözlemde tutacağı özellikler

Aşağıdaki liste, veli panelinin bugünkü hâlinin (geliş/çıkış, süre, seri, hedef,
deneme takvimi) üzerine gelecek katmanlar. Her satırda **kim yazar / kim okur**
ve gizlilik varsayılanı belirtildi.

#### A. Haftalık veli raporu (V1 #17) — en yüksek değer

- **Ne:** Her pazar üretilen tek sayfa: geliş gün sayısı, toplam süre, geçen
  haftaya göre değişim, hedef tutma, çözülen deneme sayısı ve net değişimi
  (varsa), koç yorumu (varsa).
- **Kim yazar:** Sistem üretir, koç yorum ekler. **Kim okur:** Veli, öğrenci.
- **Neden ilk:** Velinin haftada 1–2 kez baktığı tek ekran bu. Bugünkü panelin
  verisiyle **hemen** üretilebilir; deneme ve koç yorumu geldikçe zenginleşir.
- **Teknik:** `weekly_reports` (student_id, week_start, payload json,
  coach_comment). Vercel'de cron yok → "tembel üretim": rapor ilk açıldığında
  hesaplanır ve saklanır (Dalga 4'teki tembel kapatma kararıyla aynı mantık).

#### B. Koç rolü, koç–öğrenci ataması ve koç notları (V1 #15)

- **Ne:** Koç paneli: öğrenci listesi (haftalık süre, hedef %, seri, son deneme),
  düşüş gösteren öğrenci vurgusu, öğrenci detayı, not bırakma.
- **Not görünürlüğü üç seviye:** `private` (yalnız koç), `parent` (veli de),
  `student_and_parent`. **Varsayılan `private`.** Veli yalnızca koçun açıkça
  paylaştığını görür.
- **Teknik:** `coach_assignments`, `coach_notes`. `accessibleStudentIds()`
  bugün koç için boş dönüyor; atama tablosu gelince tek satırla genişler
  (global scope yok, Dalga 6 kararı).

#### C. Deneme sonucu girişi ve net gelişimi (V1 #13, #14)

- **Ne:** Deneme takvimindeki bir denemeye (Dalga 6b) öğrenci başına sonuç:
  ders bazlı D/Y/boş, net otomatik. Ders listesi TYT ve AYT için **veriden**
  gelir (`subjects` tablosu), koda gömülmez.
- **Kim yazar:** Öneri: **öğrenci girer, koç doğrular** (açık soru #4'e cevap
  önerisi). Koç girişi darboğaz olur; öğrenci girişi hızlıdır, koç "doğrulandı"
  işaretiyle güveni sağlar.
- **Kim okur:** Öğrenci, koç. **Veli:** `student_parent.can_view_exams`
  bayrağıyla; **varsayılan kapalı**, öğrenciyle konuşularak açılır (açık soru #5'e
  cevap önerisi). Veli raporunda "net değişimi" bayrak açıksa görünür.
- **Grafik:** ders bazlı net serisi, deneme türüne göre ayrı (TYT/AYT).
  Nedensellik iddiası yok; çalışma süresiyle yan yana gösterim V2 (#28).
- **Teknik:** `mock_exam_results` (exam_event_id, student_id, subject_id,
  correct, wrong, blank; net hesaplanır), `subjects` (type, name, order).
  Dalga 6b'nin `exam_events` tablosu bunun üstü; ayrı `mock_exams` tablosuna
  gerek kalmadı — GUNCELLEMELER şemasındaki `mock_exams` bu tabloya katlanır.

#### D. Görev sistemi ve tamamlama oranı (V1 #16)

- **Ne:** Koç görev atar (başlık, ders, hedef tarih), öğrenci tamamlar.
  Haftalık tamamlama oranı koç ve veli panelinde.
- **Kim yazar:** Koç (ve isteğe bağlı öğrenci kendine). **Kim okur:** Öğrenci,
  koç, veli (oran ve başlıklar; görev içeriği koçun tercihiyle).
- **Teknik:** `tasks`. Veli paneline "bu hafta 5/7 görev" satırı.

#### E. Devamlılık düşüş uyarısı (V1 #20)

- **Ne:** Kural tabanlı, yorumsuz sinyal: "son 7 günde 2 geliş, önceki 7 günde
  5", "hedefin %40'ı", "3 gündür gelmedi". Koç panelinde vurgu; veli raporunda
  **koç paylaşırsa**.
- **Neden koça önce:** Veliye doğrudan giden düşüş uyarısı baskıya dönüşür.
  Önce koç görür, konuşur, isterse raporda yorumuyla paylaşır.
- **Teknik:** Tablo yok; `StudyStats` üzerinden hesaplanır, eşikler
  `config/kafe.php`.

#### F. Ders bazlı çalışma kırılımı (V1 #18)

- **Ne:** Oturum başlarken ya da biterken tek dokunuşla ders etiketi
  (Matematik, Türkçe, …). Zorunlu değil; etiketsiz oturum "genel".
- **Değer:** Veli ve koç "12 saat çalıştı" yerine "8 saat matematik, 0 saat
  Türkçe" görür; görevle ve zayıf konuyla bağlanır.
- **Teknik:** `study_sessions.subject_id` nullable; `subjects` C ile ortak.

#### G. Sınava geri sayım ve dönem takvimi (küçük, hemen)

- **Ne:** Deneme takvimine "resmî sınav" türü (YKS tarihi, LGS tarihi); panelde
  büyük geri sayım; takvimde işaretli. Dalga 6b'nin altyapısıyla bir günlük iş.
- **Teknik:** `ExamType::Official`, `exam_events` üstüne bayrak yok, tür yeter.

#### H. Zayıf konu listesi (V2 #21)

- **Ne:** Deneme sonucundan (ders bazlı düşük net) ya da koç girişiyle konu
  listesi; her konuya görev bağlanır; konu "kapandı" işaretlenir.
- **Kim okur:** Öğrenci, koç; veli yalnızca sayı ("açık 4 konu, bu hafta 2 kapandı").

#### I. Koç–veli görüşme kaydı (küçük, B ile birlikte)

- **Ne:** Koç görüşme sonrası kısa özet bırakır ("Ekim planı konuşuldu, hedef
  20 saat"). Sohbet/mesajlaşma **değil** — tek yönlü kayıt; WhatsApp'ın yerini
  almaya çalışmaz, kayıt altına alır.
- **Teknik:** `coach_notes` içinde `kind = meeting`; ek tablo gerekmez.

#### J. Öğrencinin kendi günlük notu (isteğe bağlı, düşük öncelik)

- **Ne:** Oturum bitirirken "bugün nasıldı" tek satır. Yalnızca öğrenci ve
  (öğrenci isterse) koç görür. **Veliye gitmez.** Sistem yorumlamaz.
- **Neden düşük:** Vizyon "otomatik yorum yok" diyor; bu da yorum değil, kayıt.
  Ama yük ekler; öğrenci istemezse boş kalır.

#### K. Bildirim kanalı (V2 #23)

- Bugün her hatırlatıcı **panel içi**. E-posta için `MAIL_MAILER=log`; gerçek
  gönderim SMTP/Resend gerektirir. WhatsApp resmî API ücretli ve onaylı şablon
  ister. Öneri: önce e-posta (haftalık rapor + deneme hatırlatması), WhatsApp
  sonra. Vercel'de cron tetiklemesi (Vercel Cron → `/api/cron/...` uç noktası)
  ayrı planlanmalı.

### 3.3 Gizlilik ve güven kuralları (özellik yazılmadan önce sabit)

1. Veli **salt okunur**; hiçbir veli rotası POST/PUT/DELETE taşımaz (test var).
2. Koç notu **varsayılan özel**; paylaşım açık seçimle.
3. Deneme sonuçları veliye **varsayılan kapalı**; öğrenci başına bayrak.
4. Öğrenci, veliye ne gittiğini **kendi panelinde görür** ("velinizle
   paylaşılanlar" bölümü). Gizli izleme yok.
5. Sıralama, karşılaştırma, yüzdelik dilim **yok**. Öğrenci yalnızca kendi
   geçmişiyle karşılaştırılır.
6. Düşüş sinyalleri önce koça; veliye koç yorumuyla.
7. Yorum üreten tek kişi koç. Sistem sayı gösterir, sıfat üretmez.

### 3.4 Önerilen sıra

İki hat var; ikisi paralel yürüyebilir çünkü tabloları kesişmiyor.

**İşletme hattı (para):**

| Sıra | Dalga | Büyüklük | Bağımlılık |
|---|---|---|---|
| 1 | 7 · Paket ve ödeme | L | — |
| 2 | 8 · Tüketimi masaya ve pakete bağlama | M | 7 |

**Koçluk hattı (YKS değeri) — veli paneli yeni bittiği için şimdi en verimli an:**

| Sıra | Dalga | İçerik | Büyüklük | Bağımlılık |
|---|---|---|---|---|
| 1 | 9 | Sınava geri sayım (G) + haftalık veli raporu, tembel üretim (A) | M | 6, 6b |
| 2 | 10 | Koç rolü aktif: atama, koç paneli, koç notları üç görünürlükle, görüşme kaydı (B, I) | L | 6 |
| 3 | 11 | `subjects` + deneme sonucu girişi + net grafiği + `can_view_exams` (C) | L | 6b, 10 |
| 4 | 12 | Görevler + tamamlama oranı (D) | M | 10 |
| 5 | 13 | Devamlılık düşüş sinyalleri, koç panelinde (E) | S | 10 |
| 6 | 14 | Ders etiketi (F) ve zayıf konu listesi (H) | M | 11 |
| 7 | 15 | E-posta gönderimi + Vercel Cron (K) | M | 9 |

S ≈ bir oturum, M ≈ iki-üç oturum, L ≈ dört ve üzeri. Her dalga: migration +
`PostgresSecurity::lockDown()` + factory + test + canlı ledger karşılaştırması
(bkz. `DEVIR.md` §1).

Öneri: **Dalga 7'yi bitir, sonra Dalga 9 ve 10'u sırayla yap.** Dalga 9 küçük
ve velinin elinde hemen somut bir çıktı bırakır; Dalga 10 sistemin "koçluk"
tarafını açar ve C–E'nin hepsi ona bağlı.

### 3.5 Karar gerektiren sorular

| # | Soru | Öneri | Etkisi |
|---|---|---|---|
| 1 | Deneme sonucunu kim girer? | Öğrenci girer, koç doğrular | C'nin formu ve yetkisi |
| 2 | Veli netleri görsün mü? | Varsayılan kapalı, öğrenci başına bayrak | Veli raporunun içeriği |
| 3 | Koç kim? Yönetici mi, ayrı kişiler mi? | Ayrı rol (var); yönetici de koç olabilir | Dalga 10'un yetki matrisi |
| 4 | Haftalık rapor hangi gün kapanır? | Pazar 23:59 (kafe saati) | `LocalDay::weekBounds` zaten pazartesi başlıyor |
| 5 | Bildirim kanalı ilk hangisi? | E-posta | Dalga 15'in sağlayıcı seçimi |
| 6 | YKS 2027 tarihi | Yönetici takvime "resmî sınav" olarak girer | Geri sayım |

---

## 4. Veri modeli özeti (önerilen eklemeler)

```
subjects              id, exam_type (tyt|ayt|lgs), name, sort_order
coach_assignments     id, coach_id, student_id, assigned_at, is_active
coach_notes           id, coach_id, student_id, kind (note|meeting), body,
                      visibility (private|parent|student_and_parent), created_at
mock_exam_results     id, exam_event_id, student_id, subject_id,
                      correct, wrong, blank, net, verified_by, verified_at
tasks                 id, student_id, assigned_by, subject_id?, title,
                      due_date, status (open|done|cancelled), completed_at
weekly_reports        id, student_id, week_start, payload (json),
                      coach_comment, generated_at
weak_topics           id, student_id, subject_id, topic, source, status
study_sessions        + subject_id (nullable)
student_parent        + can_view_exams (bool, default false)
exam_events           exam_type'a 'official' eklenir
```

Mevcut tablolarla çakışma yok. `mock_exams` tablosu (GUNCELLEMELER şeması)
Dalga 6b'nin `exam_events`'ine katlandı; ayrıca açılmaz.
