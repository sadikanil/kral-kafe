<!DOCTYPE html>
<html lang="tr">

{{--
    Tek kabuk (Dalga 21). Herkes burada; menu App\Support\Navigation'dan
    gelir, rol ve paket haklarina gore suzulur. Ic ice/acilir menu yok.
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

    // Surum icerikten: dosya degisince adres de degisir. vercel.json ?v=
    // tasiyan istegi bir yil onbellekte tutar; dosya pakette yoksa parametre
    // eklenmez ve tarayici her seferinde sorar - bayat CSS riski yok.
    $surumlu = fn (string $yol) => asset($yol) . (is_file($dosya = public_path($yol)) ? '?v=' . substr(md5_file($dosya), 0, 12) : '');
@endphp

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kral Kafe')</title>

    {{-- Yazi tipi sistemden (app.css --font-sans); Google Fonts ilk boyamayi
         ucuncu taraf baglantisi kadar bekletiyordu. --}}
    <link rel="stylesheet" href="{{ $surumlu('css/app.css') }}">
    {{-- Cift gonderim kilidi, menu, zil ve hata ozeti (bkz. dosya basi). --}}
    <script src="{{ $surumlu('js/kabuk.js') }}" defer></script>

    @stack('styles')
</head>

<body>
    <a href="#icerik" class="skip-link">İçeriğe geç</a>

    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="{{ route($kullanici->homeRoute()) }}" class="sidebar-logo">
                    <span class="sidebar-logo-icon" aria-hidden="true">☕</span>
                    <span>Kral Kafe</span>
                </a>
            </div>

            {{-- Ekran okuyucu emojiyi okur ("ev", "kapi"); simgeler gizli, ad
                 metinde. Etkin sayfa yalnizca renkle degil aria-current ile. --}}
            <nav class="sidebar-nav" aria-label="Ana menü">
                @foreach($gruplar as $grup)
                    <div class="sidebar-nav-section">
                        <span class="sidebar-nav-section-title">{{ $grup['title'] }}</span>
                    </div>

                    @foreach($grup['items'] as $oge)
                        @php($etkin = $aktif($oge))
                        <a href="{{ route($oge['route']) }}" class="sidebar-nav-link{{ $etkin ? ' active' : '' }}"@if($etkin) aria-current="page"@endif>
                            <span class="sidebar-nav-link-icon" aria-hidden="true">{{ $oge['icon'] }}</span>
                            <span>{{ $oge['label'] }}</span>
                        </a>
                    @endforeach
                @endforeach
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar">
                        {{ strtoupper(mb_substr($kullanici->name, 0, 2)) }}
                    </div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">{{ $kullanici->name }}</div>
                        <div class="sidebar-user-role">
                            {{-- Abonelik yalnizca ogrenciyi ilgilendirir; koc/veli icin
                                 "Pasif" yazmak yanlis bir uyari olurdu. --}}
                            @if ($kullanici->needsActiveSubscription())
                                @if ($kullanici->hasActiveSubscription())
                                    <span class="text-success">Aktif Üye</span>
                                @else
                                    <span class="text-danger">Pasif</span>
                                @endif
                            @else
                                <span>{{ $kullanici->role()?->label() }}</span>
                            @endif
                        </div>
                    </div>
                    <form action="{{ route('logout') }}" method="POST" class="d-inline-block">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary" aria-label="Çıkış yap" title="Çıkış yap"><span aria-hidden="true">🚪</span></button>
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
                <div class="d-flex align-items-center gap-2">
                    <h1 class="topbar-title">@yield('page-title', 'Panel')</h1>
                </div>

                <div class="topbar-actions">
                    @yield('topbar-actions')

                    {{-- Zil: son bildirimler altinda listelenir; "Tumu" okundu sayar.
                         Ad metinde ("Bildirimler, 2 okunmamış"); emoji ve rozet
                         ekran okuyucudan gizli, yoksa "zil 2" okunuyordu. --}}
                    <details class="notif-bell">
                        <summary class="btn btn-icon btn-secondary" title="Bildirimler" aria-label="Bildirimler{{ $okunmamis > 0 ? ', ' . $okunmamis . ' okunmamış' : '' }}">
                            <span aria-hidden="true">🔔</span>@if($okunmamis > 0)<span aria-hidden="true" class="notif-count">{{ $okunmamis }}</span>@endif
                        </summary>
                        <div class="notif-panel">
                            @forelse($sonBildirimler as $bildirim)
                                <div class="notif-item {{ $bildirim->read_at ? '' : 'is-unread' }}">
                                    <strong>{{ $bildirim->title }}</strong>
                                    @if($bildirim->body)<div class="text-muted">{{ $bildirim->body }}</div>@endif
                                    <div class="text-muted" style="font-size: .75rem;">
                                        {{ $bildirim->created_at->timezone(config('kafe.timezone'))->format('d.m H:i') }}
                                    </div>
                                </div>
                            @empty
                                <div class="notif-item text-muted">Bildirim yok.</div>
                            @endforelse
                            <a href="{{ route('notifications.index') }}" class="notif-all">Tümü →</a>
                        </div>
                    </details>
                </div>
            </header>

            <div class="page-content">
                {{-- Yonlendirmeden sonra gelen mesajlar ekran okuyucuya duyurulur:
                     basari role=status (sirasini bekler), hata role=alert. Hata
                     kutulari acilista odak alir (data-odakla, kabuk.js); uzun
                     formda neyin yanlis gittigi boylece hemen okunur. --}}
                @if(session('success'))
                    <div class="alert alert-success animate-slide-up" role="status"><span aria-hidden="true">✅</span> {{ session('success') }}</div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger animate-slide-up" role="alert" tabindex="-1" data-odakla><span aria-hidden="true">❌</span> {{ session('error') }}</div>
                @endif

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

    @stack('scripts')
</body>

</html>
