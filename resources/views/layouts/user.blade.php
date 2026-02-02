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
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="{{ route('user.dashboard') }}" class="sidebar-logo">
                    <span class="sidebar-logo-icon">☕</span>
                    <span>Kral Kafe</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-nav-section">
                    <span class="sidebar-nav-section-title">Menü</span>
                </div>

                <a href="{{ route('user.dashboard') }}"
                    class="sidebar-nav-link {{ request()->routeIs('user.dashboard') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">🏠</span>
                    <span>Ana Sayfa</span>
                </a>

                <a href="{{ route('user.history') }}"
                    class="sidebar-nav-link {{ request()->routeIs('user.history') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📜</span>
                    <span>Tüketim Geçmişi</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar">
                        {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                    </div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">{{ auth()->user()->name }}</div>
                        <div class="sidebar-user-role">
                            @if(auth()->user()->hasActiveSubscription())
                                <span class="text-success">Aktif Üye</span>
                            @else
                                <span class="text-danger">Pasif</span>
                            @endif
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

        <!-- Sidebar Overlay (Mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-icon btn-secondary d-lg-none" onclick="toggleSidebar()">
                        ☰
                    </button>
                    <h1 class="topbar-title">@yield('page-title', 'Panel')</h1>
                </div>

                <div class="topbar-actions">
                    @yield('topbar-actions')
                </div>
            </header>

            <div class="page-content">
                <!-- Flash Messages -->
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

    @stack('scripts')
</body>

</html>