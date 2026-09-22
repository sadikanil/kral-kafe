{{--
    Alt menu (Dalga 10a).

    $tabs: ['route' => ..., 'label' => ..., 'icon' => ..., 'match' => ...]
    'match' verilmezse route adinin kendisi kullanilir; bir sekme birden
    cok rotayi kapsiyorsa (gecmis + detay) desen olarak verilir.

    Yalnizca 1024px altinda cizilir (CSS); masaustunde kenar cubugu var.
--}}
<nav class="bottom-nav">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="bottom-nav-item {{ request()->routeIs($tab['match'] ?? $tab['route']) ? 'is-active' : '' }}">
            <span class="bottom-nav-icon">{{ $tab['icon'] }}</span>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
</nav>
