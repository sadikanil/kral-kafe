{{--
    Alt sekme cubugu (Dalga 10a, Dalga 21 ve Faz 3'te yenilendi).

    $tabs: en sik 4 sayfa (App\Support\Navigation::quick). 5. yer "Menu":
    tum sayfalarin gruplu listesini alttan acilan sayfa olarak acar. Her
    sayfa en fazla iki dokunusta.

    Yalnizca 1024px altinda cizilir (CSS); masaustunde kenar cubugu var.

    Etkin sekme renkle birlikte aria-current ile de belli; simgeler ekran
    okuyucudan gizli. "Menu" dugmesini kabuk.js baglar ve aria-expanded'i
    gunceller (acik/kapali durumu sesli okunur).
--}}
<nav class="bottom-nav" aria-label="Hızlı menü">
    @foreach($tabs as $tab)
        @php($etkin = $aktif($tab))
        <a href="{{ route($tab['route']) }}" class="bottom-nav-item{{ $etkin ? ' is-active' : '' }}"@if($etkin) aria-current="page"@endif>
            <span class="bottom-nav-icon" aria-hidden="true"><x-icon :name="$tab['icon']" /></span>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
    <button type="button" class="bottom-nav-item js-menu-dugmesi" aria-controls="sidebar" aria-expanded="false">
        <span class="bottom-nav-icon" aria-hidden="true"><x-icon name="menu" /></span>
        <span>Menü</span>
    </button>
</nav>
