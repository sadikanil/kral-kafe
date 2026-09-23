@extends('layouts.app')

@section('title', $student->name . ' - Çalışma Planı')
@section('page-title', $student->name)

@section('topbar-actions')
    <a href="{{ route('coach.notes.index', $student) }}" class="btn btn-sm btn-secondary">Notlar</a>
    <a href="{{ route('coach.report', $student) }}" class="btn btn-sm btn-secondary">Rapor</a>
    <a href="{{ route('coach.topics.index', $student) }}" class="btn btn-sm btn-secondary">Zayıf konular</a>
    <a href="{{ route('coach.plan.index') }}" class="btn btn-sm btn-secondary">← Öğrenciler</a>
@endsection

{{--
    Takvimli plan (Dalga 30c). Ust: tek ekleme formu (gunlerdeki "+"
    tarihi buraya yazar). Orta: haftalik takvim. Alt: haftalik sabit
    program (okul, dershane, distaki ozel ders).
--}}
@section('content')
    @if($student->gradeEnum())
        <p class="text-muted mb-2">
            {{ $student->gradeEnum()->label() }}@if($student->fieldEnum()) · {{ $student->fieldEnum()->label() }}@endif
            — yalnızca sorumlu olduğu dersler listelenir.
        </p>
    @endif

    <div class="card mb-3" id="planaEkle">
        <div class="card-header"><h4>➕ Plana ekle</h4></div>
        <div class="card-body">
            <form method="POST" action="{{ route('coach.plan.store', $student) }}" class="plan-form">
                @csrf
                <div class="form-group">
                    <label for="plan_date" class="form-label">Gün</label>
                    <input type="date" id="plan_date" name="plan_date" class="form-control @error('plan_date') is-invalid @enderror"
                           value="{{ old('plan_date', \App\Support\LocalDay::today() >= $hafta && \App\Support\LocalDay::today() <= $days[6]['date'] ? \App\Support\LocalDay::today() : $hafta) }}" required>
                </div>
                <div class="form-group">
                    <label for="subject_id" class="form-label">Ders</label>
                    <select id="subject_id" name="subject_id" class="form-control @error('subject_id') is-invalid @enderror">
                        <option value="">Seçin…</option>
                        @foreach($dersler->groupBy(fn ($d) => $d->exam_type) as $tur => $grup)
                            <optgroup label="{{ ['tyt' => 'TYT', 'ayt' => 'AYT', 'ydt' => 'YDT', 'okul' => 'Okul'][$tur] ?? strtoupper($tur) }}">
                                @foreach($grup as $ders)
                                    <option value="{{ $ders->id }}" @selected((int) old('subject_id') === $ders->id)>{{ $ders->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('subject_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="subject_topic_id" class="form-label">Konu</label>
                    <select id="subject_topic_id" name="subject_topic_id" class="form-control @error('subject_topic_id') is-invalid @enderror"
                            data-secili="{{ old('subject_topic_id') }}">
                        <option value="">Önce ders seçin</option>
                    </select>
                    @error('subject_topic_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="title" class="form-label">Not</label>
                    <input type="text" id="title" name="title" maxlength="150" class="form-control"
                           value="{{ old('title') }}" placeholder="Örn. 40 soru, tekrar">
                </div>
                <div class="form-group">
                    <label for="starts_at" class="form-label">Saat</label>
                    <input type="time" id="starts_at" name="starts_at" class="form-control" value="{{ old('starts_at') }}">
                </div>
                <div class="form-group">
                    <label for="duration_minutes" class="form-label">Süre (dk)</label>
                    <input type="number" id="duration_minutes" name="duration_minutes" min="5" max="720" step="5"
                           class="form-control" value="{{ old('duration_minutes') }}" placeholder="60">
                </div>
                <div class="form-group plan-form-submit">
                    <button type="submit" class="btn btn-primary btn-block">Ekle</button>
                </div>
            </form>
        </div>
    </div>

    @include('_plan-takvimi', [
        'mode' => 'coach',
        'navUrl' => fn ($h) => route('coach.plan.show', [$student, 'hafta' => $h]),
    ])

    {{-- Haftalik sabit program (Dalga 30c) --}}
    <div class="card mb-3">
        <div class="card-header"><h4>🏫 Haftalık sabit program</h4></div>
        <div class="card-body">
            <p class="text-muted">Okul, dershane, dışarıdaki özel ders… Takvimde her hafta dolu saat olarak görünür.</p>

            @if($commitments->isNotEmpty())
                <ul class="log-list mb-3">
                    @foreach($commitments as $program)
                        <li>
                            <span class="text-muted">{{ \Illuminate\Support\Carbon::create(2026, 9, 27)->addDays($program->weekday)->locale('tr')->translatedFormat('D') }}</span>
                            <span class="log-label">{{ $program->kind->icon() }} {{ $program->label() }} · {{ $program->starts_at }}–{{ $program->ends_at }}</span>
                            <form method="POST" action="{{ route('coach.commitments.destroy', $program) }}"
                                  onsubmit="return confirm('Bu satır silinsin mi?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-secondary" aria-label="Sil">✕</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('coach.commitments.store', $student) }}">
                @csrf
                <div class="d-flex gap-2" style="flex-wrap: wrap;">
                    <div class="form-group" style="min-width: 150px;">
                        <label for="kind" class="form-label">Tür</label>
                        <select id="kind" name="kind" class="form-control">
                            @foreach(\App\Enums\CommitmentKind::cases() as $tur)
                                <option value="{{ $tur->value }}" @selected(old('kind') === $tur->value)>{{ $tur->icon() }} {{ $tur->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 150px;">
                        <label for="commitment_title" class="form-label">Ad (isteğe bağlı)</label>
                        <input type="text" id="commitment_title" name="commitment_title" maxlength="100" class="form-control"
                               value="{{ old('commitment_title') }}" placeholder="Örn. Limit Dershanesi">
                    </div>
                    <div class="form-group">
                        <label for="c_starts" class="form-label">Başlangıç</label>
                        <input type="time" id="c_starts" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at', '08:00') }}" required>
                    </div>
                    <div class="form-group">
                        <label for="c_ends" class="form-label">Bitiş</label>
                        <input type="time" id="c_ends" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at', '15:00') }}" required>
                        @error('ends_at')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="d-flex gap-2 mb-2" style="flex-wrap: wrap;">
                    @foreach(['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $i => $gunAdi)
                        <label class="d-flex align-items-center gap-1">
                            <input type="checkbox" name="weekdays[]" value="{{ $i + 1 }}" @checked(in_array($i + 1, old('weekdays', [])))> {{ $gunAdi }}
                        </label>
                    @endforeach
                </div>
                @error('weekdays')<p class="text-danger">{{ $message }}</p>@enderror
                <button type="submit" class="btn btn-secondary">Programa ekle</button>
            </form>
        </div>
    </div>

    @include('_calisma-kayitlari')
@endsection

@push('scripts')
<script>
(function () {
    const konular = @json($konular);
    const ders = document.getElementById('subject_id');
    const konu = document.getElementById('subject_topic_id');

    function doldur() {
        const liste = konular[ders.value] || [];
        const secili = konu.dataset.secili;
        konu.innerHTML = '';
        konu.add(new Option(ders.value ? (liste.length ? 'Konu seçin (isteğe bağlı)' : 'Bu derste konu listesi yok') : 'Önce ders seçin', ''));
        liste.forEach(k => konu.add(new Option(k.name, k.id, false, String(k.id) === secili)));
        konu.disabled = liste.length === 0;
    }
    ders.addEventListener('change', () => { konu.dataset.secili = ''; doldur(); });
    doldur();

    // Gundeki "+" formun gununu ayarlar ve forma goturur.
    document.querySelectorAll('.js-gune-ekle').forEach(b => b.addEventListener('click', () => {
        document.getElementById('plan_date').value = b.dataset.gun;
        document.getElementById('planaEkle').scrollIntoView({ behavior: 'smooth' });
        ders.focus();
    }));
})();
</script>
@endpush
