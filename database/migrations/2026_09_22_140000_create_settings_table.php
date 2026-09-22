<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yoneticinin panelden degistirebildigi ayarlar (Dalga 10b).
 *
 * Neden config/kafe.php yetmedi: kafe koordinati kafede OLCULEREK alinacak
 * ("konumu buradan al" butonu). Config'e yazmak her tasinmada deploy
 * gerektirir ve degerin dogrulugunu kimse goremez.
 *
 * Neden tek satirlik "cafe_settings" degil anahtar/deger: ileride kafe
 * saatleri, esikler ve bildirim tercihleri de buraya gelecek; her biri icin
 * migration yazmak yerine anahtar ekleniyor. Deger JSON, cunku koordinat gibi
 * bilesik degerler var.
 *
 * Degerler YONETICI tarafindan yazilir; kullanici girdisi degil. Yine de
 * okuyan taraf tipini dogruluyor (bkz. Setting::cafeLocation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        PostgresSecurity::lockDown('settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
