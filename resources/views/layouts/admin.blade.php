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
                <a href="{{ route('admin.dashboard') }}" class="sidebar-logo">
                    <span class="sidebar-logo-icon">☕</span>
                    <span>Kral Kafe</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-nav-section">
                    <span class="sidebar-nav-section-title">Genel</span>
                </div>

                <a href="{{ route('admin.dashboard') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📊</span>
                    <span>Panel</span>
                </a>

                <div class="sidebar-nav-section">
                    <span class="sidebar-nav-section-title">Yönetim</span>
                </div>

                <a href="{{ route('admin.users.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">👥</span>
                    <span>Kullanıcılar</span>
                </a>

                <a href="{{ route('admin.live') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.live') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">🟢</span>
                    <span>Canlı Ekran</span>
                </a>

                <a href="{{ route('admin.subjects.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.subjects.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📚</span>
                    <span>Dersler</span>
                </a>

                <a href="{{ route('admin.settings.edit') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">⚙️</span>
                    <span>Ayarlar</span>
                </a>

                <a href="{{ route('admin.tables.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.tables.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">🪑</span>
                    <span>Masalar</span>
                </a>

                <a href="{{ route('admin.packages.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.packages.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">🎫</span>
                    <span>Paketler</span>
                </a>

                <a href="{{ route('admin.subscriptions.overview') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.subscriptions.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">💳</span>
                    <span>Ödemeler</span>
                </a>

                <a href="{{ route('admin.exams.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.exams.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📝</span>
                    <span>Deneme Takvimi</span>
                </a>

                <a href="{{ route('admin.products.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📦</span>
                    <span>Ürünler</span>
                </a>

                <a href="{{ route('admin.locations.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.locations.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📍</span>
                    <span>Lokasyonlar</span>
                </a>

                <div class="sidebar-nav-section">
                    <span class="sidebar-nav-section-title">Stok</span>
                </div>

                <a href="{{ route('admin.stock.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.stock.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📷</span>
                    <span>Stok Sayım</span>
                </a>

                <div class="sidebar-nav-section">
                    <span class="sidebar-nav-section-title">Raporlar</span>
                </div>

                <a href="{{ route('admin.reports.index') }}"
                    class="sidebar-nav-link {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">
                    <span class="sidebar-nav-link-icon">📈</span>
                    <span>Raporlar</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar">
                        {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                    </div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">{{ auth()->user()->name }}</div>
                        <div class="sidebar-user-role">Yönetici</div>
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
        <main class="main-content has-bottom-nav">
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

    @include('layouts._bottom-nav', ['tabs' => [
        ['route' => 'admin.dashboard', 'label' => 'Panel', 'icon' => '🏠'],
        ['route' => 'admin.live', 'label' => 'Canlı', 'icon' => '🟢'],
        ['route' => 'admin.users.index', 'label' => 'Kullanıcılar', 'icon' => '👥', 'match' => 'admin.users.*'],
        ['route' => 'admin.tables.index', 'label' => 'Masalar', 'icon' => '🪑', 'match' => 'admin.tables.*'],
    ]])

    @stack('scripts')
</body>

</html>