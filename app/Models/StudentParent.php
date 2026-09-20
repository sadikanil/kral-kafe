<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * student_parent bag satiri.
 *
 * Ayri bir model olarak var olmasinin tek sebebi factory: her yeni tablo ile
 * birlikte factory yaziliyor (UYGULAMA-PLANI §4). Uygulama kodu bagi
 * User::students() / User::parents() uzerinden kurar, bu sinifi dogrudan
 * kullanmaz.
 */
class StudentParent extends Pivot
{
    use HasFactory;

    protected $table = 'student_parent';

    public $incrementing = true;

    protected $fillable = [
        'student_id',
        'parent_id',
        'created_by',
    ];
}
