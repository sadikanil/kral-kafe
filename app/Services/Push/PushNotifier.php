<?php

namespace App\Services\Push;

use App\Models\Notification;
use App\Models\PushSubscription;

use function Illuminate\Support\defer;

/**
 * Zil bildirimini telefona da yollar (6 Ekim 2026).
 *
 * Her yeni Notification kaydi buraya duser (Notification::booted). Gonderim
 * istegin sonunda TOPLU yapilir: cron'un bir turu onlarca bildirim
 * uretebiliyor; hepsi tek havuzda, ayni anda gider. Gonderim hatasi kaydi
 * ya da istegi bozmaz - zil her zaman asil kaynak.
 */
class PushNotifier
{
    /** @var list<Notification> */
    private array $bekleyen = [];

    public function __construct(private readonly WebPush $push)
    {
    }

    public function queue(Notification $bildirim): void
    {
        $this->bekleyen[] = $bildirim;

        if (count($this->bekleyen) === 1) {
            defer(fn () => $this->flush(), 'web-push', always: true);
        }
    }

    /** @return int Gonderilen cihaz sayisi */
    public function flush(): int
    {
        $liste = $this->bekleyen;
        $this->bekleyen = [];

        if ($liste === []) {
            return 0;
        }

        try {
            $cihazlar = PushSubscription::whereIn('user_id', array_unique(array_map(fn ($b) => $b->user_id, $liste)))
                ->get()->groupBy('user_id');
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }

        $gonderilen = 0;
        foreach ($liste as $bildirim) {
            $aboneler = $cihazlar->get($bildirim->user_id);
            if ($aboneler === null || $aboneler->isEmpty()) {
                continue;
            }

            try {
                $gonderilen += $this->push->send($aboneler->values()->all(), $this->icerik($bildirim));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $gonderilen;
    }

    /** @return array{title:string,body:string,url:string,tag:string} */
    private function icerik(Notification $bildirim): array
    {
        $bildirim->loadMissing(['user', 'student']);
        $adres = $bildirim->user ? $bildirim->url($bildirim->user) : null;

        return [
            'title' => $bildirim->title,
            'body' => (string) $bildirim->body,
            'url' => $adres ?? route('notifications.index'),
            'tag' => 'bildirim-' . $bildirim->id,
        ];
    }
}
