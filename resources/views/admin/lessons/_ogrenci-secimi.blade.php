{{--
    Coklu ogrenci secimi (1 Ekim 2026): arama kutusu + onay kutulari.
    Beklenen: $students, $onek (id onegi, iki form ayni sayfada).
--}}
<fieldset class="form-group lesson-picker">
    <legend class="form-label">Öğrenciler</legend>
    <input type="search" class="form-control mb-2 js-ogrenci-ara" placeholder="Ada göre ara" aria-label="Öğrenci ara"
           data-liste="{{ $onek }}-liste">
    <div id="{{ $onek }}-liste" class="lesson-student-list">
        @foreach($students as $ogrenci)
            <div class="form-check js-ogrenci" data-ara="{{ \Illuminate\Support\Str::lower($ogrenci->name) }}">
                <input type="checkbox" class="form-check-input" id="{{ $onek }}-{{ $ogrenci->id }}" name="student_ids[]" value="{{ $ogrenci->id }}"
                    @checked(in_array($ogrenci->id, array_map('intval', (array) old('student_ids', [])), true) && old('_form') === $onek)>
                <label class="form-check-label" for="{{ $onek }}-{{ $ogrenci->id }}">
                    {{ $ogrenci->name }}@if($ogrenci->gradeEnum())<span class="text-muted"> · {{ $ogrenci->gradeEnum()->label() }}</span>@endif
                </label>
            </div>
        @endforeach
    </div>
    @error('student_ids')<p class="text-danger mb-0">{{ $message }}</p>@enderror
</fieldset>
