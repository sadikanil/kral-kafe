<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Tek menu (Dalga 21).
 *
 * Herkes ayni kabukta; menu rol ve paket haklarina gore suzulur. Ic ice ya
 * da acilir menu YOK: baslikli gruplar ve duz linkler. Onceden her rolun
 * ayri kabugu vardi ve yonetici "Calisma Plani"na girince menu tamamen
 * degisiyordu.
 *
 * Oge: ['route', 'label', 'icon', 'match' (istege bagli, routeIs deseni)].
 * icon: public/img/simgeler.svg'deki simgenin adi (Faz 3; emoji degil).
 */
final class Navigation
{
    /** @return array<int,array{title:string,items:array<int,array<string,string>>}> */
    public static function groups(User $u): array
    {
        $gruplar = match ($u->role()) {
            Role::Admin => self::admin(),
            Role::Coach => [
                ['title' => 'Koçluk', 'items' => [self::planlar()]],
            ],
            Role::Parent => [
                ['title' => 'Veli', 'items' => [
                    ['route' => 'parent.dashboard', 'label' => 'Çocuklarım', 'icon' => 'house', 'match' => 'parent.dashboard|parent.student|parent.report|parent.payments'],
                    ['route' => 'parent.exams', 'label' => 'Deneme Takvimi', 'icon' => 'clipboard-list'],
                ]],
            ],
            Role::Student => self::student($u->entitlements()),
            default => [
                ['title' => 'Menü', 'items' => [
                    ['route' => 'user.dashboard', 'label' => 'Panel', 'icon' => 'house'],
                ]],
            ],
        };

        // Bos kalan grup (ornegin kulupsuz ogrencide "Denemeler" tek ogeli
        // olur, ama hic ogesi olmayan grup basligi yalniz kalmasin).
        return array_values(array_filter($gruplar, fn ($g) => $g['items'] !== []));
    }

    /**
     * Telefon alt bari: en sik 4 sayfa. 5. yer "Menu" dugmesi ve tum
     * gruplari acar - her sayfa en fazla iki dokunusta.
     *
     * @return array<int,array<string,string>>
     */
    public static function quick(User $u): array
    {
        $tercih = match ($u->role()) {
            Role::Admin => ['admin.dashboard', 'admin.live', 'admin.users.index', 'coach.plan.index'],
            Role::Student => ['user.dashboard', 'table.scanner', 'user.plan', 'user.tab'],
            default => [],
        };

        $hepsi = collect(self::groups($u))->flatMap(fn ($g) => $g['items'])->keyBy('route');

        if ($tercih === []) {
            return $hepsi->take(4)->values()->all();
        }

        return collect($tercih)->filter(fn ($r) => $hepsi->has($r))->map(fn ($r) => $hepsi[$r])->values()->all();
    }

    private static function planlar(): array
    {
        return ['route' => 'coach.plan.index', 'label' => 'Çalışma Planları', 'icon' => 'compass', 'match' => 'coach.*'];
    }

    private static function admin(): array
    {
        return [
            ['title' => 'Günlük', 'items' => [
                ['route' => 'admin.dashboard', 'label' => 'Panel', 'icon' => 'house'],
                ['route' => 'admin.live', 'label' => 'Canlı Ekran', 'icon' => 'activity'],
            ]],
            ['title' => 'Öğrenciler', 'items' => [
                ['route' => 'admin.users.index', 'label' => 'Kullanıcılar', 'icon' => 'users', 'match' => 'admin.users.*|admin.subscriptions.index|admin.exam-reports.*|admin.exam-results.*'],
                self::planlar(),
                ['route' => 'admin.exams.index', 'label' => 'Deneme Takvimi', 'icon' => 'clipboard-list', 'match' => 'admin.exams.*'],
            ]],
            ['title' => 'Kafe', 'items' => [
                ['route' => 'admin.tables.index', 'label' => 'Masalar', 'icon' => 'armchair', 'match' => 'admin.tables.*'],
                // Dalga 29: urun, stok ve sayim tek girişte; sayfalar sekmeli.
                ['route' => 'admin.products.index', 'label' => 'Ürünler ve Stok', 'icon' => 'coffee', 'match' => 'admin.products.*|admin.stock.*'],
                ['route' => 'admin.packages.index', 'label' => 'Paketler', 'icon' => 'ticket', 'match' => 'admin.packages.*'],
                ['route' => 'admin.subscriptions.overview', 'label' => 'Ödemeler', 'icon' => 'credit-card'],
                ['route' => 'admin.reports.index', 'label' => 'Raporlar', 'icon' => 'chart-column', 'match' => 'admin.reports.*'],
            ]],
            ['title' => 'Ayarlar', 'items' => [
                ['route' => 'admin.settings.edit', 'label' => 'Ayarlar', 'icon' => 'settings', 'match' => 'admin.settings.*'],
            ]],
        ];
    }

    private static function student(Entitlements $hak): array
    {
        return [
            ['title' => 'Günlük', 'items' => array_values(array_filter([
                ['route' => 'user.dashboard', 'label' => 'Panel', 'icon' => 'house'],
                $hak->table ? ['route' => 'table.scanner', 'label' => 'QR Okut', 'icon' => 'scan-line', 'match' => 'table.*'] : null,
                ['route' => 'user.tab', 'label' => 'Adisyon', 'icon' => 'receipt', 'match' => 'user.tab*'],
                ['route' => 'user.plan', 'label' => 'Planım', 'icon' => 'calendar-days'],
                ['route' => 'user.report', 'label' => 'Haftalık Raporum', 'icon' => 'chart-no-axes-column'],
            ]))],
            ['title' => 'Denemeler', 'items' => array_values(array_filter([
                ['route' => 'user.exams', 'label' => 'Deneme Takvimi', 'icon' => 'clipboard-list'],
                $hak->examClub ? ['route' => 'user.exam-results', 'label' => 'Deneme Sonuçlarım', 'icon' => 'target'] : null,
                $hak->examClub ? ['route' => 'user.exam-reports.index', 'label' => 'Deneme Raporlarım', 'icon' => 'file-text', 'match' => 'user.exam-reports.*'] : null,
            ]))],
            ['title' => 'Hesap', 'items' => [
                ['route' => 'user.payments', 'label' => 'Ödemeler', 'icon' => 'credit-card'],
            ]],
        ];
    }
}
