<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationBuilder;
use App\Support\LocalDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sinavdan bir gun onceki hatirlatmanin basligi (QA bug 5).
 *
 * Resmi sinav (YKS) bir deneme degil; ikisinin basligi ayri. Diger testler
 * yalnizca satirin olustugunu denetliyordu - butun hatirlatmalari tek
 * kaliba ceviren ya da on eki dusuren bir degisiklik yesil kaliyordu.
 */
class ExamReminderTitleTest extends TestCase
{
    use RefreshDatabase;

    private function yarinkiSinavinBasligi(string $tur, string $ad): string
    {
        $ogrenci = User::factory()->create(['role' => Role::Student->value]);
        ExamEvent::create(['title' => $ad, 'exam_type' => $tur, 'exam_date' => '2026-09-17']);

        $this->travelTo(Carbon::parse('2026-09-16 20:00', config('kafe.timezone')));
        app(NotificationBuilder::class)->examReminders(LocalDay::today());

        return Notification::where('user_id', $ogrenci->id)
            ->where('type', NotificationType::ExamTomorrow->value)
            ->sole()
            ->title;
    }

    public function test_a_practice_exam_reminder_says_there_is_a_practice_exam_tomorrow(): void
    {
        $this->assertSame('Yarın deneme var: TYT Deneme 4', $this->yarinkiSinavinBasligi('tyt', 'TYT Deneme 4'));
    }

    public function test_an_official_exam_reminder_says_tomorrow_is_exam_day(): void
    {
        $this->assertSame('Yarın sınav günü: YKS 2027', $this->yarinkiSinavinBasligi('official', 'YKS 2027'));
    }
}
