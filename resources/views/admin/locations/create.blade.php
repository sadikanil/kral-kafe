@extends('layouts.app')

@section('title', 'Yeni Lokasyon - Kral Kafe')
@section('page-title', 'Yeni Lokasyon Ekle')

@section('content')
    <div class="card" style="max-width: 700px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.locations.store') }}">
                @csrf
                
                <div class="form-group">
                    <label for="name" class="form-label">Lokasyon Adı *</label>
                    <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" placeholder="Örn: Buzdolabı 1, Raf A" required>
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>
                
                <div class="form-group">
                    <label for="type" class="form-label">Lokasyon Tipi *</label>
                    <select id="type" name="type" class="form-control @error('type') is-invalid @enderror" required>
                        <option value="">Seçin...</option>
                        <option value="shelf" {{ old('type') == 'shelf' ? 'selected' : '' }}>📚 Raf</option>
                        <option value="cabinet" {{ old('type') == 'cabinet' ? 'selected' : '' }}>🗄️ Dolap</option>
                        <option value="fridge" {{ old('type') == 'fridge' ? 'selected' : '' }}>❄️ Buzdolabı</option>
                    </select>
                    @error('type')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>
                
                <div class="form-group">
                    <label for="description" class="form-label">Açıklama</label>
                    <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror" rows="2">{{ old('description') }}</textarea>
                    @error('description')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bu Lokasyondaki Ürünler</label>
                    <div class="card" style="max-height: 300px; overflow-y: auto;">
                        <div class="card-body p-2">
                            @forelse($products as $product)
                                <div class="form-check">
                                    <input type="checkbox" 
                                           id="product_{{ $product->id }}" 
                                           name="products[]" 
                                           value="{{ $product->id }}"
                                           class="form-check-input"
                                           {{ in_array($product->id, old('products', [])) ? 'checked' : '' }}>
                                    <label for="product_{{ $product->id }}" class="form-check-label">
                                        {{ $product->name }} <span class="text-muted">({{ $product->formatted_price }})</span>
                                    </label>
                                </div>
                            @empty
                                <p class="text-muted mb-0">Henüz ürün eklenmemiş. Önce ürün ekleyin.</p>
                            @endforelse
                        </div>
                    </div>
                    <span class="form-text">Bu lokasyonda satılacak ürünleri seçin.</span>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Lokasyon Ekle</button>
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
