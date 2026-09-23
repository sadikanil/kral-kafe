@extends('layouts.app')

@section('title', $student->name . ' - Çalışma Planı')
@section('page-title', $student->name)

@section('topbar-actions')
    <a href="{{ route('coach.notes.index', $student) }}" class="btn btn-sm btn-secondary">Notlar</a>
    <a href="{{ route('coach.report', $student) }}" class="btn btn-sm btn-secondary">Rapor</a>
    <a href="{{ route('coach.topics.index', $student) }}" class="btn btn-sm btn-secondary">Konular</a>
    <a href="{{ route('coach.plan.index') }}" class="btn btn-sm btn-secondary">← Öğrenciler</a>
@endsection

@section('content')

    @php
        use App\Enums\PlanPeriod;
        $tamamlanan = $maddeler->where('status', 'done')->count();
    @endphp

    {{--
        Donem secimi ve kaydirma. Ikisi de adres cubugundan gidiyor
        (GET baglantisi): koc bir donemi acik birakip sayfayi yenileyebilmeli
        ve baglantiyi paylasabilmeli.
    --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between gap-2" style="flex-wrap: wrap;">
                <div class="d-flex gap-2">
                    @foreach(PlanPeriod::cases() as $secenek)
                        <a href="{{ route('coach.plan.show', [$student, 'donem' => $secenek->value]) }}"
                           class="btn btn-sm {{ $donem === $secenek ? 'btn-primary' : 'btn-secondary' }}">
                            {{ $secenek->label() }}
                        </a>
                    @endforeach
                </div>

                <div class="d-flex align-items-center gap-2">
                    <a href="{{ route('coach.plan.show', [$student, 'donem' => $donem->value, 'baslangic' => $donem->shift($baslangic, -1)]) }}"
                       class="btn btn-sm btn-secondary">←</a>
                    <strong>{{ $donem->titleFor($baslangic) }}</strong>
                    <a href="{{ route('coach.plan.show', [$student, 'donem' => $donem->value, 'baslangic' => $donem->shift($baslangic, 1)]) }}"
                       class="btn btn-sm btn-secondary">→</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>{{ $donem->label() }} plan</h4>
            @if($maddeler->isNotEmpty())
                <span class="badge {{ $tamamlanan === $maddeler->count() ? 'badge-success' : 'badge-info' }}">
                    {{ $tamamlanan }} / {{ $maddeler->count() }}
                </span>
            @endif
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('coach.plan.store', $student) }}"
                  class="d-flex align-items-center gap-2 mb-3" style="flex-wrap: wrap;">
                @csrf

                {{-- Donem ve baslangic GIZLI alanlarda: koc hangi donemi
                     aciksa madde oraya dusmeli. Sunucuda bugunun donemine
                     varsayilmasi, gelecek haftaya plan yazmayi imkansiz
                     kilardi. --}}
                <input type="hidden" name="period" value="{{ $donem->value }}">
                <input type="hidden" name="baslangic" value="{{ $baslangic }}">

                <input type="text" name="title" class="form-control" placeholder="Yapılacak"
                       maxlength="150" required value="{{ old('title') }}">

                <select name="subject_id" class="form-control">
                    <option value="">Ders (isteğe bağlı)</option>
                    @foreach($dersler as $ders)
                        <option value="{{ $ders->id }}" @selected(old('subject_id') == $ders->id)>{{ $ders->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-primary">Ekle</button>
            </form>

            @forelse($maddeler as $madde)
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <div>
                        <strong>{{ $madde->title }}</strong>
                        <div class="text-muted">
                            {{ $madde->subject?->name ?? 'Genel' }}
                            @if($madde->status === 'done')
                                · tamamlandı
                                @if($madde->completed_at)
                                    {{ $madde->completed_at->timezone(config('kafe.timezone'))->format('d.m H:i') }}
                                @endif
                            @endif
                        </div>
                    </div>

                    <form method="POST" action="{{ route('coach.plan.destroy', $madde) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                    </form>
                </div>
            @empty
                <p class="text-muted mb-0">Bu dönem için madde yok.</p>
            @endforelse
        </div>
    </div>

    @include('_calisma-kayitlari')
@endsection
