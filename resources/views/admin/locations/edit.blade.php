@extends('layouts.admin')

@section('title', 'Lokasyon Düzenle - Kral Kafe')
@section('page-title', 'Lokasyon Düzenle')

@section('content')
    <div class="card" style="max-width: 700px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.locations.update', $location) }}">
                @csrf
                @method('PUT')
                
                <div class="form-group">
                    <label for="name" class="form-label">Lokasyon Adı *</label>
                    <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $location->name) }}" required>
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>
                
                <div class="form-group">
                    <label for="type" class="form-label">Lokasyon Tipi *</label>
                    <select id="type" name="type" class="form-control @error('type') is-invalid @enderror" required>
                        <option value="shelf" {{ old('type', $location->type) == 'shelf' ? 'selected' : '' }}>📚 Raf</option>
                        <option value="cabinet" {{ old('type', $location->type) == 'cabinet' ? 'selected' : '' }}>🗄️ Dolap</option>
                        <option value="fridge" {{ old('type', $location->type) == 'fridge' ? 'selected' : '' }}>❄️ Buzdolabı</option>
                    </select>
                    @error('type')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>
                
                <div class="form-group">
                    <label for="description" class="form-label">Açıklama</label>
                    <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror" rows="2">{{ old('description', $location->description) }}</textarea>
                    @error('description')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bu Lokasyondaki Ürünler</label>
                    <div class="card" style="max-height: 300px; overflow-y: auto;">
                        <div class="card-body p-2">
                            @php
                                $locationProductIds = $location->products->pluck('id')->toArray();
                            @endphp
                            @forelse($products as $product)
                                <div class="form-check">
                                    <input type="checkbox" 
                                           id="product_{{ $product->id }}" 
                                           name="products[]" 
                                           value="{{ $product->id }}"
                                           class="form-check-input"
                                           {{ in_array($product->id, old('products', $locationProductIds)) ? 'checked' : '' }}>
                                    <label for="product_{{ $product->id }}" class="form-check-label">
                                        {{ $product->name }} <span class="text-muted">({{ $product->formatted_price }})</span>
                                    </label>
                                </div>
                            @empty
                                <p class="text-muted mb-0">Henüz ürün eklenmemiş.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
