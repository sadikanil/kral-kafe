{{-- Masa formunun istege bagli alanlari (7 Kasim 2026): bolum ve sabit uye. --}}
<div class="form-group">
    <label for="area" class="form-label">Bölüm</label>
    <input type="text" id="area" name="area" maxlength="40" list="js-bolumler" autocomplete="off"
        class="form-control @error('area') is-invalid @enderror"
        value="{{ old('area', $table->area ?? '') }}" placeholder="örn. Üst kat, Sessiz oda">
    <datalist id="js-bolumler">
        @foreach($areas as $bolum)
            <option value="{{ $bolum }}">
        @endforeach
    </datalist>
    @error('area')
        <span class="invalid-feedback">{{ $message }}</span>
    @enderror
    <small class="text-muted">İsteğe bağlı. Masalar listesi bölüme göre gruplanır.</small>
</div>

<div class="form-group">
    <label for="assigned_student_id" class="form-label">Sabit üye</label>
    <select id="assigned_student_id" name="assigned_student_id"
        class="form-control @error('assigned_student_id') is-invalid @enderror">
        <option value="">Yok — herkes oturabilir</option>
        @foreach($students as $uye)
            <option value="{{ $uye->id }}" @selected((string) old('assigned_student_id', $table->assigned_student_id ?? '') === (string) $uye->id)>{{ $uye->name }}</option>
        @endforeach
    </select>
    @error('assigned_student_id')
        <span class="invalid-feedback">{{ $message }}</span>
    @enderror
    <small class="text-muted">
        Masa bu üyeye ayrılır. Başkası okutursa "bu masa ayrılmış" uyarısı görür
        ama engellenmez; sabit üye gelmediği gün masa boş kalmaz.
    </small>
</div>
