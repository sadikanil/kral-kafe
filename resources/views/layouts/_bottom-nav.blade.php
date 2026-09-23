{{--
    Alt menu (Dalga 10a, Dalga 21'de yenilendi).

    $tabs: en sik 4 sayfa (App\Support\Navigation::quick). 5. yer "Menu":
    kenar cubugunu acar, tum sayfalar ayni gruplu duz listede. Her sayfa en
    fazla iki dokunusta.

    Yalnizca 1024px altinda cizilir (CSS); masaustunde kenar cubugu var.

    Etkin sekme renkle birlikte aria-current ile de belli; simgeler ekran
    okuyucudan gizli. "Menu" dugmesini kabuk.js baglar ve aria-expanded'i
    gunceller (acik/kapali durumu sesli okunur).
--}}
<nav class="bottom-nav" aria-label="Hızlı menü">
    @foreach($tabs as $tab)
        @php($etkin = $aktif($tab))
        <a href="{{ route($tab['route']) }}" class="bottom-nav-item{{ $etkin ? ' is-active' : '' }}"@if($etkin) aria-current="page"@endif>
            <span class="bottom-nav-icon" aria-hidden="true">{{ $tab['icon'] }}</span>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
    <button type="button" class="bottom-nav-item js-menu-dugmesi" aria-controls="sidebar" aria-expanded="false">
        <span class="bottom-nav-icon" aria-hidden="true">☰</span>
        <span>Menü</span>
    </button>
</nav>
