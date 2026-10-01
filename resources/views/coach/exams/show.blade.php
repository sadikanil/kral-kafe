@extends('layouts.app')

@section('title', $event->title . ' - Deneme Analizi')
@section('page-title', $event->title)

@section('page-actions')
    <a href="{{ route('coach.exams.index') }}" class="btn btn-secondary btn-sm">← Deneme Analizi</a>
@endsection

{{--
    Bir denemede bakilabilen ogrenciler yan yana (1 Ekim 2026): toplam net,
    puan, kurum sirasi, ders netleri. Altta en cok eksik cikan konular -
    ortak calisma ya da ders planlamak icin. Koc ve yonetici ekrani; ogrenci
    ve veli birbirini gormez (README SS6.1-4).
--}}
@section('content')
    @php $sayi = fn ($n) => number_format((float) $n, 2, ',', '.'); @endphp

    <p class="text-muted mb-3">{{ $event->exam_date->format('d.m.Y') }} · {{ $results->count() }} öğrenci · en yüksek netten düşüğe</p>

    @if($results->isEmpty())
        <div class="card"><div class="card-body"><p class="text-muted mb-0">Bu denemede görebildiğin öğrencinin sonucu yok.</p></div></div>
    @else
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">{{ $sayi($results->avg(fn ($r) => $r->totalNet())) }}</div>
                <div class="stat-label">Ortalama net</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ $sayi($results->max(fn ($r) => $r->totalNet())) }}</div>
                <div class="stat-label">En yüksek net</div>
            </div>
            @php $puanlar = $results->pluck('score')->filter(fn ($p) => $p !== null); @endphp
            @if($puanlar->isNotEmpty())
                <div class="stat-card">
                    <div class="stat-value">{{ number_format((float) $puanlar->avg(), 3, ',', '.') }}</div>
                    <div class="stat-label">Ortalama puan</div>
                </div>
            @endif
        </div>

        <div class="card mb-3">
            <div class="card-header"><h4>Öğrenciler</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Öğrenci</th>
                                <th>Net</th>
                                <th>Puan</th>
                                <th>Kurum</th>
                                @foreach($subjects as $ders)<th>{{ $ders->name }}</th>@endforeach
                                <th>Eksik</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($results as $sonuc)
                                @php $netler = $sonuc->subjects->keyBy('subject_id'); @endphp
                                <tr>
                                    <td><a href="{{ route('coach.exams.result', $sonuc) }}">{{ $sonuc->student->name }}</a></td>
                                    <td><strong>{{ $sayi($sonuc->totalNet()) }}</strong></td>
                                    <td>{{ $sonuc->score !== null ? number_format((float) $sonuc->score, 3, ',', '.') : '—' }}</td>
                                    <td>{{ $sonuc->rank_institution ? $sonuc->rank_institution . '.' : '—' }}</td>
                                    @foreach($subjects as $ders)
                                        <td>{{ $netler->has($ders->id) ? $sayi($netler[$ders->id]->net) : '—' }}</td>
                                    @endforeach
                                    <td>{{ empty($sonuc->topics) ? '—' : count($sonuc->weakTopics()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if($commonWeak->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header"><h4>En çok eksik çıkan konular</h4></div>
                <div class="card-body p-0">
                    @foreach($commonWeak as $konu => $kisi)
                        <div class="list-row">
                            <span class="list-row-main"><span class="list-row-title">{{ $konu }}</span></span>
                            <span class="list-row-value">{{ $kisi }} öğrencide</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
@endsection
