@extends('layouts.app')

@section('title', 'Ürünler - Kral Kafe')
@section('page-title', 'Ürünler')

@section('topbar-actions')
    <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">
        ➕ Yeni Ürün
    </a>
@endsection

@section('content')
    @include('admin.products._tabs')

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ürün</th>
                            <th>Kategori</th>
                            <th>Fiyat</th>
                            <th>Konum</th>
                            <th>Stok</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div
                                            style="width: 40px; height: 40px; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                                            {{ $product->emoji ? $product->emoji : '📦' }}
                                        </div>
                                        <span>{{ $product->name }}</span>
                                    </div>
                                </td>
                                <td>{{ $product->category ?? '-' }}</td>
                                <td>{{ $product->formatted_price }}</td>
                                <td>{{ $product->location->name ?? '—' }}</td>
                                <td>
                                    @if($product->tracksStock())
                                        <span class="{{ $product->isCritical() || $product->stock_quantity <= 0 ? 'text-danger' : '' }}">
                                            {{ $product->stock_quantity }} {{ $product->unit_type_name }}
                                        </span>
                                    @else
                                        <span class="text-muted">takip yok</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-{{ $product->is_active ? 'success' : 'warning' }}">
                                        {{ $product->is_active ? 'Aktif' : 'Pasif' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('admin.products.edit', $product) }}"
                                            class="btn btn-sm btn-secondary">✏️</a>

                                        <form action="{{ route('admin.products.toggle-status', $product) }}" method="POST"
                                            class="d-inline-block">
                                            @csrf
                                            <button type="submit"
                                                class="btn btn-sm btn-{{ $product->is_active ? 'warning' : 'success' }}"
                                                title="{{ $product->is_active ? 'Devre Dışı' : 'Aktifleştir' }}">
                                                {{ $product->is_active ? '⏸️' : '▶️' }}
                                            </button>
                                        </form>

                                        <form action="{{ route('admin.products.destroy', $product) }}" method="POST"
                                            class="d-inline-block"
                                            onsubmit="return confirm('Bu ürünü silmek istediğinize emin misiniz?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center p-4 text-muted">
                                    Henüz ürün eklenmemiş.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $products->withQueryString()->links() }}
        </div>
    </div>
@endsection