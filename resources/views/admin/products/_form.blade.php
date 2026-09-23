{{--
    Urun formu - ekleme ve duzenleme AYNI parca (Dalga 29).

    Iki ayri kopya vardi ve ayristilar: aciklama alani ikisinde de vardi ama
    kaydedilmiyordu, durum kutusu duzenlemede yoktu. Beklenen: $product
    (yeni urunde bos model), $categories, $units, $locations.
--}}
@php
    $emojiler = [
        'İçecekler' => ['☕' => 'Kahve', '🍵' => 'Çay', '🥤' => 'İçecek', '🧃' => 'Meyve Suyu', '💧' => 'Su', '🥛' => 'Süt'],
        'Yiyecekler' => ['🥯' => 'Simit/Poğaça', '🥪' => 'Sandviç', '🥐' => 'Kruvasan', '🍪' => 'Kurabiye', '🍫' => 'Çikolata', '🍰' => 'Kek', '🍬' => 'Şeker', '🍟' => 'Cips'],
    ];
    $hazirBirimler = ['Paket', 'Teneke Kutu', 'Cam Şişe', 'Pet Şişe', 'Fincan'];
@endphp

<div class="d-flex gap-2">
    <div class="form-group" style="flex: 1;">
        <label for="name" class="form-label">Ürün Adı *</label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $product->name) }}" required>
        @error('name')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>

    <div class="form-group" style="width: 120px;">
        <label for="emoji" class="form-label">Emoji</label>
        <select id="emoji" name="emoji" class="form-control @error('emoji') is-invalid @enderror">
            <option value="">Seç...</option>
            @foreach($emojiler as $grup => $secenekler)
                <optgroup label="{{ $grup }}">
                    @foreach($secenekler as $emoji => $ad)
                        <option value="{{ $emoji }}" @selected(old('emoji', $product->emoji) === $emoji)>{{ $emoji }} {{ $ad }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error('emoji')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
</div>

<div class="form-group">
    <label for="category" class="form-label">Kategori</label>
    <input type="text" id="category" name="category" list="categoryList"
        class="form-control @error('category') is-invalid @enderror"
        value="{{ old('category', $product->category) }}" placeholder="Örn: Soğuk İçecek, Ülker" autocomplete="off">
    <datalist id="categoryList">
        @foreach($categories as $cat)
            <option value="{{ $cat }}">
        @endforeach
    </datalist>
    @error('category')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="d-flex gap-2">
    <div class="form-group" style="flex: 1;">
        <label for="unit_price" class="form-label">Birim Fiyat (₺) *</label>
        <input type="number" step="0.01" min="0" id="unit_price" name="unit_price"
            class="form-control @error('unit_price') is-invalid @enderror"
            value="{{ old('unit_price', $product->unit_price) }}" required>
        @error('unit_price')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>

    <div class="form-group" style="flex: 1;">
        <label for="unit_type" class="form-label">Birim *</label>
        <input type="text" id="unit_type" name="unit_type" list="unitList"
            class="form-control @error('unit_type') is-invalid @enderror"
            value="{{ old('unit_type', $product->unit_type) }}" placeholder="Seçin veya yazın" required autocomplete="off">
        <datalist id="unitList">
            @foreach(collect($hazirBirimler)->merge($units)->reject(fn ($b) => in_array($b, ['piece', 'kg', 'liter']))->unique() as $birim)
                <option value="{{ $birim }}">
            @endforeach
        </datalist>
        @error('unit_type')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
</div>

<div class="form-group">
    <label for="description" class="form-label">Açıklama</label>
    <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror"
        rows="3" maxlength="1000">{{ old('description', $product->description) }}</textarea>
    @error('description')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

{{-- Konum etiketi (Dalga 29): listeden sec ya da yeni bir ad yaz. --}}
<div class="d-flex gap-2" style="flex-wrap: wrap;">
    <div class="form-group" style="flex: 1; min-width: 180px;">
        <label for="location_id" class="form-label">Konum</label>
        <select id="location_id" name="location_id" class="form-control @error('location_id') is-invalid @enderror">
            <option value="">Konum yok</option>
            @foreach($locations as $konum)
                <option value="{{ $konum->id }}" @selected((int) old('location_id', $product->location_id) === $konum->id)>{{ $konum->name }}</option>
            @endforeach
        </select>
        @error('location_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
    <div class="form-group" style="flex: 1; min-width: 180px;">
        <label for="new_location" class="form-label">…ya da yeni konum</label>
        <input type="text" id="new_location" name="new_location" maxlength="100"
            class="form-control @error('new_location') is-invalid @enderror"
            value="{{ old('new_location') }}" placeholder="Örn: Depo">
        @error('new_location')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
</div>

<div class="d-flex gap-2">
    <div class="form-group" style="flex: 1;">
        <label for="stock_quantity" class="form-label">Stok</label>
        <input type="number" min="0" id="stock_quantity" name="stock_quantity" inputmode="numeric"
            class="form-control @error('stock_quantity') is-invalid @enderror"
            value="{{ old('stock_quantity', $product->stock_quantity) }}" placeholder="Boş = takip yok">
        @error('stock_quantity')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
    <div class="form-group" style="flex: 1;">
        <label for="critical_quantity" class="form-label">Kritik stok</label>
        <input type="number" min="0" id="critical_quantity" name="critical_quantity" inputmode="numeric"
            class="form-control @error('critical_quantity') is-invalid @enderror"
            value="{{ old('critical_quantity', $product->critical_quantity) }}" placeholder="Örn: 5">
        @error('critical_quantity')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
</div>
<p class="form-text mb-3">Stoğu boş bırakırsan (sıcak içecek, çay) satışta düşmez. Stok kritik sayıya indiğinde yöneticiye bildirim gelir.</p>

<div class="form-check mb-3">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
        @checked(old('is_active', $product->exists ? $product->is_active : true))>
    <label for="is_active" class="form-check-label">Satışta (öğrenci adisyonda görür)</label>
</div>
