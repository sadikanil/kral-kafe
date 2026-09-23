{{-- Beklenen: $package (null = yeni), $products, $items (product_id => PackageItem) --}}
<div class="form-group">
    <label for="name" class="form-label">Paket Adı *</label>
    <input type="text" id="name" name="name" maxlength="80" required autofocus
        class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $package?->name) }}" placeholder="Örn. Standart">
    @error('name')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="form-group">
    <label for="tier" class="form-label">Seviye</label>
    <select id="tier" name="tier" class="form-control @error('tier') is-invalid @enderror">
        @php $seviye = (string) old('tier', $package?->tier); @endphp
        <option value="" {{ $seviye === '' ? 'selected' : '' }}>Yok (ek paket / sadece deneme)</option>
        <option value="1" {{ $seviye === '1' ? 'selected' : '' }}>Tier 1 · Standart</option>
        <option value="2" {{ $seviye === '2' ? 'selected' : '' }}>Tier 2 · Orta</option>
        <option value="3" {{ $seviye === '3' ? 'selected' : '' }}>Tier 3 · Kral</option>
    </select>
    <small class="text-muted">Yalnızca etiket. Öğrencinin neye erişeceğini aşağıdaki kutular belirler.</small>
    @error('tier')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="form-group">
    <label for="monthly_price" class="form-label">Aylık Fiyat (₺) *</label>
    <input type="number" id="monthly_price" name="monthly_price" step="0.01" min="0" required
        class="form-control @error('monthly_price') is-invalid @enderror" value="{{ old('monthly_price', $package?->monthly_price) }}" placeholder="7500">
    @error('monthly_price')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="form-group">
    <label for="description" class="form-label">Açıklama</label>
    <input type="text" id="description" name="description" maxlength="255"
        class="form-control @error('description') is-invalid @enderror" value="{{ old('description', $package?->description) }}">
    @error('description')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="form-group">
    <label class="form-label d-flex align-items-center gap-2">
        <input type="checkbox" name="has_reserved_table" value="1" {{ old('has_reserved_table', $package?->has_reserved_table) ? 'checked' : '' }}>
        Rezerve masa
    </label>
    <label class="form-label d-flex align-items-center gap-2">
        <input type="checkbox" name="includes_coaching" value="1" {{ old('includes_coaching', $package?->includes_coaching) ? 'checked' : '' }}>
        Koçluk dahil
    </label>
    <label class="form-label d-flex align-items-center gap-2">
        <input type="checkbox" name="includes_exam_club" value="1" {{ old('includes_exam_club', $package?->includes_exam_club) ? 'checked' : '' }}>
        Deneme kulübü (deneme detayları ve sonuçlar)
    </label>
    <label class="form-label d-flex align-items-center gap-2">
        <input type="checkbox" name="includes_private_lessons" value="1" {{ old('includes_private_lessons', $package?->includes_private_lessons) ? 'checked' : '' }}>
        Özel ders
    </label>
    <label class="form-label d-flex align-items-center gap-2">
        <input type="checkbox" name="is_addon" value="1" {{ old('is_addon', $package?->is_addon) ? 'checked' : '' }}>
        Ek paket (ana paketin üstüne eklenir)
    </label>
</div>

<div class="form-group">
    <label for="weekly_mock_exams" class="form-label">Haftalık deneme sayısı</label>
    <input type="number" id="weekly_mock_exams" name="weekly_mock_exams" min="0" max="20"
        class="form-control @error('weekly_mock_exams') is-invalid @enderror" value="{{ old('weekly_mock_exams', $package?->weekly_mock_exams ?? 0) }}">
    @error('weekly_mock_exams')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="form-group">
    <label class="form-label">Pakete dahil ürünler</label>
    @if($products->isEmpty())
        <p class="text-muted mb-0">Sistemde aktif ürün yok.</p>
    @else
        <div class="rounded p-2" style="max-height: 280px; overflow-y: auto; border: 1px solid var(--separator);">
            @foreach($products as $urun)
                @php
                    $eski = old('items.' . $urun->id);
                    $kalem = $items->get($urun->id);
                    $dahil = $eski !== null ? !empty($eski['included']) : $kalem !== null;
                    $adet = $eski !== null ? ($eski['quantity'] ?? '') : ($kalem?->included_quantity ?? '');
                    $donem = $eski !== null ? ($eski['period'] ?? 'monthly') : ($kalem?->period?->value ?? 'monthly');
                @endphp
                <div class="d-flex align-items-center gap-2 mb-1" style="flex-wrap: wrap;">
                    <label class="d-flex align-items-center gap-1" style="min-width: 180px;">
                        <input type="checkbox" name="items[{{ $urun->id }}][included]" value="1" {{ $dahil ? 'checked' : '' }}>
                        {{ $urun->name }}
                    </label>
                    <input type="number" name="items[{{ $urun->id }}][quantity]" min="1" max="1000" class="form-control" style="width: 90px; padding: 4px;"
                        value="{{ $adet }}" placeholder="sınırsız">
                    <select name="items[{{ $urun->id }}][period]" class="form-control" style="width: 110px; padding: 4px;">
                        @foreach(\App\Enums\PackagePeriod::cases() as $p)
                            <option value="{{ $p->value }}" {{ $donem === $p->value ? 'selected' : '' }}>{{ $p->label() }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
        <small class="text-muted">Adet boş bırakılırsa sınırsız. Kapsamın tüketime uygulanması Dalga 8'de gelir; şimdilik tanım.</small>
    @endif
    @error('items.*.quantity')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
</div>

@if($package)
    <div class="form-group">
        <label class="form-label d-flex align-items-center gap-2">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $package->is_active) ? 'checked' : '' }}>
            Satışta
        </label>
    </div>
@endif
