@extends('layouts.admin')

@section('title', 'Dersler - Kral Kafe')
@section('page-title', 'Dersler')

@section('content')
    <p class="text-muted">
        Deneme sonucu girişinde kullanılan ders listesi. Ders <strong>silinmez</strong>,
        kapatılır — silinseydi ona bağlı geçmiş sonuçlar da giderdi.
    </p>

    <div class="session-card mb-3">
        <strong>Yeni ders</strong>
        <form method="POST" action="{{ route('admin.subjects.store') }}" class="d-flex align-items-center gap-2 mt-2">
            @csrf
            <input type="text" name="name" class="form-control" placeholder="Ders adı" maxlength="60" required>
            <select name="exam_type" class="form-control" required>
                {{-- "Resmî Sınav" bir DERS turu degil: subjects.exam_type
                     dersin hangi sinavda ciktigini soyluyor. Dalga 15b'de
                     eklenen enum degerinin sessiz yan etkisi. --}}
                @foreach(array_filter(\App\Enums\ExamType::cases(), fn ($t) => $t->isPractice()) as $tur)
                    <option value="{{ $tur->value }}">{{ $tur->label() }}</option>
                @endforeach
            </select>
            <input type="number" name="sort_order" class="form-control" placeholder="Sıra" min="0" max="999">
            <button type="submit" class="btn btn-primary">Ekle</button>
        </form>
        @error('name') <p class="text-danger mt-2">{{ $message }}</p> @enderror
    </div>

    @forelse($subjects as $ders)
        <div class="session-card mb-2">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <strong>{{ $ders->name }}</strong>
                    <div class="text-muted">{{ strtoupper($ders->exam_type) }} · sıra {{ $ders->sort_order }}</div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    @unless($ders->is_active)
                        <span class="badge badge-warning">Kapalı</span>
                    @endunless

                    <form method="POST" action="{{ route('admin.subjects.toggle-status', $ders) }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary">
                            {{ $ders->is_active ? 'Kapat' : 'Aç' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <div class="empty-state-icon">📚</div>
            <div class="empty-state-title">Henüz ders yok</div>
            <p class="text-muted">Yukarıdan ekleyebilir ya da <code>php artisan db:seed --class=SubjectSeeder</code> ile standart listeyi yükleyebilirsin.</p>
        </div>
    @endforelse
@endsection
