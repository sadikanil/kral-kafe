<?php

namespace App\Enums;

/**
 * Aylik adisyonun tahsilat durumu (DEV-507, 27 Ekim 2026).
 *
 * monthly_bills.status (pending/sent/exported) faturanin YASAM dongusu;
 * bu ise parasi alindi mi. Deger string sutunda, enum degil - Postgres'te
 * enum degistirmek migration'i cokertiyor (README SS10.2).
 */
enum BillPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    /** Odenen ve tahsil edilecek tutardan; ikisi de kurus duzeyinde. */
    public static function for(float $tutar, float $odenen): self
    {
        return match (true) {
            round($odenen, 2) <= 0 => self::Unpaid,
            round($odenen, 2) >= round($tutar, 2) => self::Paid,
            default => self::Partial,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Ödenmedi',
            self::Partial => 'Kısmen ödendi',
            self::Paid => 'Ödendi',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::Partial => 'info',
            self::Paid => 'success',
        };
    }
}
