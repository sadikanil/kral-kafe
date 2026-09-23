{{-- Urunler altindaki sekmeler (Dalga 29): urun, stok ve sayim tek menude. --}}
<nav class="page-tabs mb-3">
    <a href="{{ route('admin.products.index') }}" class="page-tab {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">☕ Ürünler</a>
    <a href="{{ route('admin.stock.index') }}" class="page-tab {{ request()->routeIs('admin.stock.index') ? 'active' : '' }}">📦 Stok</a>
    <a href="{{ route('admin.stock.counts') }}" class="page-tab {{ request()->routeIs('admin.stock.*') && ! request()->routeIs('admin.stock.index') ? 'active' : '' }}">📷 Sayım</a>
</nav>
