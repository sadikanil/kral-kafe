<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Nakit',
            self::Transfer => 'Havale / EFT',
            self::Card => 'Kart',
            self::Other => 'Diğer',
        };
    }
}
