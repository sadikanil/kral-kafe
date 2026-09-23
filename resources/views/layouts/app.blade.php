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

    // Bildirim zili (Dalga 27)
    $okunmamis = \App\Models\Notification::for($kullanici)->whereNull('read_at')->count();
    $sonBildirimler = \App\Models\Notification::for($kullanici)->latest()->limit(6)->get();
@endphp

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kral Kafe')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('css/app.css') }}">

    @stack('styles')
</head>

<body>
    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="{{ route($kullanici->homeRoute()) }}" class="sidebar-logo">
                    <span class="sidebar-logo-icon">☕</span>
                    <span>Kral Kafe</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                @foreach($gruplar as $grup)
                    <div class="sidebar-nav-section">
                        <span class="sidebar-nav-section-title">{{ $grup['title'] }}</span>
                    </div>

                    @foreach($grup['items'] as $oge)
                        <a href="{{ route($oge['route']) }}" class="sidebar-nav-link {{ $aktif($oge) ? 'active' : '' }}">
                            <span class="sidebar-nav-link-icon">{{ $oge['icon'] }}</span>
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
                        <button type="submit" class="btn btn-sm btn-secondary" title="Çıkış">🚪</button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

        <main class="main-content has-bottom-nav">
            <header class="topbar">
                <div class="d-flex align-items-center gap-2">
                    <h1 class="topbar-title">@yield('page-title', 'Panel')</h1>
                </div>

                <div class="topbar-actions">
                    @yield('topbar-actions')

                    {{-- Zil: son bildirimler altinda listelenir; "Tumu" okundu sayar. --}}
                    <details class="notif-bell">
                        <summary class="btn btn-icon btn-secondary" title="Bildirimler">
                            🔔@if($okunmamis > 0)<span class="notif-count">{{ $okunmamis }}</span>@endif
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
                @if(session('success'))
                    <div class="alert alert-success animate-slide-up">✅ {{ session('success') }}</div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger animate-slide-up">❌ {{ session('error') }}</div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger animate-slide-up">
                        <div>
                            <strong>Hata!</strong>
                            <ul class="mb-0 mt-1">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }
    </script>

    @include('layouts._bottom-nav', ['tabs' => \App\Support\Navigation::quick($kullanici), 'aktif' => $aktif])

    @stack('scripts')
</body>

</html>
