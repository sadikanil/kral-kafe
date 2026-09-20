# Kral Kafe — Yol Haritası (takip dosyası)

_Son güncelleme: 21 Eylül 2026. Kararların gerekçesi `YKS-CALISMASI.md`,
uygulama ayrıntısı `UYGULAMA-PLANI.md`, oturum devri `DEVIR.md`._

Durum işaretleri: ✅ canlıda · 🔄 sırada/üzerinde çalışılıyor · ⬜ planlı · ⏸ rafta

## 1. Bitenler

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
| 6c | Self adisyon: panelden ürün ekleme (QR'siz), 60 sn geri alma | ✅ |
| 6d | Deneme sonuç PDF'i: yönetici yükler, yapay zeka başarılı/zayıf alanları çıkarır, öğrenci görür | ✅ |
| 7 | Paket kataloğu + kapsam kalemleri, öğrenciye paket atama (fiyat kopyalanır), ödeme kayıtları, vade/gecikmiş takibi, faturada paket tutarı | ✅ |

## 2. Kabul edilen sıra

İki hat paralel yürüyebilir; tabloları kesişmiyor.

### İşletme hattı

| Sıra | Dalga | İçerik | Büyüklük | Durum |
|---|---|---|---|---|
| 1 | 7 | Paket tanımı, öğrenciye paket atama (`subscriptions.price`), ödeme kayıtları, ödendi/bekliyor/gecikmiş | L | ✅ |
| 2 | 8 | Tüketimi masaya ve pakete bağlama (`covered_by_package`, `package_items` kapsamı); `Consumption::boot` fiyat ezmesi düzelir | M | ⬜ |

### Koçluk hattı (YKS değeri)

| Sıra | Dalga | İçerik | Büyüklük | Bağımlılık | Durum |
|---|---|---|---|---|---|
| 1 | 9 | Sınava geri sayım (resmî sınav türü) + haftalık veli raporu (tembel üretim, koç yorumu alanı) | M | 6, 6b | 🔄 sırada |
| 2 | 10 | Koç rolü aktif: koç–öğrenci atama, koç paneli, notlar (özel / veli / öğrenci+veli), görüşme kaydı | L | 6 | ⬜ |
| 3 | 11 | `subjects` + yapılandırılmış deneme sonucu girişi (öğrenci girer, koç doğrular) + net grafiği + `can_view_exams` | L | 6b, 6d, 10 | ⬜ |
| 4 | 12 | Görevler + haftalık tamamlama oranı | M | 10 | ⬜ |
| 5 | 13 | Devamlılık düşüş sinyalleri (önce koça) | S | 10 | ⬜ |
| 6 | 14 | Oturuma ders etiketi + zayıf konu listesi (6d'nin analizinden beslenir) | M | 11 | ⬜ |
| 7 | 15 | E-posta gönderimi (haftalık rapor, deneme hatırlatması) + Vercel Cron | M | 9 | ⬜ |

Önerilen akış: **7 → 9 → 10 → 11 → 12 → 13 → 8 → 14 → 15.**

## 3. Verilen kararlar (kabul edildi)

| # | Karar |
|---|---|
| 1 | Deneme sonucunu öğrenci girer, koç doğrular. PDF raporu (6d) yönetici yükler. |
| 2 | Veli netleri ve PDF analizini varsayılan **görmez**; öğrenci başına `can_view_exams` bayrağı (Dalga 11). |
| 3 | Koç ayrı rol; yönetici de koç olabilir. |
| 4 | Haftalık rapor pazar 23:59 kafe saatinde kapanır. |
| 5 | İlk bildirim kanalı e-posta; WhatsApp sonra. |
| 6 | YKS tarihi takvime "resmî sınav" olarak girilir (Dalga 9). |

## 4. Gizlilik kuralları (her dalgada geçerli)

1. Veli salt okunur; veli rotalarında yazma yöntemi yok (test var).
2. Koç notu varsayılan özel.
3. Deneme sonuçları/analizleri veliye varsayılan kapalı.
4. Öğrenci veliye ne gittiğini kendi panelinde görür.
5. Sıralama/karşılaştırma yok.
6. Düşüş sinyalleri önce koça.
7. Yorumu koç yapar; sistem ve yapay zeka sayı ve alan gösterir, sıfat üretmez (6d prompt'unda açık yasak).

## 5. Rafta

| Konu | Neden |
|---|---|
| IP kapısı | Kafe IP'si dinamik |
| Anomali "gördüm" işareti | Yedi günlük pencere şimdilik yetiyor |
| Öğrencinin günlük notu | Yük ekler; isteğe bağlı, koçluk hattı bitince |
| WhatsApp bildirimi | Resmî API ücretli; e-postadan sonra |

## 6. Her dalgada kontrol listesi

- Migration + `PostgresSecurity::lockDown()` + factory + test.
- `php artisan test` yeşil → ayrı adımda commit → `main`'e push (Vercel).
- Canlı `migrations` tablosu ile `database/migrations/` karşılaştırması (`DEVIR.md` §1).
- Yeni form → `lang/tr/validation.php` attributes.
- Yeni ortam değişkeni → `DEPLOY.md` §2.
