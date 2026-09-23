{{-- Beklenen: $exam (null ise yeni kayit) --}}
<div class="form-group">
    <label for="title" class="form-label">Deneme Adı *</label>
    <input type="text" id="title" name="title" maxlength="100" required autofocus
        class="form-control @error('title') is-invalid @enderror"
        value="{{ old('title', $exam?->title) }}" placeholder="Örn. Türkiye Geneli TYT Denemesi 3">
    @error('title')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="form-group">
    <label for="exam_type" class="form-label">Sınav Türü *</label>
    <select id="exam_type" name="exam_type" class="form-control @error('exam_type') is-invalid @enderror" required>
        @foreach(\App\Enums\ExamType::cases() as $tur)
            <option value="{{ $tur->value }}" {{ old('exam_type', $exam?->exam_type?->value ?? 'tyt') === $tur->value ? 'selected' : '' }}>{{ $tur->label() }}</option>
        @endforeach
    </select>
    @error('exam_type')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<div class="d-flex gap-2">
    <div class="form-group" style="flex: 1;">
        <label for="exam_date" class="form-label">Tarih *</label>
        <input type="date" id="exam_date" name="exam_date" required
            class="form-control @error('exam_date') is-invalid @enderror"
            value="{{ old('exam_date', $exam?->exam_date?->toDateString()) }}">
        @error('exam_date')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
    <div class="form-group" style="flex: 1;">
        <label for="starts_at" class="form-label">Başlangıç Saati</label>
        <input type="time" id="starts_at" name="starts_at"
            class="form-control @error('starts_at') is-invalid @enderror"
            value="{{ old('starts_at', $exam?->starts_at) }}">
        @error('starts_at')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
</div>

<div class="form-group">
    <label for="note" class="form-label">Not</label>
    <input type="text" id="note" name="note" maxlength="255"
        class="form-control @error('note') is-invalid @enderror"
        value="{{ old('note', $exam?->note) }}" placeholder="Örn. Optik form getirin; salon: üst kat">
    @error('note')<span class="invalid-feedback">{{ $message }}</span>@enderror
    <small class="text-muted">Öğrenci ve veli panelinde hatırlatıcıda görünür.</small>
</div>

{{-- Serbest deneme (Dalga 30a): ogrenci tarihi kendisi secer. --}}
<div class="form-check mb-2">
    <input type="hidden" name="is_flexible" value="0">
    <input type="checkbox" id="is_flexible" name="is_flexible" value="1" class="form-check-input"
        @checked(old('is_flexible', $exam?->is_flexible))>
    <label for="is_flexible" class="form-check-label">Serbest: öğrenci tarihi kendisi seçer (Tarih = ilk gün)</label>
</div>
<div class="form-group">
    <label for="available_until" class="form-label">Serbest ise son gün</label>
    <input type="date" id="available_until" name="available_until"
        class="form-control @error('available_until') is-invalid @enderror"
        value="{{ old('available_until', $exam?->available_until?->toDateString()) }}">
    @error('available_until')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>
