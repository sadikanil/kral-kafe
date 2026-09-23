{{--
    Sinif ve alan (Dalga 30b) - ekleme ve duzenleme ayni parca. Alan 11.
    siniftan itibaren; 9-10'da secilse de kaydedilmez. Beklenen: $user
    (yeni kayitta null).
--}}
<div class="d-flex gap-2" style="flex-wrap: wrap;">
    <div class="form-group" style="flex: 1; min-width: 150px;">
        <label for="grade" class="form-label">Sınıf</label>
        <select id="grade" name="grade" class="form-control @error('grade') is-invalid @enderror">
            <option value="">Seçin…</option>
            @foreach(\App\Enums\Grade::cases() as $sinif)
                <option value="{{ $sinif->value }}" data-alan="{{ $sinif->hasField() ? 1 : 0 }}"
                    @selected(old('grade', $user?->grade) === $sinif->value)>{{ $sinif->label() }}</option>
            @endforeach
        </select>
        @error('grade')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
    <div class="form-group" style="flex: 1; min-width: 150px;" id="alanBolumu">
        <label for="field" class="form-label">Alan</label>
        <select id="field" name="field" class="form-control @error('field') is-invalid @enderror">
            <option value="">Seçin…</option>
            @foreach(\App\Enums\StudyField::cases() as $alan)
                <option value="{{ $alan->value }}" @selected(old('field', $user?->field) === $alan->value)>{{ $alan->label() }}</option>
            @endforeach
        </select>
        @error('field')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
</div>
<small class="text-muted d-block mb-3">Planlarda yalnızca öğrencinin sorumlu olduğu dersler çıkar. Alan 11. sınıftan itibaren.</small>

@push('scripts')
<script>
    (function () {
        const sinif = document.getElementById('grade');
        const alan = document.getElementById('alanBolumu');
        function guncelle() {
            const secili = sinif.options[sinif.selectedIndex];
            alan.style.display = secili && secili.dataset.alan === '0' ? 'none' : '';
        }
        sinif.addEventListener('change', guncelle);
        guncelle();
    })();
</script>
@endpush
