@extends('layouts.app')

@section('title', 'Deneme Sonucu - Kral Kafe')
@section('page-title', 'Deneme Sonucu')

@section('content')
    <div class="session-card mb-3">
        <strong>{{ $event->title }}</strong>
        <div class="text-muted">
            {{ $student->name }} ·
            {{ $event->exam_date->timezone(config('kafe.timezone'))->format('d.m.Y') }}
        </div>
    </div>

    @if($subjects->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">📚</div>
            <div class="empty-state-title">Önce ders tanımlamalısın</div>
            <p class="text-muted">Bu deneme türü için tanımlı ders yok; dersler müfredattan gelir.</p>
        </div>
    @else
        <form method="POST" action="{{ route('admin.exam-results.store', [$event, $student]) }}">
            @csrf

            <h2>Ders bazlı sonuç</h2>
            <p class="text-muted">Net otomatik hesaplanır: doğru − yanlış/4.</p>

            @foreach($subjects as $ders)
                @php $satir = $result?->subjects->firstWhere('subject_id', $ders->id); @endphp

                <div class="session-card mb-2">
                    <strong>{{ $ders->name }}</strong>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <input type="number" min="0" max="200" class="form-control"
                               name="subjects[{{ $ders->id }}][correct]" placeholder="Doğru"
                               value="{{ old("subjects.{$ders->id}.correct", $satir->correct ?? 0) }}" required>
                        <input type="number" min="0" max="200" class="form-control"
                               name="subjects[{{ $ders->id }}][wrong]" placeholder="Yanlış"
                               value="{{ old("subjects.{$ders->id}.wrong", $satir->wrong ?? 0) }}" required>
                        <input type="number" min="0" max="200" class="form-control"
                               name="subjects[{{ $ders->id }}][blank]" placeholder="Boş"
                               value="{{ old("subjects.{$ders->id}.blank", $satir->blank ?? 0) }}" required>
                    </div>
                </div>
            @endforeach

            <h2 class="mt-4">Sıralamalar</h2>
            <p class="text-muted">
                Boş bırakabilirsin — kurum sıralaması ertesi gün, Türkiye geneli
                bir hafta sonra açıklanabiliyor. Katılımcı sayısı olmadan sıra
                tek başına karşılaştırılamaz.
            </p>

            @foreach(['institution' => 'Kurum', 'district' => 'İlçe', 'city' => 'İl', 'country' => 'Türkiye'] as $alan => $etiket)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="text-muted">{{ $etiket }}</span>
                    <input type="number" min="1" class="form-control" name="rank_{{ $alan }}" placeholder="Sıra"
                           value="{{ old("rank_{$alan}", $result?->{"rank_{$alan}"}) }}">
                    <input type="number" min="1" class="form-control" name="total_{{ $alan }}" placeholder="Katılımcı"
                           value="{{ old("total_{$alan}", $result?->{"total_{$alan}"}) }}">
                </div>
            @endforeach

            <h2 class="mt-4">Değerlendirme</h2>
            <p class="text-muted">
                Yüklediğin PDF raporunun özetini okuyup buraya yaz. Bu not
                öğrencinin ve velisinin panelinde görünür.
            </p>
            <textarea name="note" class="form-control" rows="4" maxlength="1000">{{ old('note', $result?->note) }}</textarea>

            <button type="submit" class="btn btn-primary mt-3">Kaydet</button>
        </form>
    @endif
@endsection
