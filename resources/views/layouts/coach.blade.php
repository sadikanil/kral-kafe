<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kral Kafe')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">

    @stack('styles')
</head>

<body>
    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="{{ route('coach.plan.index') }}" class="sidebar-logo">
                    <span class="sidebar-logo-icon">☕</span>
                    <span>Kral Kafe</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-nav-section">
                    <span class="sidebar-nav-section-title">Koçluk</span>
                </div>

                <a href="{{ route('coach.plan.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('coach.plan.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">🗓️</span>
                    <span>Çalışma Planı</span>
                </a>

                {{-- Yonetici ayni zamanda koctur (karar 11); buraya kendi
                     panelinden gelir ve geri donebilmeli. Koc icin boyle bir
                     baglanti YOK - yonetim paneli ona kapali. --}}
                @if(auth()->user()->isAdmin())
                    <div class="sidebar-nav-section">
                        <span class="sidebar-nav-section-title">Yönetim</span>
                    </div>

                    <a href="{{ route('admin.dashboard') }}" class="sidebar-nav-link">
                        <span class="sidebar-nav-link-icon">📊</span>
                        <span>Yönetim Paneli</span>
                    </a>
                @endif
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar">
                        {{ strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}
                    </div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">{{ auth()->user()->name }}</div>
                        <div class="sidebar-user-role">
                            {{-- Abonelik yalnizca ogrenciyi ilgilendirir. --}}
                            <span>{{ auth()->user()->role()?->label() }}</span>
                        </div>
                    </div>
                    <form action="{{ route('logout') }}" method="POST" class="d-inline-block">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary" title="Çıkış">
                            🚪
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

        <main class="main-content has-bottom-nav">
            <header class="topbar">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-icon btn-secondary d-lg-none" onclick="toggleSidebar()">
                        ☰
                    </button>
                    <h1 class="topbar-title">@yield('page-title', 'Çalışma Planı')</h1>
                </div>

                <div class="topbar-actions">
                    @yield('topbar-actions')
                </div>
            </header>

            <div class="page-content">
                @if(session('success'))
                    <div class="alert alert-success animate-slide-up">
                        ✅ {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger animate-slide-up">
                        ❌ {{ session('error') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger animate-slide-up">
                        ❌ {{ $errors->first() }}
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

    @include('layouts._bottom-nav', ['tabs' => array_values(array_filter([
        ['route' => 'coach.plan.index', 'label' => 'Planlar', 'icon' => '🗓️', 'match' => 'coach.plan.*'],
        auth()->user()->isAdmin()
            ? ['route' => 'admin.dashboard', 'label' => 'Yönetim', 'icon' => '📊']
            : null,
    ]))])

    @stack('scripts')
</body>

</html>
