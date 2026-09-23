{{--
    Alt menu (Dalga 10a, Dalga 21'de yenilendi).

    $tabs: en sik 4 sayfa (App\Support\Navigation::quick). 5. yer "Menu":
    kenar cubugunu acar, tum sayfalar ayni gruplu duz listede. Her sayfa en
    fazla iki dokunusta.

    Yalnizca 1024px altinda cizilir (CSS); masaustunde kenar cubugu var.
--}}
<nav class="bottom-nav">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}" class="bottom-nav-item {{ $aktif($tab) ? 'is-active' : '' }}">
            <span class="bottom-nav-icon">{{ $tab['icon'] }}</span>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
    <button type="button" class="bottom-nav-item" onclick="toggleSidebar()">
        <span class="bottom-nav-icon">☰</span>
        <span>Menü</span>
    </button>
</nav>
