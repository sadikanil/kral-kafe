<?php

namespace App\Support;

use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * Bir kullanicinin paketlerinden dogan haklar (Dalga 19).
 *
 * Paket bayraklarinin BIRLESIMI: ana paket + ekler (deneme kulubu eki
 * gibi). Tier numarasi hak vermez, yalnizca etikettir.
 */
final class Entitlements
{
    public function __construct(
        public readonly bool $table = false,
        public readonly bool $coaching = false,
        public readonly bool $examClub = false,
        public readonly bool $privateLessons = false,
    ) {}

    public static function all(): self
    {
        return new self(true, true, true, true);
    }

    /** @param Collection<int,Package> $paketler */
    public static function fromPackages(Collection $paketler): self
    {
        return new self(
            table: $paketler->contains('has_reserved_table', true),
            coaching: $paketler->contains('includes_coaching', true),
            examClub: $paketler->contains('includes_exam_club', true),
            privateLessons: $paketler->contains('includes_private_lessons', true),
        );
    }
}
