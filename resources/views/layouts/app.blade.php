<!DOCTYPE html>
<html lang="tr">

{{--
    Tek kabuk (Dalga 21, Faz 3'te yenilendi). Herkes burada; menu
    App\Support\Navigation'dan gelir, rol ve paket haklarina gore suzulur.

    Apple duzeni: yari saydam ust cubuk, icerikte buyuk baslik (kaydirinca
    cubukta kucugu belirir), telefonda alt sekme cubugu ve alttan acilan menu
    sayfasi, genis ekranda kenar cubugu. Sayfalarin verdigi bolumler:

      page-title    buyuk baslik (ve sekme basligi degilse cubuktaki kucuk hali)
      page-actions  basligin yanindaki islemler (Yeni, Yazdir, CSV...)
      back          cubugun solunda geri baglantisi: <a href class="topbar-back">
      content       sayfanin kendisi
--}}
@php
    $kullanici = auth()->user();
    $gruplar = \App\Support\Navigation::groups($kullanici);
    $aktif = fn (array $oge) => request()->routeIs(...explode('|', $oge['match'] ?? $oge['route']));

    // Bildirim zili (Dalga 27). Her sayfada calistigi icin tek sorgu: son 6
    // bildirim, okunmamis sayisi alt sorgu olarak her satirda. Hic satir
    // yoksa okunmamis da yoktur.
    $sonBildirimler = \App\Models\Notification::for($kullanici)
        ->addSelect(['okunmamis_sayisi' => \App\Models\Notification::selectRaw('count(*)')
            ->where('user_id', $kullanici->id)->whereNull('read_at')])
        ->latest()->limit(6)->get();
    $okunmamis = (int) ($sonBildirimler->first()?->okunmamis_sayisi ?? 0);
@endphp

<head>
    <meta charset="UTF-8">
    {{-- viewport-fit=cover: centikli telefonda cubuklar kenara kadar uzanir,
         icerik env(safe-area-inset-*) kadar iceride kalir (app.css). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kral Kafe')</title>

    {{-- Yazi tipi sistemden (app.css --font-sans); Google Fonts ilk boyamayi
         ucuncu taraf baglantisi kadar bekletiyordu. --}}
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">
    {{-- Cift gonderim kilidi, menu, zil, hata ozeti, onay, balon (bkz. dosya basi). --}}
    <script src="{{ \App\Support\Asset::url('js/kabuk.js') }}" defer></script>

    @stack('styles')
</head>

<body>
    <a href="#icerik" class="skip-link">İçeriğe geç</a>

    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="{{ route($kullanici->homeRoute()) }}" class="sidebar-logo">
                    @include('layouts._marka')
                </a>
                <button type="button" class="btn btn-icon btn-plain sidebar-close js-menu-kapat" aria-label="Menüyü kapat">
                    <x-icon name="x" />
                </button>
            </div>

            {{-- Simgeler ekran okuyucudan gizli, ad metinde. Etkin sayfa yalnizca
                 renkle degil aria-current ile. Her grup basligiyla adlandirilir. --}}
            <nav class="sidebar-nav" aria-label="Ana menü">
                @foreach($gruplar as $sira => $grup)
                    <div class="sidebar-group" role="group" aria-labelledby="menu-grup-{{ $sira }}">
                        <div class="sidebar-nav-section">
                            <span class="sidebar-nav-section-title" id="menu-grup-{{ $sira }}">{{ $grup['title'] }}</span>
                        </div>

                        <div class="sidebar-group-items">
                            @foreach($grup['items'] as $oge)
                                @php($etkin = $aktif($oge))
                                <a href="{{ route($oge['route']) }}" class="sidebar-nav-link{{ $etkin ? ' active' : '' }}"@if($etkin) aria-current="page"@endif>
                                    <span class="sidebar-nav-link-icon" aria-hidden="true"><x-icon :name="$oge['icon']" /></span>
                                    <span>{{ $oge['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr($kullanici->name, 0, 2)) }}
                    </div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">{{ $kullanici->name }}</div>
                        <div class="sidebar-user-role">
                            {{-- Abonelik yalnizca ogrenciyi ilgilendirir; koc/veli icin
                                 "Pasif" yazmak yanlis bir uyari olurdu. --}}
                            @if ($kullanici->needsActiveSubscription())
                                @if ($kullanici->hasActiveSubscription())
                                    <span class="text-success">Aktif üye</span>
                                @else
                                    <span class="text-danger">Pasif</span>
                                @endif
                            @else
                                <span>{{ $kullanici->role()?->label() }}</span>
                            @endif
                        </div>
                    </div>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-icon btn-plain" aria-label="Çıkış yap" title="Çıkış yap"><x-icon name="log-out" /></button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Karartma dugme: dokununca menu kapanir. Tab sirasinda yok; klavyede
             Escape ve Menü dugmesi ayni isi yapiyor. --}}
        <button type="button" class="sidebar-overlay js-menu-kapat" id="sidebarOverlay" aria-label="Menüyü kapat" tabindex="-1"></button>

        {{-- "Icerige gec" hedefi; menu acikken betik burayi inert yapar. --}}
        <main class="main-content has-bottom-nav" id="icerik" tabindex="-1">
            <header class="topbar">
                @yield('back')

                {{-- Marka ve kucuk baslik ayni yerde; kaydirinca yer degistirir.
                     Kucuk baslik tekrar oldugu icin ekran okuyucudan gizli. --}}
                <div class="topbar-lead">
                    @unless(View::hasSection('back'))
                        <a href="{{ route($kullanici->homeRoute()) }}" class="topbar-brand">
                            @include('layouts._marka', ['sinif' => 'topbar-logo'])
                        </a>
                    @endunless
                    <span class="topbar-title" aria-hidden="true">@yield('page-title', 'Panel')</span>
                </div>

                <div class="topbar-actions">
                    {{-- Zil: son bildirimler altinda listelenir; "Tumu" okundu sayar.
                         Ad metinde ("Bildirimler, 2 okunmamış"); simge ve rozet
                         ekran okuyucudan gizli, yoksa "zil 2" okunuyordu. --}}
                    <details class="notif-bell">
                        <summary class="btn btn-icon btn-plain" title="Bildirimler" aria-label="Bildirimler{{ $okunmamis > 0 ? ', ' . $okunmamis . ' okunmamış' : '' }}">
                            <x-icon name="bell" />@if($okunmamis > 0)<span aria-hidden="true" class="notif-count">{{ $okunmamis }}</span>@endif
                        </summary>
                        <div class="notif-panel">
                            @forelse($sonBildirimler as $bildirim)
                                <div class="notif-item {{ $bildirim->read_at ? '' : 'is-unread' }}">
                                    <strong>{{ $bildirim->title }}</strong>
                                    @if($bildirim->body)<div class="text-muted">{{ $bildirim->body }}</div>@endif
                                    <div class="notif-time">
                                        {{ $bildirim->created_at->timezone(config('kafe.timezone'))->format('d.m H:i') }}
                                    </div>
                                </div>
                            @empty
                                <div class="notif-item text-muted">Bildirim yok.</div>
                            @endforelse
                            <a href="{{ route('notifications.index') }}" class="notif-all">Tümünü gör</a>
                        </div>
                    </details>
                </div>
            </header>

            {{-- Yonlendirmeden sonra gelen mesajlar yuzen balon (iOS). Basari
                 role=status ve 5 sn sonra kaybolur (betik acilista yeniden
                 duyurur); hata role=alert, kapatilana kadar kalir ve acilista
                 odak alir (data-odakla). --}}
            @if(session('success') || session('error'))
                <div class="toast-stack">
                    @if(session('success'))
                        <div class="toast toast-success" role="status">
                            <x-icon name="circle-check" />
                            <span class="toast-message">{{ session('success') }}</span>
                            <button type="button" class="btn btn-icon btn-plain toast-close js-balon-kapat" aria-label="Kapat"><x-icon name="x" /></button>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="toast toast-error" role="alert" tabindex="-1" data-odakla>
                            <x-icon name="circle-x" />
                            <span class="toast-message">{{ session('error') }}</span>
                            <button type="button" class="btn btn-icon btn-plain toast-close js-balon-kapat" aria-label="Kapat"><x-icon name="x" /></button>
                        </div>
                    @endif
                </div>
            @endif

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title">@yield('page-title', 'Panel')</h1>
                    @hasSection('page-actions')
                        <div class="page-actions">@yield('page-actions')</div>
                    @endif
                </div>

                {{-- data-hata-alani: hatanin anahtari. kabuk.js alan sayfadaysa satiri
                     ona goturen baglantiya cevirir ve alani aria-invalid yapar. --}}
                @if($errors->any())
                    <div class="alert alert-danger animate-slide-up" role="alert" tabindex="-1" data-odakla>
                        <div>
                            <strong>Hata!</strong>
                            <ul class="mb-0 mt-1">
                                @foreach($errors->getMessages() as $alan => $mesajlar)
                                    @foreach($mesajlar as $mesaj)
                                        <li data-hata-alani="{{ $alan }}">{{ $mesaj }}</li>
                                    @endforeach
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    @include('layouts._bottom-nav', ['tabs' => \App\Support\Navigation::quick($kullanici), 'aktif' => $aktif])

    {{-- Onay sayfasi (kabuk.js, data-confirm): confirm() yerine alttan acilan
         eylem sayfasi; genis ekranda ortada kucuk kutu. Vazgec ilk odakta:
         yanlislikla Enter yikici islemi yapmasin. --}}
    <dialog class="sheet" id="onay" aria-labelledby="onayMetni">
        <form method="dialog">
            <div class="sheet-group">
                <p class="sheet-message js-onay-metni" id="onayMetni"></p>
                <button type="submit" value="evet" class="sheet-action is-destructive js-onay-evet">Onayla</button>
            </div>
            <button type="submit" value="" class="sheet-action sheet-cancel" autofocus>Vazgeç</button>
        </form>
    </dialog>

    @stack('scripts')
</body>

</html>
