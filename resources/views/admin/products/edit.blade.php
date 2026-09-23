@extends('layouts.app')

@section('title', 'Ürün Düzenle - Kral Kafe')
@section('page-title', 'Ürün Düzenle')

@section('content')
    <div class="card" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.products.update', $product) }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="d-flex gap-2">
                    <div class="form-group" style="flex: 1;">
                        <label for="name" class="form-label">Ürün Adı *</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                            value="{{ old('name', $product->name) }}" required>
                        @error('name')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group" style="width: 120px;">
                        <label for="emoji" class="form-label">Emoji</label>
                        <select id="emoji" name="emoji" class="form-control @error('emoji') is-invalid @enderror">
                            <option value="">Seç...</option>
                            <optgroup label="İçecekler">
                                <option value="☕" {{ old('emoji', $product->emoji) == '☕' ? 'selected' : '' }}>☕ Kahve
                                </option>
                                <option value="🍵" {{ old('emoji', $product->emoji) == '🍵' ? 'selected' : '' }}>🍵 Çay
                                </option>
                                <option value="🥤" {{ old('emoji', $product->emoji) == '🥤' ? 'selected' : '' }}>🥤 İçecek
                                </option>
                                <option value="🧃" {{ old('emoji', $product->emoji) == '🧃' ? 'selected' : '' }}>🧃 Meyve Suyu
                                </option>
                                <option value="🥛" {{ old('emoji', $product->emoji) == '🥛' ? 'selected' : '' }}>🥛 Süt
                                </option>
                            </optgroup>
                            <optgroup label="Yiyecekler">
                                <option value="🥯" {{ old('emoji', $product->emoji) == '🥯' ? 'selected' : '' }}>🥯
                                    Simit/Poğaça</option>
                                <option value="🥪" {{ old('emoji', $product->emoji) == '🥪' ? 'selected' : '' }}>🥪 Sandviç
                                </option>
                                <option value="🥐" {{ old('emoji', $product->emoji) == '🥐' ? 'selected' : '' }}>🥐 Kruvasan
                                </option>
                                <option value="🍪" {{ old('emoji', $product->emoji) == '🍪' ? 'selected' : '' }}>🍪 Kurabiye
                                </option>
                                <option value="🍫" {{ old('emoji', $product->emoji) == '🍫' ? 'selected' : '' }}>🍫 Çikolata
                                </option>
                                <option value="🍬" {{ old('emoji', $product->emoji) == '🍬' ? 'selected' : '' }}>🍬 Şeker
                                </option>
                                <option value="🍟" {{ old('emoji', $product->emoji) == '🍟' ? 'selected' : '' }}>🍟 Cips
                                </option>
                            </optgroup>
                        </select>
                        @error('emoji')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-group">
                    <label for="category" class="form-label">Kategori</label>
                    <input type="text" id="category" name="category" list="categoryList"
                        class="form-control @error('category') is-invalid @enderror"
                        value="{{ old('category', $product->category) }}" autocomplete="off">
                    <datalist id="categoryList">
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}">
                        @endforeach
                    </datalist>
                    @error('category')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <div class="form-group" style="flex: 1;">
                        <label for="unit_price" class="form-label">Birim Fiyat (₺) *</label>
                        <input type="number" step="0.01" min="0" id="unit_price" name="unit_price"
                            class="form-control @error('unit_price') is-invalid @enderror"
                            value="{{ old('unit_price', $product->unit_price) }}" required>
                        @error('unit_price')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label for="unit_type" class="form-label">Birim *</label>
                        <input type="text" id="unit_type" name="unit_type" list="unitList"
                            class="form-control @error('unit_type') is-invalid @enderror"
                            value="{{ old('unit_type', $product->unit_type) }}" required autocomplete="off">
                        <datalist id="unitList">
                            <option value="Paket">
                            <option value="Teneke Kutu">
                            <option value="Cam Şişe">
                            <option value="Pet Şişe">
                                @foreach($units as $unit)
                                    @if(!in_array($unit, ['Paket', 'Teneke Kutu', 'Cam Şişe', 'Pet Şişe', 'piece', 'kg', 'liter']))
                                        <option value="{{ $unit }}">
                                    @endif
                                @endforeach
                        </datalist>
                        @error('unit_type')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Açıklama</label>
                    <textarea id="description" name="description"
                        class="form-control @error('description') is-invalid @enderror"
                        rows="3">{{ old('description', $product->description) }}</textarea>
                    @error('description')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection