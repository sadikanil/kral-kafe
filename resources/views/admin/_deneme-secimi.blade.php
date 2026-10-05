{{--
    Sonuc formlarinda deneme secimi (5 Ekim 2026). Serbest denemeler
    akis icinde kendiliginden cozuluyor: once onlar (penceresi kapanmis
    olanlar dahil) ayri grupta, sonra takvimdekiler; listede yoksa
    "Yeni serbest deneme" secilir ve ad/tur/gun ayni formda girilir
    (SpontaneousExam). Betik yokken alanlar acik kalir, sunucu yalnizca
    "yeni" secildiyse dogrular.

    Beklenen: $events (takvimdeki), $flexible (serbest). Istege bagli:
    $placeholder ("Seçin…"), $required (true).
--}}
@php
    $secili = (string) old('exam_event_id');
    $yeniSecili = $secili === \App\Support\SpontaneousExam::NEW || ($events->isEmpty() && $flexible->isEmpty());
    $zorunlu = $required ?? true;
@endphp
<div class="form-group">
    <label for="exam_event_id" class="form-label">Deneme{{ $zorunlu ? ' *' : '' }}</label>
    <select id="exam_event_id" name="exam_event_id" class="form-control js-deneme-secimi @error('exam_event_id') is-invalid @enderror" @required($zorunlu)>
        <option value="">{{ $placeholder ?? 'Seçin…' }}</option>
        <option value="{{ \App\Support\SpontaneousExam::NEW }}" @selected($yeniSecili)>+ Yeni serbest deneme oluştur</option>
        @if($flexible->isNotEmpty())
            <optgroup label="Serbest denemeler">
                @foreach($flexible as $deneme)
                    <option value="{{ $deneme->id }}" @selected($secili === (string) $deneme->id)>{{ \App\Support\SpontaneousExam::label($deneme) }}</option>
                @endforeach
            </optgroup>
        @endif
        @if($events->isNotEmpty())
            <optgroup label="Takvimdeki denemeler">
                @foreach($events as $deneme)
                    <option value="{{ $deneme->id }}" @selected($secili === (string) $deneme->id)>
                        {{ $deneme->exam_date->format('d.m.Y') }} · {{ $deneme->title }} ({{ $deneme->exam_type->label() }})
                    </option>
                @endforeach
            </optgroup>
        @endif
    </select>
    @error('exam_event_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
</div>

<fieldset class="spontaneous-exam js-yeni-deneme" @if(! $yeniSecili) hidden @endif>
    <legend>Yeni serbest deneme</legend>
    <div class="form-group">
        <label for="new_title" class="form-label">Deneme adı *</label>
        <input type="text" id="new_title" name="new_title" maxlength="100"
            class="form-control @error('new_title') is-invalid @enderror"
            value="{{ old('new_title') }}" placeholder="Örn. 345 Yayınları TYT 4">
        @error('new_title')<span class="invalid-feedback">{{ $message }}</span>@enderror
    </div>
    <div class="d-flex gap-2">
        <div class="form-group" style="flex: 1;">
            <label for="new_exam_type" class="form-label">Sınav türü *</label>
            <select id="new_exam_type" name="new_exam_type" class="form-control @error('new_exam_type') is-invalid @enderror">
                @foreach(\App\Support\SpontaneousExam::TYPES as $tur)
                    <option value="{{ $tur->value }}" @selected(old('new_exam_type', 'tyt') === $tur->value)>{{ $tur->label() }}</option>
                @endforeach
            </select>
            @error('new_exam_type')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>
        <div class="form-group" style="flex: 1;">
            <label for="new_exam_date" class="form-label">Çözüldüğü gün *</label>
            <input type="date" id="new_exam_date" name="new_exam_date" max="{{ \App\Support\LocalDay::today() }}"
                class="form-control @error('new_exam_date') is-invalid @enderror"
                value="{{ old('new_exam_date', \App\Support\LocalDay::today()) }}">
            @error('new_exam_date')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>
    </div>
    <small class="text-muted">Takvime serbest deneme olarak, yalnızca bu güne eklenir; öğrencilerin açık serbest denemeler listesinde sonradan durmaz.</small>
</fieldset>

@pushOnce('scripts')
<script>
    (function () {
        document.querySelectorAll('.js-deneme-secimi').forEach(function (secim) {
            const alan = secim.closest('form').querySelector('.js-yeni-deneme');
            if (!alan) { return; }

            function guncelle() {
                const yeni = secim.value === '{{ \App\Support\SpontaneousExam::NEW }}';
                alan.hidden = !yeni;
                alan.querySelectorAll('input, select').forEach(function (kutu) {
                    kutu.disabled = !yeni;
                    if (kutu.tagName === 'INPUT') { kutu.required = yeni; }
                });
            }

            secim.addEventListener('change', guncelle);
            guncelle();
        });
    })();
</script>
@endPushOnce
