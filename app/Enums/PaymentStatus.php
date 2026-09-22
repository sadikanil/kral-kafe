<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Ödendi',
            self::Pending => 'Bekliyor',
            self::Overdue => 'Gecikmiş',
            self::Cancelled => 'İptal',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'warning',
            self::Overdue => 'danger',
            self::Cancelled => 'info',
        };
    }
}
