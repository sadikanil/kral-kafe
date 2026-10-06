<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Telefon bildirimi gonderimi (6 Ekim 2026): Web Push, paketsiz.
 *
 * Standartlar: RFC 8291 (icerik sifreleme, aes128gcm) ve RFC 8292 (VAPID:
 * sunucunun kendini tanitmasi). Yalnizca openssl kullanir; GMP/bcmath
 * isteyen kutuphaneler Vercel'in PHP calisma ortaminda garanti degil.
 *
 * VAPID anahtari ortam degiskeninden (WEB_PUSH_PUBLIC_KEY +
 * WEB_PUSH_PRIVATE_KEY) ya da yoksa ilk kullanimda uretilip ayarlar
 * tablosuna yazilir: kurulum adimi gerekmez. Anahtar degisirse eski
 * abonelikler gecersiz kalir (saglayici 403 doner), tarayici yeniden
 * abone olur.
 */
class WebPush
{
    public const SETTING = 'web_push_vapid';

    /** P-256 acik anahtari icin SubjectPublicKeyInfo (DER) on eki. */
    private const SPKI_ONEKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Saglayici bildirimi en fazla bu kadar saniye tutar (telefon kapaliysa). */
    private const TTL = 86400;

    /** @var array{public:string,private:string}|null */
    private ?array $anahtarlar = null;

    /** Tarayiciya verilen acik anahtar (base64url, 65 bayt). */
    public function publicKey(): string
    {
        return $this->anahtarlar()['public'];
    }

    /**
     * Bir cihaza gonderir. Saglayici aboneligi tanimiyorsa (404/410) satir
     * silinir. Ag hatasi bildirimi kaybettirir ama hicbir seyi bozmaz: zil
     * yine gosterir.
     *
     * @param  list<PushSubscription>  $abonelikler
     * @param  array<string,mixed>  $icerik  title, body, url, tag
     * @return int Basariyla gonderilen sayisi
     */
    public function send(array $abonelikler, array $icerik): int
    {
        if ($abonelikler === []) {
            return 0;
        }

        $govde = json_encode($icerik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $istekler = [];
        foreach ($abonelikler as $i => $abone) {
            try {
                $istekler[$i] = [
                    'url' => $abone->endpoint,
                    'body' => $this->encrypt($govde, $abone->p256dh, $abone->auth),
                    'headers' => [
                        'Authorization' => $this->vapidHeader($abone->endpoint),
                        'Content-Encoding' => 'aes128gcm',
                        'Content-Type' => 'application/octet-stream',
                        'TTL' => (string) self::TTL,
                        'Urgency' => 'normal',
                    ],
                ];
            } catch (\Throwable $e) {
                // Bozuk anahtarli abonelik: tarayici yeniden abone olur.
                Log::warning('Telefon bildirimi sifrelenemedi; abonelik siliniyor', ['error' => $e->getMessage()]);
                $abone->delete();
            }
        }

        // Ayni anda: cron'un bir turunda onlarca bildirim cikabiliyor.
        $yanitlar = Http::pool(fn (Pool $havuz) => array_map(
            fn ($i) => $havuz->as((string) $i)->timeout(5)->connectTimeout(3)
                ->withHeaders($istekler[$i]['headers'])
                ->withBody($istekler[$i]['body'], 'application/octet-stream')
                ->post($istekler[$i]['url']),
            array_keys($istekler),
        ));

        $basarili = 0;
        foreach (array_keys($istekler) as $i) {
            $yanit = $yanitlar[(string) $i] ?? null;
            $abone = $abonelikler[$i];

            if ($yanit instanceof Response && $yanit->successful()) {
                $abone->forceFill(['last_sent_at' => now()])->save();
                $basarili++;
            } elseif ($yanit instanceof Response && in_array($yanit->status(), [403, 404, 410], true)) {
                // 404/410: abonelik kalkti. 403: baska VAPID anahtariyla alinmis.
                $abone->delete();
            } else {
                Log::warning('Telefon bildirimi gonderilemedi', [
                    'status' => $yanit instanceof Response ? $yanit->status() : null,
                    'error' => $yanit instanceof \Throwable ? $yanit->getMessage() : null,
                ]);
            }
        }

        return $basarili;
    }

    /**
     * RFC 8291: icerigi aboneligin anahtariyla sifreler (tek kayit).
     *
     * @param  string  $p256dh  aboneligin acik anahtari (base64url, 65 bayt)
     * @param  string  $auth  aboneligin dogrulama sirri (base64url, 16 bayt)
     * @param  \OpenSSLAsymmetricKey|null  $gecici  yalnizca test (RFC 8291 Ek A ornegi)
     */
    public function encrypt(string $icerik, string $p256dh, string $auth, ?\OpenSSLAsymmetricKey $gecici = null, ?string $tuz = null): string
    {
        $aliciAcik = self::b64d($p256dh);
        $sir = self::b64d($auth);
        if (strlen($aliciAcik) !== 65 || $aliciAcik[0] !== "\x04" || strlen($sir) !== 16) {
            throw new RuntimeException('Abonelik anahtari gecersiz.');
        }

        $gecici ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $geciciAcik = self::rawPublic($gecici);
        $ortak = openssl_pkey_derive(self::publicFromRaw($aliciAcik), $gecici, 32);
        if ($ortak === false) {
            throw new RuntimeException('Anahtar anlasmasi yapilamadi.');
        }

        $ikm = hash_hkdf('sha256', $ortak, 32, "WebPush: info\x00" . $aliciAcik . $geciciAcik, $sir);
        $tuz ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $tuz);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $tuz);

        // Tek ve son kayit: icerik + 0x02 ayiraci.
        $sifreli = openssl_encrypt($icerik . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiket);

        return $tuz . pack('N', 4096) . chr(65) . $geciciAcik . $sifreli . $etiket;
    }

    /** RFC 8292: "vapid t=<JWT>, k=<acik anahtar>". */
    public function vapidHeader(string $endpoint): string
    {
        $parca = parse_url($endpoint);
        $kitle = ($parca['scheme'] ?? 'https') . '://' . ($parca['host'] ?? '') . (isset($parca['port']) ? ':' . $parca['port'] : '');

        $baslik = self::b64e(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $iddia = self::b64e(json_encode([
            'aud' => $kitle,
            'exp' => now()->addHours(12)->getTimestamp(),
            'sub' => $this->iletisim(),
        ], JSON_UNESCAPED_SLASHES));

        $imzalanan = "{$baslik}.{$iddia}";
        if (! openssl_sign($imzalanan, $der, openssl_pkey_get_private($this->anahtarlar()['private']), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID imzalanamadi.');
        }

        return 'vapid t=' . $imzalanan . '.' . self::b64e(self::derToRaw($der)) . ', k=' . $this->publicKey();
    }

    /**
     * VAPID "sub": saglayicinin sorun olursa ulasacagi adres. E-posta yerine
     * sitenin kendisi (RFC 8292 https adresine izin verir); Apple https
     * olmayani reddediyor. Vercel'de istek https gelse de vekil arkasinda
     * http gorunebilir: sema her durumda https yazilir.
     */
    private function iletisim(): string
    {
        $host = parse_url(url('/'), PHP_URL_HOST) ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return 'https://' . $host;
    }

    /** @return array{public:string,private:string} */
    private function anahtarlar(): array
    {
        if ($this->anahtarlar !== null) {
            return $this->anahtarlar;
        }

        $acik = (string) config('kafe.web_push.public_key');
        $gizli = (string) config('kafe.web_push.private_key');
        if ($acik !== '' && $gizli !== '') {
            return $this->anahtarlar = ['public' => $acik, 'private' => str_replace('\n', "\n", $gizli)];
        }

        $kayitli = Setting::where('key', self::SETTING)->value('value');
        if (is_array($kayitli) && isset($kayitli['public'], $kayitli['private'])) {
            return $this->anahtarlar = $kayitli;
        }

        $anahtar = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($anahtar, $pem);
        $yeni = ['public' => self::b64e(self::rawPublic($anahtar)), 'private' => $pem];

        try {
            Setting::create(['key' => self::SETTING, 'value' => $yeni]);
        } catch (UniqueConstraintViolationException) {
            // Ayni anda baska istek uretti: onunki gecerli.
            $yeni = Setting::where('key', self::SETTING)->value('value');
        }

        return $this->anahtarlar = $yeni;
    }

    /** Ozel anahtarin acik yarisi, sikistirilmamis (0x04 || X || Y). */
    public static function rawPublic(\OpenSSLAsymmetricKey $anahtar): string
    {
        $ec = openssl_pkey_get_details($anahtar)['ec'];

        return "\x04" . str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);
    }

    public static function publicFromRaw(string $ham): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin(self::SPKI_ONEKI) . $ham), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $anahtar = openssl_pkey_get_public($pem);
        if ($anahtar === false) {
            throw new RuntimeException('Acik anahtar okunamadi.');
        }

        return $anahtar;
    }

    /** openssl'in DER ECDSA imzasi -> JWT'nin istedigi 64 bayt (r || s). */
    public static function derToRaw(string $der): string
    {
        $konum = 2; // SEQUENCE etiketi + uzunluk (P-256'da hep tek bayt)
        $parcalar = [];
        for ($i = 0; $i < 2; $i++) {
            $uzunluk = ord($der[$konum + 1]);
            $sayi = ltrim(substr($der, $konum + 2, $uzunluk), "\x00");
            $parcalar[] = str_pad($sayi, 32, "\x00", STR_PAD_LEFT);
            $konum += 2 + $uzunluk;
        }

        return $parcalar[0] . $parcalar[1];
    }

    public static function b64e(string $veri): string
    {
        return rtrim(strtr(base64_encode($veri), '+/', '-_'), '=');
    }

    public static function b64d(string $veri): string
    {
        return (string) base64_decode(strtr($veri, '-_', '+/'), true);
    }
}
