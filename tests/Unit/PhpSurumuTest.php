<?php

namespace Tests\Unit;

use App\Support\PhpSurumu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** php2shell (CVE-2026-109618 / CVE-2026-109619) yamali surum kurali. */
class PhpSurumuTest extends TestCase
{
    public static function surumler(): array
    {
        return [
            'canlidaki eski runtime' => ['8.3.8', false],
            '8.3 yamadan once' => ['8.3.35', false],
            '8.3 yamali' => ['8.3.36', true],
            '8.4 yamadan once' => ['8.4.26', false],
            '8.4 yamali (vercel-php@0.8.0)' => ['8.4.27', true],
            '8.4 sonrasi' => ['8.4.30', true],
            '8.5 yamadan once' => ['8.5.11', false],
            '8.5 yamali (vercel-php@0.9.0)' => ['8.5.12', true],
            '8.2 yamali' => ['8.2.35', true],
            '8.2 yamadan once' => ['8.2.34', false],
            'destek disi dal' => ['8.1.31', false],
            'yamadan sonra acilan dal' => ['8.6.0', true],
        ];
    }

    #[DataProvider('surumler')]
    public function test_patched_versions_per_branch(string $surum, bool $yamali): void
    {
        $this->assertSame($yamali, PhpSurumu::yamaliMi($surum));
    }

    public function test_the_running_version_is_read_without_distro_suffixes(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', PhpSurumu::simdiki());
        $this->assertSame(PhpSurumu::yamaliMi(PhpSurumu::simdiki()), PhpSurumu::yamaliMi());
    }
}
