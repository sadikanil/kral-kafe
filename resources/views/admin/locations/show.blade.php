@extends('layouts.admin')

@section('title', $location->name . ' - Kral Kafe')
@section('page-title', $location->name)

@section('topbar-actions')
    <a href="{{ route('admin.locations.qr', $location) }}" class="btn btn-secondary btn-sm">📱 QR Kod</a>
    <a href="{{ route('admin.locations.edit', $location) }}" class="btn btn-primary btn-sm">✏️ Düzenle</a>
@endsection

@section('content')
    <div class="d-grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
        <!-- Lokasyon Bilgisi -->
        <div class="card">
            <div class="card-header">
                <h4>Lokasyon Bilgisi</h4>
            </div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <td class="text-muted">Tip</td>
                        <td>
                            <span style="font-size: 1.25rem;">
                                @switch($location->type)
                                    @case('shelf') 📚 @break
                                    @case('cabinet') 🗄️ @break
                                    @case('fridge') ❄️ @break
                                @endswitch
                            </span>
                            {{ $location->type_name }}
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">QR Kod</td>
                        <td><code>{{ $location->qr_code }}</code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Durum</td>
                        <td>
                            <span class="badge badge-{{ $location->is_active ? 'success' : 'warning' }}">
                                {{ $location->is_active ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                    </tr>
                    @if($location->description)
                        <tr>
                            <td class="text-muted">Açıklama</td>
                            <td>{{ $location->description }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        </div>
        
        <!-- QR Kod -->
        <div class="card">
            <div class="card-header">
                <h4>QR Kod</h4>
            </div>
            <div class="card-body text-center">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode($location->qr_url) }}" alt="QR Code" style="border-radius: var(--radius);">
                <p class="text-muted mt-2 mb-0">
                    <small>{{ $location->qr_url }}</small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Ürünler -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Bu Lokasyondaki Ürünler</h4>
            <span class="badge badge-info">{{ $location->products->count() }} ürün</span>
        </div>
        <div class="card-body p-0">
            @if($location->products->isEmpty())
                <div class="p-4 text-center text-muted">
                    Bu lokasyonda henüz ürün tanımlı değil.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Fiyat</th>
                                <th>Birim</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($location->products as $product)
                                <tr>
                                    <td>{{ $product->name }}</td>
                                    <td>{{ $product->formatted_price }}</td>
                                    <td>{{ $product->unit_name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
