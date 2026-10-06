<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\Push\PushNotifier;
use App\Services\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telefon bildirimi (6 Ekim 2026): Web Push, paketsiz.
 *
 * Sifreleme RFC 8291 Ek A'daki ornekle birebir dogrulanir; sonra her
 * gonderim "telefon" tarafinda (aboneligin ozel anahtariyla) cozulur.
 * Ag yok: saglayici Http::fake.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private const UC = 'https://fcm.googleapis.com/fcm/send/abc123';

    /** RFC 8291 Ek A. */
    public function test_encryption_matches_the_rfc_example(): void
    {
        $gecici = $this->ozelAnahtar('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
            'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');

        $sifreli = app(WebPush::class)->encrypt(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            $gecici,
            WebPush::b64d('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::b64e($sifreli));
    }

    public function test_the_vapid_header_is_a_valid_es256_token_for_the_push_service(): void
    {
        $push = app(WebPush::class);
        $baslik = $push->vapidHeader(self::UC);

        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $baslik);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $baslik, $p);

        $this->assertSame($push->publicKey(), $p[4]);
        $iddia = json_decode(WebPush::b64d($p[2]), true);
        $this->assertSame('https://fcm.googleapis.com', $iddia['aud']);
        $this->assertGreaterThan(time(), $iddia['exp']);
        $this->assertSame('https://localhost', $iddia['sub']);

        // Imza: 64 bayt r||s, acik anahtarla dogrulanir.
        $ham = WebPush::b64d($p[3]);
        $this->assertSame(64, strlen($ham));
        $this->assertSame(1, openssl_verify("{$p[1]}.{$p[2]}", $this->derImza($ham),
            WebPush::publicFromRaw(WebPush::b64d($p[4])), OPENSSL_ALGO_SHA256));
    }

    public function test_the_key_is_created_once_and_kept(): void
    {
        $ilk = app(WebPush::class)->publicKey();
        $this->assertSame(65, strlen(WebPush::b64d($ilk)));
        $this->assertSame($ilk, Setting::where('key', WebPush::SETTING)->sole()->value['public']);

        // Yeni istek (yeni nesne) ayni anahtari okur.
        $this->assertSame($ilk, (new WebPush)->publicKey());
    }

    public function test_a_device_subscribes_and_unsubscribes(): void
    {
        $veli = User::factory()->parent()->create();
        [, $abonelik] = $this->cihaz();

        $this->actingAs($veli)->postJson(route('push.store'), $abonelik)->assertOk();
        $kayit = PushSubscription::sole();
        $this->assertSame([$veli->id, self::UC], [$kayit->user_id, $kayit->endpoint]);

        // Ayni telefon baska hesapla: abonelik yeni hesaba gecer, iki kisiye gitmez.
        $ogrenci = User::factory()->student()->create();
        $this->actingAs($ogrenci)->postJson(route('push.store'), $abonelik)->assertOk();
        $this->assertSame($ogrenci->id, PushSubscription::sole()->user_id);

        // Baskasinin aboneligi silinemez.
        $this->actingAs($veli)->deleteJson(route('push.destroy'), ['endpoint' => self::UC])->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->actingAs($ogrenci)->deleteJson(route('push.destroy'), ['endpoint' => self::UC])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_only_https_push_services_and_signed_in_users(): void
    {
        [, $abonelik] = $this->cihaz();

        $this->postJson(route('push.store'), $abonelik)->assertUnauthorized();
        $this->actingAs(User::factory()->parent()->create())
            ->postJson(route('push.store'), ['endpoint' => 'http://ic-ag/bildir'] + $abonelik)
            ->assertJsonValidationErrors('endpoint');
    }

    public function test_a_new_notification_reaches_the_phone_encrypted_with_its_link(): void
    {
        Http::fake([self::UC => Http::response('', 201)]);
        $yonetici = User::factory()->admin()->create();
        [$telefon, $abonelik] = $this->cihaz();
        $this->actingAs($yonetici)->postJson(route('push.store'), $abonelik)->assertOk();

        $bildirim = Notification::create([
            'type' => NotificationType::ExamImport->value, 'user_id' => $yonetici->id, 'related_id' => 7,
            'unique_key' => 'deneme', 'title' => 'Deneme okundu: Hız ve Renk TYT 2', 'body' => '3 öğrenci okundu.',
        ]);
        $this->assertSame(1, app(PushNotifier::class)->flush());

        Http::assertSentCount(1);
        $istek = Http::recorded()[0][0];
        $this->assertSame(self::UC, $istek->url());
        $this->assertSame('aes128gcm', $istek->header('Content-Encoding')[0]);
        $this->assertStringStartsWith('vapid t=', $istek->header('Authorization')[0]);

        $icerik = json_decode($this->coz($istek->body(), $telefon, $abonelik), true);
        $this->assertSame([
            'title' => 'Deneme okundu: Hız ve Renk TYT 2',
            'body' => '3 öğrenci okundu.',
            'url' => route('admin.exam-imports.show', 7),
            'tag' => 'bildirim-' . $bildirim->id,
        ], $icerik);
        $this->assertNotNull(PushSubscription::sole()->last_sent_at);
    }

    public function test_notifications_made_during_a_request_are_sent_after_it(): void
    {
        Http::fake([self::UC => Http::response('', 201)]);
        $yonetici = User::factory()->admin()->create();
        [, $abonelik] = $this->cihaz();
        $this->actingAs($yonetici)->postJson(route('push.store'), $abonelik)->assertOk();

        // Cron'un bir turu: bildirim istegin icinde uretilir, gonderim istekten sonra.
        $this->travelTo(now()->setTimezone(config('kafe.timezone'))->setTime(23, 0));
        config(['kafe.cron_anahtari' => 'gizli']);
        $this->withHeader('Authorization', 'Bearer gizli')->getJson('/zamanlanmis/gunluk')->assertOk();

        $this->assertGreaterThan(0, Notification::where('user_id', $yonetici->id)->count());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::UC);
    }

    public function test_a_gone_subscription_is_removed_and_others_are_untouched(): void
    {
        Http::fake([self::UC => Http::response('', 410)]);
        $veli = User::factory()->parent()->create();
        [, $abonelik] = $this->cihaz();
        $this->actingAs($veli)->postJson(route('push.store'), $abonelik)->assertOk();

        Notification::create(['type' => NotificationType::Absence->value, 'user_id' => $veli->id,
            'unique_key' => 'yok', 'title' => 'Elif bugün kafeye gelmedi']);
        $this->assertSame(0, app(PushNotifier::class)->flush());

        $this->assertSame(0, PushSubscription::count());
        $this->assertSame(1, Notification::count(), 'zil kaydi kalir');
    }

    public function test_without_a_device_nothing_is_sent(): void
    {
        Http::fake();
        $veli = User::factory()->parent()->create();

        Notification::create(['type' => NotificationType::Absence->value, 'user_id' => $veli->id,
            'unique_key' => 'yok', 'title' => 'Elif bugün kafeye gelmedi']);
        app(PushNotifier::class)->flush();

        Http::assertNothingSent();
    }

    public function test_the_notifications_page_offers_phone_notifications(): void
    {
        $veli = User::factory()->parent()->create();

        $this->actingAs($veli)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Telefon bildirimleri')
            ->assertSee('data-anahtar="' . app(WebPush::class)->publicKey() . '"', false)
            ->assertSee(route('push.store'), false);
    }

    // --- Yardimcilar -------------------------------------------------------

    /** @return array{0:\OpenSSLAsymmetricKey,1:array} telefonun ozel anahtari ve tarayicinin abonelik JSON'u */
    private function cihaz(): array
    {
        $anahtar = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        return [$anahtar, ['endpoint' => self::UC, 'keys' => [
            'p256dh' => WebPush::b64e(WebPush::rawPublic($anahtar)),
            'auth' => WebPush::b64e(random_bytes(16)),
        ]]];
    }

    /** Telefon tarafi: RFC 8291 cozumu. */
    private function coz(string $govde, \OpenSSLAsymmetricKey $telefon, array $abonelik): string
    {
        $tuz = substr($govde, 0, 16);
        $this->assertSame(4096, unpack('N', substr($govde, 16, 4))[1]);
        $this->assertSame(65, ord($govde[20]));
        $geciciAcik = substr($govde, 21, 65);
        $sifreli = substr($govde, 86, -16);
        $etiket = substr($govde, -16);

        $ortak = openssl_pkey_derive(WebPush::publicFromRaw($geciciAcik), $telefon, 32);
        $ikm = hash_hkdf('sha256', $ortak, 32, "WebPush: info\x00" . WebPush::rawPublic($telefon) . $geciciAcik,
            WebPush::b64d($abonelik['keys']['auth']));
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $tuz);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $tuz);

        $acik = openssl_decrypt($sifreli, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiket);
        $this->assertIsString($acik);
        $this->assertSame("\x02", substr($acik, -1));

        return substr($acik, 0, -1);
    }

    /** Ham ozel (d) ve acik anahtardan openssl anahtari (SEC1 DER). */
    private function ozelAnahtar(string $d, string $acik): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420') . WebPush::b64d($d)
            . hex2bin('a00a06082a8648ce3d030107a144034200') . WebPush::b64d($acik);

        return openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
    }

    /** 64 bayt r||s -> DER (openssl_verify icin). */
    private function derImza(string $ham): string
    {
        $tam = function (string $x): string {
            $x = ltrim($x, "\x00");
            if ($x === '' || ord($x[0]) & 0x80) {
                $x = "\x00" . $x;
            }

            return "\x02" . chr(strlen($x)) . $x;
        };
        $govde = $tam(substr($ham, 0, 32)) . $tam(substr($ham, 32));

        return "\x30" . chr(strlen($govde)) . $govde;
    }
}
