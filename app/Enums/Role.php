<?php

namespace App\Enums;

/**
 * Sistemdeki roller. Tek dogruluk kaynagi burasi.
 *
 * Veritabaninda 'role' sutunu duz string olarak tutulur ve DB seviyesinde
 * CHECK kisiti YOKTUR. Sebep calistirarak dogrulandi:
 *
 *   - Postgres'te enum()->change() "alter column type ... check (...)" uretiyor
 *     ve SQLSTATE[42601] ile migration'i cokertiyor.
 *   - string()->change() komutu gecirir ama eski kisit yerinde kalir; sonra
 *     yeni bir rol yazmak SQLSTATE[23514] verir.
 *   - SQLite'ta ->change() tabloyu bastan yazarken KOMSU subscription_status
 *     sutununun kisitini da dusurur.
 *
 * Kisiti korumak, her rol eklemesinde iki surucu icin ayri elle SQL yazmayi VE
 * komsu sutunun kisitini elle geri kurmayi zorunlu kilardi. Dogrulama bunun
 * yerine burada ve Rule::enum() ile yapilir.
 */
enum Role: string
{
    case Admin = 'admin';
    case Coach = 'coach';
    case Teacher = 'teacher';
    case Student = 'student';
    case Parent = 'parent';
    case Staff = 'staff';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Arayuzde gosterilecek Turkce ad.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Yönetici',
            self::Coach => 'Koç',
            self::Teacher => 'Öğretmen',
            self::Student => 'Öğrenci',
            self::Parent => 'Veli',
            self::Staff => 'Görevli',
        };
    }

    /**
     * Abonelik yalnizca ogrenciyi ilgilendirir.
     *
     * Koc/ogretmen/veli/gorevli icin abonelik kavrami yok; subscription_status
     * alanina guvenmek, birinin durumu degistigi an personeli kendi panelinden
     * kilitler.
     */
    public function needsActiveSubscription(): bool
    {
        return $this === self::Student;
    }

    /**
     * Giristen sonra bu rolun indigi sayfa. TEK dogruluk kaynagi.
     *
     * Ayni karar daha once routes/web.php ile AuthenticatedSessionController
     * icinde iki kez yazilmisti; ayrisirlarsa kullanici girise basinca bir yere,
     * ana sayfaya girince baska yere gider.
     *
     * Koc/ogretmen/gorevli icin henuz ayri panel YOK - AdminMiddleware
     * yalnizca yoneticiyi geciriyor, dolayisiyla onlari yonetim paneline
     * yollamak dogrudan 403 demek olurdu. Panelleri geldigi dalgada bu match
     * tek satirla genisler. Veli paneli Dalga 6'da geldi.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.dashboard',
            self::Parent => 'parent.dashboard',
            default => 'user.dashboard',
        };
    }

    /**
     * Listelerde rozet rengi. Roller dorde gruplanir: yetkili, egitmen,
     * ogrenci, veli. CSS yalnizca bes rozet sinifi tanimliyor.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Admin, self::Staff => 'primary',
            self::Coach, self::Teacher => 'success',
            self::Student => 'info',
            self::Parent => 'warning',
        };
    }
}
