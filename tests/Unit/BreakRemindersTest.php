<?php

namespace Tests\Unit;

use App\Support\BreakReminders;
use PHPUnit\Framework\TestCase;

/**
 * Dalga 24: esneme hatirlaticilari (karar, 23 Eyl: "30 dk mikro + 60 dk
 * kalk"). Kaynaklar README SS0'da. Sayac araliksiz calismaya bakar.
 */
class BreakRemindersTest extends TestCase
{
    private function tur(int $dakika): ?string
    {
        foreach (BreakReminders::schedule() as $h) {
            if ($h['at'] === $dakika * 60) {
                return $h['kind'];
            }
        }

        return null;
    }

    public function test_every_thirty_minutes_a_micro_break(): void
    {
        $this->assertSame('mikro', $this->tur(30));
        $this->assertSame('mikro', $this->tur(90));
    }

    public function test_every_hour_stand_up(): void
    {
        $this->assertSame('kalk', $this->tur(60));
        $this->assertSame('kalk', $this->tur(180));
    }

    public function test_every_two_hours_a_real_break_is_suggested(): void
    {
        $this->assertSame('mola', $this->tur(120));
        $this->assertSame('mola', $this->tur(240));
    }

    public function test_nothing_in_between(): void
    {
        $this->assertNull($this->tur(45));
    }

    public function test_every_reminder_has_a_message(): void
    {
        foreach (BreakReminders::schedule() as $h) {
            $this->assertNotEmpty($h['title']);
            $this->assertNotEmpty($h['text']);
        }
    }

    public function test_the_schedule_covers_a_long_day(): void
    {
        $this->assertGreaterThanOrEqual(8 * 3600, max(array_column(BreakReminders::schedule(), 'at')));
    }
}
