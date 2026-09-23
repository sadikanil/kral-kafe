<?php

namespace Tests\Unit;

use App\Support\Asset;
use Tests\TestCase;

/**
 * Surumlu varlik adresi (Faz 3). vercel.json ?v= tasiyan istegi bir yil
 * onbellekte tutar; surum dosyanin ozeti oldugu icin dosya degisince adres
 * de degisir. Simge sprite'i bir sayfada onlarca kez istendigi icin ozet
 * istek basina bir kez hesaplanir.
 */
class AssetTest extends TestCase
{
    public function test_an_existing_file_gets_its_content_version(): void
    {
        $this->assertSame(
            asset('css/app.css') . '?v=' . substr(md5_file(public_path('css/app.css')), 0, 12),
            Asset::url('css/app.css')
        );
    }

    /** Pakette olmayan dosyada parametre yok: tarayici her seferinde sorar. */
    public function test_a_missing_file_stays_unversioned(): void
    {
        $this->assertSame(asset('yok/boyle-bir-dosya.css'), Asset::url('yok/boyle-bir-dosya.css'));
    }
}
