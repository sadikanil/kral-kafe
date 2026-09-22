<?php

namespace App\Enums;

/**
 * coach_notes.visibility - notu kim gorur (Dalga 14b).
 *
 * VARSAYILAN parent (karar 6, 22 Eylul): veli profile islenen her seyi gorur
 * (SS6.1-2). Varsayilanin kapali olmasi bu kuralin tersine calisirdi - koc
 * her seferinde isaretlemeyi unutur, veli hicbir sey gormezdi.
 *
 * Ozel secenegin kalmasi ise kocun HAM GOZLEMINI yazabilmesi icin: gozlem
 * notuyla veliye giden degerlendirme ayni sey degil, ve ozel secenegi
 * olmayan bir sistemde koc gozlemini hic yazmaz - ozellik kullanilmaz hale
 * gelir.
 */
enum NoteVisibility: string
{
    case Parent = 'parent';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Parent => 'Veliyle paylaşıldı',
            self::Private => 'Yalnız koç ve yönetici',
        };
    }

    /**
     * Veli (ve dolayisiyla ogrenci) bu notu gorur mu?
     *
     * Ogrenci ile veli AYNI kumeyi gorur: SS6.1-3 "veliye ne gittigini
     * ogrenci kendi panelinde gorur - gizli izleme yok". Iki ayri kural
     * yazmak, birinin gun gelip digerinden fazlasini gostermesi demekti.
     */
    public function isShared(): bool
    {
        return $this === self::Parent;
    }

    public function badgeClass(): string
    {
        return $this === self::Parent ? 'success' : 'warning';
    }
}
