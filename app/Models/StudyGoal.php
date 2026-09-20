<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bir ogrencinin bir donemdeki calisma hedefi.
 *
 * Hedef DEGISTIRILMEZ, yenisiyle degistirilir: eski satirin effective_to'su
 * kapatilir ve yeni bir satir acilir. Uzerine yazmak gecmisi sessizce yeniden
 * yazardi - gecen hafta 10 saat hedefi tutturan ogrenci, hedef 25 saate
 * cikinca "tutturamamis" gorunurdu.
 */
class StudyGoal extends Model
{
    protected $fillable = [
        'student_id',
        'period',
        'target_minutes',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'target_minutes' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Verilen tarihte yururlukte olan hedef.
     */
    public static function activeFor(User $student, string $date, string $period = 'weekly'): ?self
    {
        return static::where('student_id', $student->id)
            ->where('period', $period)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Bu hedefi verilen tarihten itibaren yururlukten kaldirir.
     *
     * effective_to bir GUN ONCESINE yazilir: yeni hedef o tarihte basliyorsa
     * ayni gun iki hedef yururlukte olamaz.
     */
    public function supersedeOn(string $date): void
    {
        $this->update([
            'effective_to' => Carbon::parse($date)->subDay()->toDateString(),
        ]);
    }
}
