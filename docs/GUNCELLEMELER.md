# Kral Cafe — Ürün Güncelleme Planı

> **Bu dosya nedir?**
> Kral Cafe'nin bundan sonraki tüm güncellemelerinin tek toplanma noktası.
> Kodda **bugün ne var**, vizyonda **ne eksik**, hangi sırayla **ne yapılacak**
> ve hangi kararlar hâlâ **açık** — hepsi burada.
>
> **Nasıl kullanılır?**
> Yeni bir özellik fikri → §5'e FEATURE kartı olarak eklenir.
> Bir özellik tamamlandı → §4'teki tabloda durumu güncellenir + §12'ye satır eklenir.
> Ürün kararı değişti → §11'de ilgili açık soru kapatılır.
>
> Son güncelleme: 19 Eylül 2026 · Durum: kod tabanı analiz edildi, plan taslak

---

## 1. Bugün kodda ne var?

Depo şu anda ürün vizyonunun **tamamını değil**, yalnızca **kafe/stok dilimini**
uyguluyor. Mevcut sistem "öğrenci raftan ürün alır → QR okutur → ay sonu fatura"
akışı üzerine kurulu. Öğrencinin **çalışmasıyla ilgili tek bir satır kod yok**.

| Alan | Durum | Nerede |
|---|---|---|
| Kimlik doğrulama + şifre sıfırlama | ✅ Var | `routes/auth.php`, `app/Http/Controllers/Auth/` |
| Roller | ⚠️ Yalnız `student` / `admin` | `users.role` enum |
| Abonelik durumu (aktif/pasif/askıda) | ✅ Basit hâliyle var | `users.subscription_status`, `ActiveSubscription` middleware |
| Paket/fiyat tanımı | ❌ Yok | — |
| Lokasyon + QR üretimi + toplu QR yazdırma | ✅ Var | `Location`, `LocationController`, `QRCodeService` |
| **Masa** entity'si | ❌ Yok (lokasyon = raf/dolap/buzdolabı) | — |
| QR ile tüketim kaydı + 60 sn geri alma | ✅ Var | `ConsumptionController`, `Consumption::canUndo()` |
| **QR ile çalışma oturumu (giriş/çıkış)** | ❌ Yok | — |
| Ürün yönetimi (fiyat, emoji, kategori) | ✅ Var | `Product`, `ProductController` |
| Stok: fotoğraf → OpenAI `gpt-4o` → inceleme → onay | ✅ Var | `StockController`, `OpenAIStockAnalyzer` |
| Tutarsızlık (beklenen vs sayılan) kaydı ve çözümü | ✅ Var | `DiscrepancyLog` |
| Aylık kişi bazlı fatura + CSV dışa aktarım | ✅ Var | `MonthlyBill`, `BillingService`, `ReportController` |
| Yönetici dashboard (günlük tüketim, top ürün) | ✅ Var | `Admin/DashboardController` |
| Öğrenci dashboard (tüketim geçmişi/özet) | ✅ Var | `User/DashboardController` |
| Çalışma süresi, hedef, devamlılık | ❌ Yok | — |
| Görev sistemi | ❌ Yok | — |
| Deneme / net takibi | ❌ Yok | — |
| Koç / öğretmen / veli rolleri ve panelleri | ❌ Yok | — |
| Yardım talebi, geri bildirim | ❌ Yok | — |
| Bildirim altyapısı (e-posta dışında) | ❌ Yok | `MAIL_MAILER` varsayılan `log` |

**Teknoloji:** Laravel 12 · PHP 8.2 · Blade · Tailwind 4 + Vite ·
SQLite (yerel) / PostgreSQL–Supabase (üretim) · Vercel dağıtımı ·
OpenAI `gpt-4o` görsel analizi.

**Sonuç:** Mevcut kod, hedeflenen ürünün **%25'i** civarı. Eksik olan kısım da
tam olarak ürünün **asıl değer önerisi** (DEVAM → ÇALIŞMA → SONUÇ zinciri).

---

## 2. Vizyon ile kod arasındaki temel fark

Ürün vizyonunda sistemin merkezinde **öğrencinin çalışması** var; kodda ise
merkezde **ürün tüketimi** var. Bu, terminolojide de çakışıyor:

| Vizyondaki kavram | Koddaki en yakın karşılık | Aynı şey mi? |
|---|---|---|
| Masa (öğrenciye atanmış, QR'lı) | `Location` (raf/dolap/buzdolabı, QR'lı) | ❌ Hayır — ayrı entity gerekir |
| Çalışma oturumu (giriş/çıkış) | — | ❌ Yok |
| Adisyon / sipariş | `Consumption` | ⚠️ Kısmen — paket kapsamı ve masa bağı yok |
| Üyelik paketi | `users.subscription_status` | ❌ Hayır — paket tanımı yok, fiyat yok |
| Öğrenci | `users.role = student` | ✅ Evet |
| Koç / Öğretmen / Veli / Staff | — | ❌ Yok |

**Karar:** `Location` yeniden adlandırılmayacak (stok mantığı doğru çalışıyor);
masalar **ayrı `tables` tablosu** olarak eklenecek. Bir masa isteğe bağlı olarak
bir lokasyona bağlanabilir ama ikisi farklı şeyler.

---

## 3. Özellik kategorileri

Tüm özellikler altı kategoriye ayrılıyor. Öncelik kararları bu kategoriler
üzerinden veriliyor:

- **A · DEVAM** — masa, QR check-in/out, oturum, devamlılık
- **B · ÇALIŞMA** — süre, hedef, ders bazlı kırılım, görev, odak
- **C · SONUÇ** — deneme, net, zayıf konu, gelişim grafiği
- **D · İLİŞKİ** — koç, öğretmen, veli, raporlar, notlar
- **E · İŞLETME** — paket, ödeme, adisyon, stok, yardım talebi, geri bildirim
- **F · PLATFORM** — roller/yetki, bildirim, denetim kaydı (audit), performans

Mevcut kod tamamen **E**'nin içinde. Ürünü ayakta tutan **A + B**, ürünü
değerli kılan **C + D**.

---

## 4. Öncelik: MVP / V1 / V2

**MVP'nin tanımı:** 20 aboneli bir kafenin, kâğıt/Excel kullanmadan çalışabildiği
en küçük sistem. Kriter: *öğrenci geldiğinde iki dokunuşla oturum açılıp
kapanıyor, veli haftalık raporu görüyor, yönetici kimin içeride olduğunu
tek ekranda görüyor.*

### MVP (ilk çıkacak sürüm)

| # | Özellik | Kat. | Durum |
|---|---|---|---|
| 1 | Masa entity'si + masa QR üretimi/yazdırma | A | ⬜ Planlandı |
| 2 | QR ile çalışma oturumu başlat/bitir | A | ⬜ Planlandı |
| 3 | Unutulan oturumların otomatik kapanması | A | ⬜ Planlandı |
| 4 | Öğrenci dashboard: bugün / bu hafta / bu ay süre | B | ⬜ Planlandı |
| 5 | Haftalık çalışma hedefi + ilerleme | B | ⬜ Planlandı |
| 6 | Devamlılık (gün sayısı, gelmediği günler) | A | ⬜ Planlandı |
| 7 | Rol genişletmesi: coach, parent, staff, teacher | F | ⬜ Planlandı |
| 8 | Veli–öğrenci bağı + veli paneli (salt okunur) | D | ⬜ Planlandı |
| 9 | Yönetici canlı ekran: içeride kim, hangi masada, ne kadardır | A | ⬜ Planlandı |
| 10 | Paket tanımı (admin panelinden) + öğrenciye paket atama | E | ⬜ Planlandı |
| 11 | Ödeme durumu takibi (ödendi/bekliyor/gecikmiş) | E | ⬜ Planlandı |
| 12 | Mevcut tüketim akışının masa/paket kapsamına bağlanması | E | ⬜ Planlandı |
| 29 | Deneme sınavı takvimi: yönetici planlar, öğrenci/veli takvim görünümü + panel hatırlatıcısı | C | ✅ Yapıldı (20 Eylül 2026) |

### V1 (MVP'den ~1–2 ay sonra)

| # | Özellik | Kat. | Durum |
|---|---|---|---|
| 13 | Deneme sonucu girişi (ders bazlı D/Y/net) | C | ⬜ |
| 14 | Net gelişim grafikleri | C | ⬜ |
| 15 | Koç paneli: öğrenci listesi + özet + not | D | ⬜ |
| 16 | Görev atama ve tamamlama | B | ⬜ |
| 17 | Otomatik haftalık veli raporu (panelde) | D | ⬜ |
| 18 | Ders bazlı çalışma kırılımı (oturuma etiket) | B | ⬜ |
| 19 | Yardım/çağrı butonu + yönetici bildirimi | E | ⬜ |
| 20 | Devamlılık düşüş uyarısı (koç/yönetici için) | D | ⬜ |

### V2 (sonrası)

| # | Özellik | Kat. | Durum |
|---|---|---|---|
| 21 | Zayıf konu takibi + konuya görev bağlama | C | ⬜ |
| 22 | Öğretmene soru sorma (fotoğraflı) + öğretmen programı | D | ⬜ |
| 23 | Haftalık raporun PDF / e-posta / WhatsApp ile gönderimi | D | ⬜ |
| 24 | Pomodoro / odak seansları | B | ⬜ |
| 25 | Geri bildirim (anonim seçenekli) | E | ⬜ |
| 26 | Ortak kafe hedefi (kolektif gamification) | B | ⬜ |
| 27 | Online ödeme entegrasyonu | E | ⬜ |
| 28 | Çalışma ↔ net korelasyon görünümü (nedensellik iddiası olmadan) | C | ⬜ |

### Şimdilik **yapılmayacaklar** (bilinçli karar)

- Öğrenci sıralama tablosu / rekabetçi liderlik tablosu — vizyonla çelişiyor.
- Rozet sistemi — MVP'de değer üretmez, bakım maliyeti yaratır.
- Mobil uygulama — web app mobile-first yeter, QR akışı zaten tarayıcıda.
- Sistem tarafından otomatik psikolojik/akademik yorum üretimi — yalnızca veri gösterilir.
- Dinamik (saniyede değişen) QR — §8'deki analize göre maliyeti faydasından yüksek.

---

## 5. Özellik kartları

Aşağıdaki kartlar, uygulanmadan önce doldurulması gereken standart formatta.
Yeni fikirler bu formatta eklenmeli.

---

### FEATURE 1 — Çalışma Oturumu (QR check-in / check-out)

- **Problem:** Öğrencinin kafeye ne zaman geldiği, ne kadar kaldığı ve gerçekten
  çalışıp çalışmadığı ölçülemiyor. Veli ve koç için elde hiç veri yok.
- **Çözüm:** Masadaki QR okutulunca tek butonla başlayan/biten çalışma oturumu.
- **Nasıl çalışır:**
  1. Öğrenci `/masa/{qr}` adresini okutur.
  2. Oturum açıksa doğrudan "Masa 12 — Hoş geldin Anıl · [Çalışmaya Başla]".
  3. Basınca `study_sessions` kaydı açılır (`started_at`, `table_id`, `student_id`).
  4. Sayfa canlı süre sayar; sekme kapansa da sunucudaki kayıt devam eder.
  5. Çıkışta aynı QR veya panelden tek buton: `ended_at` yazılır, süre hesaplanır.
- **Kim kullanır:** Öğrenci (günde 2 dokunuş), yönetici (canlı ekran), koç/veli (rapor).
- **MVP'de gerekli mi:** **Evet.** Ürünün çekirdeği.
- **Teknik karmaşıklık:** Orta. Asıl zorluk uç durumlar: çift başlatma,
  unutulan çıkış, gece yarısını aşan oturum, masa değişimi.
- **Öncelik:** P0
- **Bağlı olduğu özellikler:** Masa entity'si (FEATURE 2).

**Uç durum kuralları (uygulamada birebir uygulanacak):**

| Durum | Davranış |
|---|---|
| Zaten açık oturumu varken başka masada QR okutursa | Eski oturum kapatılır, yeni masada yeni oturum açılır, `switched` işaretlenir |
| Çıkışı unutursa | Kafe kapanış saatinde otomatik kapanır, `auto_closed = true`, süre kapanış saatine kadar sayılır |
| Oturum 12 saati aşarsa | Otomatik kapatılır + yöneticiye anomali olarak düşer |
| Gece yarısını aşan oturum | Raporlarda güne bölünerek yazılır (gün toplamları tutarlı kalsın diye) |
| 2 dakikadan kısa oturum | Kaydedilir ama "yanlış okutma" olarak istatistiklere dahil edilmez |

---

### FEATURE 2 — Masa Yönetimi

- **Problem:** Sistemde masa kavramı yok; QR'lar raf/dolap için üretiliyor.
- **Çözüm:** Ayrı `tables` tablosu; her masanın kendi QR'ı, durumu ve isteğe bağlı
  sabit öğrenci ataması olur.
- **Nasıl çalışır:** Yönetici masa oluşturur → QR üretilir → yazdırılır →
  isterse bir öğrenciye rezerve eder. Masa değişikliği tek tıkla yapılır.
- **Kim kullanır:** Yönetici, staff; dolaylı olarak öğrenci.
- **MVP'de gerekli mi:** Evet.
- **Teknik karmaşıklık:** Düşük. Mevcut `QRCodeService` ve QR yazdırma ekranı
  birebir yeniden kullanılabilir.
- **Öncelik:** P0
- **Bağlı olduğu özellikler:** —

---

### FEATURE 3 — Haftalık Çalışma Hedefi

- **Problem:** Öğrenci kafeye geliyor ama "yeterince çalıştım mı?" sorusunun
  ölçülebilir bir cevabı yok.
- **Çözüm:** Öğrenci başına haftalık saat hedefi + ilerleme göstergesi.
- **Nasıl çalışır:** Koç/yönetici hedefi belirler (varsayılan: 20 saat/hafta).
  Dashboard'da `14s 20dk / 20s` ve ilerleme çubuğu. Hafta pazartesi başlar.
- **Kim kullanır:** Öğrenci (görür), koç (belirler), veli (raporda görür).
- **MVP'de gerekli mi:** Evet — oturum verisi tek başına anlam taşımıyor.
- **Teknik karmaşıklık:** Düşük.
- **Öncelik:** P0
- **Bağlı olduğu özellikler:** FEATURE 1.

---

### FEATURE 4 — Veli Paneli

- **Problem:** Veli çocuğunun gerçekten çalışıp çalışmadığını göremiyor;
  bu, paketin satın alınma sebeplerinden biri.
- **Çözüm:** Veliye bağlı, **salt okunur** panel.
- **Nasıl çalışır:** Veli hesabı bir veya birden fazla öğrenciye bağlanır.
  Görebildikleri: geliş/çıkış saatleri, günlük/haftalık/aylık süre, devamlılık,
  hedef ilerlemesi. V1'den itibaren: deneme sonuçları, görevler, koç değerlendirmesi.
- **Kim kullanır:** Veli.
- **MVP'de gerekli mi:** Evet (temel hâliyle).
- **Teknik karmaşıklık:** Düşük–orta. Asıl iş **yetki sınırı**: veli yalnızca
  kendi çocuğunu, yalnızca paylaşıma açık alanları görmeli.
- **Öncelik:** P0
- **Bağlı olduğu özellikler:** FEATURE 1, rol genişletmesi.

**Gizlilik kuralı:** Koç notları varsayılan olarak **özeldir**. Bir not ancak
koç açıkça "veliyle paylaş" işaretlerse veli panelinde görünür.

---

### FEATURE 5 — Paket Sistemi

- **Problem:** Paketler koda gömülü değil, hiç yok. `subscription_status`
  yalnızca aktif/pasif tutuyor; fiyat, kapsam, süre bilgisi yok.
- **Çözüm:** Admin panelinden tanımlanabilen esnek paketler.
- **Nasıl çalışır:** Paket = ad + aylık fiyat + kapsam kuralları
  (rezerve masa var/yok, kapsamdaki ürünler ve limitleri, koçluk dahil mi,
  haftalık deneme dahil mi, kullanım saat aralığı). Öğrenciye paket atanır;
  başlangıç/bitiş tarihi, ödeme durumu takip edilir.
- **Kim kullanır:** Yönetici (tanımlar), öğrenci/veli (görür).
- **MVP'de gerekli mi:** Evet — ilk paket zaten satışta (7.500 TL/ay,
  rezerve masa + sınırsız çay).
- **Teknik karmaşıklık:** Orta. Kapsam kurallarını fazla soyutlamamak önemli:
  başlangıçta "şu üründen ayda/günde şu kadarı ücretsiz" yeter.
- **Öncelik:** P0
- **Bağlı olduğu özellikler:** Mevcut `Consumption` / `MonthlyBill` akışı —
  faturada paket kapsamındaki ürünler 0 ₺ olarak görünmeli.

---

### FEATURE 6 — Deneme Takibi

- **Problem:** Çalışmanın sonuca dönüp dönmediği ölçülemiyor.
- **Çözüm:** Ders bazlı doğru/yanlış/net girişi ve zaman içindeki gelişim.
- **Nasıl çalışır:** Deneme kaydı (tür: TYT/AYT, tarih, ad) + her ders için
  D/Y/boş. Net otomatik hesaplanır. Grafikte ders bazlı seri gösterilir.
- **Kim kullanır:** Koç veya öğrenci girer; öğrenci/veli/koç görür.
- **MVP'de gerekli mi:** Hayır — V1.
- **Teknik karmaşıklık:** Orta. Ders/alan tanımlarının veriden gelmesi gerekir
  (TYT ve AYT ders listeleri farklı), koda gömülmemeli.
- **Öncelik:** P1
- **Bağlı olduğu özellikler:** —

---

### FEATURE 6b — Deneme Sınavı Takvimi ve Hatırlatıcı (eklendi: 20 Eylül 2026)

- **Problem:** Denemenin ne zaman olduğu WhatsApp'ta kayboluyor; öğrenci ve
  veli "bu hafta deneme var mıydı" sorusunu kafeye soruyor.
- **Çözüm:** Kafe geneli deneme takvimi + panelde "sıradaki deneme, N gün kaldı".
- **Nasıl çalışır:** Yönetici ad, tür (TYT/AYT/TYT+AYT/LGS/Diğer), tarih, saat
  ve not girer. Öğrenci ve veli aynı aylık takvimi görür (`/kullanici/denemeler`,
  `/veli/denemeler`); panellerin en üstünde sıradaki deneme hatırlatıcısı çıkar,
  7 gün ve altı kaldıysa uyarı rengine döner (`kafe.deneme_hatirlatma_gun`).
- **Kim kullanır:** Yönetici (planlar), öğrenci ve veli (görür).
- **Sınır:** Sonuç tutmaz — FEATURE 6'nın `mock_exams` tablosundan ayrı
  (`exam_events`). E-posta/WhatsApp gönderimi yok; hatırlatıcı panel içi.
- **Durum:** ✅ Yapıldı.

---

### FEATURE 7 — Görev Sistemi

- **Problem:** Koçluk süreci WhatsApp'ta/kâğıtta kalıyor, takibi yok.
- **Çözüm:** Koç görev atar, öğrenci tamamladıkça işaretler.
- **Nasıl çalışır:** Görev = başlık + ders + hedef tarih + durum.
  Öğrenci panelinde haftalık görev listesi; tamamlama oranı koçta görünür.
- **Kim kullanır:** Koç (atar), öğrenci (tamamlar), veli (görür).
- **MVP'de gerekli mi:** Hayır — V1.
- **Teknik karmaşıklık:** Düşük.
- **Öncelik:** P1
- **Bağlı olduğu özellikler:** Koç rolü.

---

### FEATURE 8 — Otomatik Haftalık Veli Raporu

- **Problem:** Veliyle iletişim manuel ve düzensiz; velinin en çok istediği şey
  düzenli, karşılaştırmalı özet.
- **Çözüm:** Her pazar oluşan, önceki haftayla karşılaştırmalı otomatik rapor.
- **Nasıl çalışır:** Geliş gün sayısı, toplam süre, geçen haftaya göre % değişim,
  çözülen deneme sayısı, net değişimi, koç değerlendirmesi (varsa).
  Önce panelde; sonra PDF/e-posta/WhatsApp.
- **Kim kullanır:** Veli okur, koç yorum ekler.
- **MVP'de gerekli mi:** Hayır — V1 (panelde), V2 (gönderim).
- **Teknik karmaşıklık:** Orta. Zamanlanmış iş gerekir; Vercel serverless'ta
  cron tetiklemesi ayrıca planlanmalı.
- **Öncelik:** P1
- **Bağlı olduğu özellikler:** FEATURE 1, 4, 6.

---

### FEATURE 9 — Yardım / Çağrı Butonu

- **Problem:** Öğrenci masadan kalkıp görevli aramak zorunda; çalışma bölünüyor.
- **Çözüm:** Panelde "Yardım istiyorum" → sebep seç → yöneticide anlık bildirim.
- **Nasıl çalışır:** Sebepler: görevli çağır, öğretmene soru, temizlik,
  sipariş, teknik sorun. Talep masa ve öğrenciyle birlikte düşer, staff kapatır.
- **Kim kullanır:** Öğrenci, staff.
- **MVP'de gerekli mi:** Hayır — V1.
- **Teknik karmaşıklık:** Düşük (polling ile), orta (gerçek zamanlı ile).
  MVP sonrası 10 saniyelik polling yeterli; websocket gereksiz.
- **Öncelik:** P1
- **Bağlı olduğu özellikler:** FEATURE 2.

---

## 6. Veri modeli — eklenecekler

Mevcut tablolar korunuyor (`users`, `locations`, `products`, `product_locations`,
`consumptions`, `stock_records`, `stock_photos`, `monthly_bills`,
`discrepancy_logs`). Aşağıdakiler **eklenecek**:

```
tables                    -- masalar (locations'tan ayrı!)
  id, name, qr_code (unique), status, assigned_student_id (null),
  location_id (null), is_active, timestamps

study_sessions            -- çalışma oturumları  ← sistemin kalbi
  id, student_id, table_id, started_at, ended_at (null),
  duration_minutes (null), auto_closed (bool), source (qr|manual|admin),
  note, timestamps
  index: (student_id, started_at), (ended_at) -- açık oturum sorgusu için

session_subjects          -- oturumun ders kırılımı (V1)
  id, study_session_id, subject_id, minutes

subjects                  -- ders/alan tanımları (veri, kod değil)
  id, name, exam_type (tyt|ayt|genel), is_active, sort_order

study_goals               -- haftalık/aylık hedefler
  id, student_id, period (weekly|monthly), target_minutes,
  effective_from, effective_to (null), created_by

packages                  -- üyelik paketleri
  id, name, monthly_price, has_reserved_table, includes_coaching,
  weekly_mock_exams, usage_window (null), description, is_active

package_items             -- pakete dahil ürünler ve limitleri
  id, package_id, product_id, included_quantity (null = sınırsız),
  period (daily|weekly|monthly)

subscriptions             -- öğrencinin paket geçmişi
  id, student_id, package_id, starts_on, ends_on, price,
  payment_status (paid|pending|overdue|cancelled), note

payments                  -- ödeme kayıtları
  id, subscription_id, amount, paid_at, method, note, recorded_by

student_parent            -- veli–öğrenci bağı (çoka çok)
  id, parent_id, student_id, relation, can_view_exams (bool)

coach_assignments         -- koç–öğrenci bağı
  id, coach_id, student_id, assigned_at, is_active

coach_notes               -- koç notları
  id, coach_id, student_id, body, visibility (private|parent|student_and_parent),
  created_at

tasks                     -- görevler
  id, student_id, assigned_by, subject_id (null), title, description,
  due_date, status (open|done|cancelled), completed_at

exam_events               -- deneme takvimi (kafe geneli, sonuç yok) ✅
  id, title, exam_type (tyt|ayt|tyt_ayt|lgs|other), exam_date, starts_at, note, created_by

mock_exams                -- denemeler
  id, student_id, exam_type (tyt|ayt), name, taken_on, entered_by

mock_exam_results         -- deneme ders sonuçları
  id, mock_exam_id, subject_id, correct, wrong, blank, net (computed)

weak_topics               -- zayıf konular
  id, student_id, subject_id, topic, source (exam|coach), status, created_at

help_requests             -- yardım talepleri
  id, student_id, table_id, type, status (open|handled), handled_by, handled_at

feedback                  -- geri bildirim
  id, student_id (null = anonim), category, body, created_at

weekly_reports            -- üretilmiş haftalık raporlar (önbellek + geçmiş)
  id, student_id, week_start, payload (json), coach_comment, generated_at
```

**Mevcut tablolarda değişiklik:**

| Tablo | Değişiklik | Sebep |
|---|---|---|
| `users` | `role` enum'una `coach`, `teacher`, `parent`, `staff` eklenecek | Rol genişletmesi |
| `consumptions` | `table_id` (null), `covered_by_package` (bool) | Adisyonu masaya bağlamak, paket kapsamını faturaya yansıtmak |
| `monthly_bills` | `package_amount`, `extras_amount` ayrımı | Paket ücreti ile ekstra tüketim ayrı okunmalı |

**Migration notları:**
- Postgres/Supabase hedefte olduğu için yeni migration'larda `enum` yerine
  `string` + `check` tercih edilmeli (mevcut kodda `enum` var ama Postgres'te
  enum değiştirmek maliyetli — `users.role` genişletmesi bu yüzden dikkatli
  yazılmalı, bkz. §11 açık soru).
- `study_sessions` en çok yazılan ve en çok sorgulanan tablo olacak;
  açık oturum sorgusu (`ended_at IS NULL`) için kısmi indeks düşünülmeli.
- Süre hesabı **sunucuda** yapılmalı; istemci saatine asla güvenilmemeli.
- Tüm zaman damgaları UTC saklanır, `Europe/Istanbul` ile gösterilir.
  Haftalık/aylık toplamlar **yerel** güne göre hesaplanmalı.

---

## 7. Roller ve yetki matrisi

| | Admin | Coach | Teacher | Student | Parent | Staff |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Kendi oturumunu başlat/bitir | — | — | — | ✅ | — | — |
| Tüm oturumları gör | ✅ | kendi öğrencileri | — | kendi | kendi çocuğu | canlı ekran |
| Masa yönetimi | ✅ | — | — | — | — | ✅ |
| Ürün/stok/sayım | ✅ | — | — | — | — | ✅ |
| Paket ve ödeme | ✅ | — | — | kendi (salt) | kendi çocuğu (salt) | — |
| Görev atama | ✅ | ✅ | — | — | — | — |
| Deneme girişi | ✅ | ✅ | — | kendi | — | — |
| Koç notu (özel) | ✅ | ✅ | — | — | — | — |
| Öğrenci sorularını cevaplama | ✅ | — | ✅ | — | — | — |
| Yardım taleplerini kapatma | ✅ | — | — | — | — | ✅ |

**Kural:** Yetkilendirme controller içinde `if` ile değil, Laravel Policy
sınıflarıyla yapılmalı — rol sayısı 2'den 6'ya çıkıyor, dağınık kontroller
kısa sürede güvenlik açığına döner. Mevcut `AdminMiddleware` korunur ama
tek başına yeterli olmaz.

---

## 8. QR giriş/çıkış güvenliği — analiz ve öneri

**Tehdit:** Öğrenci masanın QR fotoğrafını evinde okutarak sahte çalışma süresi
üretir. Veli raporu yanlış olur → sistemin tüm güveni gider.

Seçenekleri gerçekçi biçimde değerlendirdim:

| Yöntem | Etkisi | Maliyeti | Karar |
|---|---|---|---|
| **Kafe Wi-Fi / IP doğrulaması** | Yüksek | Çok düşük | ✅ **Birincil çözüm** |
| Sunucu tarafı süre mantığı (istemci saatine güvenmemek) | Yüksek | Yok | ✅ Zorunlu |
| Anomali tespiti (imkânsız desenlerin işaretlenmesi) | Orta-yüksek | Düşük | ✅ MVP'de |
| Yönetici onayı / canlı ekranla çapraz kontrol | Yüksek | Yok (zaten var) | ✅ MVP'de |
| Geolocation (tarayıcı konumu) | Orta | Orta (izin ekranı, UX sürtünmesi, iç mekânda sapma) | ⚠️ Yedek |
| Dinamik QR (ekranda dönen kod) | Yüksek | Yüksek (her masaya ekran/tablet gerekir) | ❌ Hayır |
| NFC etiket | Yüksek | Orta (donanım + iOS kısıtları) | 🔮 İleride |

### Önerilen çözüm (katmanlı, UX'i bozmadan)

1. **Wi-Fi/IP kapısı (ana savunma).** Kafenin sabit IP'si (veya çıkış IP aralığı)
   ayar olarak tutulur. Oturum başlatma isteği bu IP'den gelmiyorsa buton
   "Kafedeki ağa bağlanman gerekiyor" der. Öğrenci zaten kafenin Wi-Fi'sine
   bağlanıyor — **ek bir adım yok**. Sabit IP yoksa: kafede tek bir router
   arkasından çıkan istekler için paylaşılan bir ağ anahtarı (`X-Cafe-Key`)
   captive portal ile verilir.
2. **Tek oturum kuralı.** Bir öğrencinin aynı anda tek açık oturumu olur.
3. **Sunucu saati.** `started_at` / `ended_at` yalnızca sunucuda yazılır.
4. **Anomali işaretleme.** Şu desenler yöneticiye düşer, oturum silinmez ama
   "şüpheli" işaretlenir:
   - Kafe kapalıyken açılan oturum
   - 12 saati aşan oturum
   - Aynı masada aynı anda iki öğrenci
   - Aynı öğrencinin kısa sürede farklı masalarda oturum açması
   - Bilinen kafe IP'si dışından gelen istek (kapı devre dışıysa)
5. **Yönetici müdahalesi.** Yönetici herhangi bir oturumu düzeltebilir/iptal
   edebilir; her düzeltme iz bırakır.

**Bilinçli olarak yapılmayan:** QR'ı kullanıcıya doğrulatan ek adımlar
(kod yazdırma, selfie, sürekli konum izni). Sistem bir güvenlik sistemi değil,
bir çalışma takip sistemi. Kötüye kullanımı **imkânsız** kılmak yerine
**zahmetli ve görünür** kılmak yeterli.

---

## 9. Kullanıcı yolculukları (özet)

**Öğrenci (günlük, hedef: 2 dokunuş)**
Kafeye gelir → masadaki QR → "Çalışmaya Başla" → çalışır →
(isteğe bağlı: ürün alır, yardım ister, görev işaretler) → "Çalışmayı Bitir" →
özet ekranı: bugün 2s 13dk, haftalık hedefin %72'si.

**Veli (haftada 1–2)**
Giriş → çocuğunun kartı: bu hafta 5/6 gün, 18s 24dk, geçen haftaya göre +%30 →
isterse günlük detay, deneme grafiği, koç değerlendirmesi.

**Koç (haftada 1, öğrenci başına)**
Panel → öğrenci listesi (haftalık süre, hedef %, devamlılık, son deneme) →
düşüş gösteren öğrenciye tıklar → geçmiş + denemeler + zayıf konular →
görev atar, not bırakır (özel ya da veliyle paylaşımlı).

**Öğretmen (nöbet saatlerinde)**
Panel → bekleyen sorular → fotoğraflı soruyu görür → cevaplar.

**Yönetici (gün boyu, tek ekran)**
Canlı ekran: içeride 14, boş masa 11, kim hangi masada ne kadardır →
yardım talepleri → bugünkü tüketim → stok uyarıları → ödeme durumu.

**Staff (operasyon)**
Yardım talepleri, sipariş/adisyon, stok sayımı, masa durumu.

---

## 10. Ölçülecek metrikler

**Ürün sağlığı**
- Aktif abone sayısı ve aylık kayıp (churn)
- Oturum başlatma oranı: *kafeye gelen öğrencilerin QR okutma yüzdesi*
  (bu düşükse UX bozuk demektir — en kritik metrik)
- Otomatik kapanan oturum oranı (çıkışı unutma) — hedef: < %15
- Öğrenci başına haftalık ortalama çalışma süresi
- Haftalık hedefi tutturan öğrenci yüzdesi

**Akademik**
- Öğrenci başına net değişimi (aylık)
- Çalışma süresi ile net değişimi arasındaki ilişki
  *(yalnızca birlikte gösterilir — sistem "bu yüzden" demez)*

**İşletme**
- Masa doluluk oranı (saat dilimine göre)
- Öğrenci başına aylık ekstra tüketim cirosu
- Gecikmiş ödeme sayısı
- Stok tutarsızlığı (adet ve tutar olarak)

**Koçluk**
- Görev tamamlama oranı
- Koç notu / görüşme sıklığı
- Devamlılığı düşen öğrencilerin kaçına müdahale edildiği

---

## 11. Açık sorular / karar bekleyenler

| # | Soru | Neden önemli | Durum |
|---|---|---|---|
| 1 | 2. ve 3. paketin içeriği ve fiyatı ne olacak? | Paket veri modeli buna göre esnetilir | ⬜ Açık |
| 2 | Kafenin sabit IP'si var mı? | QR güvenlik çözümünün birincil katmanı buna bağlı | ⬜ Açık |
| 3 | Kafe çalışma saatleri (otomatik kapanış için)? | Unutulan oturumların kapanma saati | ⬜ Açık |
| 4 | Deneme sonuçlarını kim girer — öğrenci mi koç mu? | Yetki ve UX akışı değişir | ⬜ Açık |
| 5 | Veli deneme sonuçlarını doğrudan görsün mü? | Gizlilik varsayılanı | ⬜ Açık |
| 6 | Koçluk pakete dahil mi, ayrı ücretli mi? | `packages.includes_coaching` alanının anlamı | ⬜ Açık |
| 7 | Mevcut `users.role` enum'u Postgres'te nasıl genişletilecek? | Migration stratejisi (enum → string + check) | ⬜ Açık |
| 8 | Haftalık rapor gönderimi hangi kanaldan? (e-posta / WhatsApp) | V2 kapsamı ve maliyeti | ⬜ Açık |
| 9 | Vercel serverless'ta zamanlanmış işler nasıl çalışacak? | Otomatik kapanış + haftalık rapor buna bağlı | ⬜ Açık |
| 10 | `Location` (raf) ile `Table` (masa) fiziksel olarak örtüşüyor mu? | Örtüşüyorsa tek QR iki işi görebilir | ⬜ Açık |

---

## 12. Güncelleme günlüğü

Tamamlanan her iş buraya bir satır olarak eklenir.

| Tarih | Güncelleme | Kapsam | Not |
|---|---|---|---|
| 2026-09-19 | Bu plan dosyası oluşturuldu | Doküman | Kod tabanı analiz edildi, mevcut durum §1'de çıkarıldı |

---

## Ek: ürün felsefesi hatırlatması

Her yeni özellik için sorulacak soru: **"Bu özellik hangi probleme çözüm oluyor?"**

Ürünün çekirdeği üç halka: **DEVAM → ÇALIŞMA → SONUÇ.**
Bu zincire bağlanmayan hiçbir özellik MVP'ye girmez.

Öğrenci için basitlik, veli için şeffaflık, koç için görünürlük,
işletme için ölçülebilirlik. Sıralama ve rekabet değil; düzen, devamlılık ve gelişim.
