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
}
