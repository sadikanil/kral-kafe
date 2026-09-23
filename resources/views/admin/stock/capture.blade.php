@extends('layouts.app')

@section('title', 'Stok Sayımı - ' . $location->name)
@section('page-title', 'Stok Sayımı: ' . $location->name)

@section('content')
    <div class="card" style="max-width: 800px;">
        <div class="card-header">
            <h4>📷 Fotoğraf Yükle</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-4">
                <strong>Nasıl çalışır?</strong><br>
                1. Lokasyondaki ürünlerin net görünen fotoğraflarını çekin<br>
                2. Fotoğrafları yükleyin (birden fazla olabilir)<br>
                3. AI analizi ile ürün sayılarını otomatik tespit edin<br>
                4. Sonuçları gözden geçirip onaylayın
            </div>

            <form method="POST" action="{{ route('admin.stock.upload', $location) }}" enctype="multipart/form-data">
                @csrf

                <div class="form-group">
                    <label for="photos" class="form-label">Fotoğraflar *</label>
                    <input type="file" id="photos" name="photos[]"
                        class="form-control @error('photos') is-invalid @enderror @error('photos.*') is-invalid @enderror"
                        accept="image/*" multiple required>
                    @error('photos')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                    @error('photos.*')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                    <span class="form-text">JPG, PNG formatında birden fazla fotoğraf seçebilirsiniz.</span>
                </div>

                <div class="form-group">
                    <label for="record_type" class="form-label">Sayım Tipi *</label>
                    <select id="record_type" name="record_type" class="form-control" required>
                        <option value="opening" {{ $recordType === 'opening' ? 'selected' : '' }}>Açılış Sayımı</option>
                        <option value="closing" {{ $recordType === 'closing' ? 'selected' : '' }}>Kapanış Sayımı</option>
                    </select>
                    @error('record_type')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="notes" class="form-label">Notlar (Opsiyonel)</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2"
                        placeholder="Varsa eklemek istediğiniz notlar..."></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        📤 Yükle ve Analiz Et
                    </button>
                    <a href="{{ route('admin.stock.counts') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bu konumdaki urunler -->
    <div class="card mt-4" style="max-width: 800px;">
        <div class="card-header">
            <h4>Bu Konumdaki Ürünler ({{ $location->products->count() }})</h4>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ürün</th>
                            <th>Sistemdeki stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($location->products as $product)
                            <tr>
                                <td>{{ $product->name }}</td>
                                <td>
                                    @if($product->tracksStock())
                                        {{ $product->stock_quantity }}
                                    @else
                                        <span class="text-muted">takip yok · ilk sayım başlatır</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection