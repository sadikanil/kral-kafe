@extends('layouts.app')

@section('title', 'Çalışma Planı - Kral Kafe')
@section('page-title', 'Çalışma Planı')

@section('content')

    <p class="text-muted mb-3">
        Öğrenciyi seç, takvimde güne ders ve konu ekle. Öğrenci maddeleri Planım'da
        işaretler; velisi aynı takvimi görür.
    </p>

    @if($ogrenciler->isEmpty())
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">🗓️</div>
                    <div class="empty-state-title">Henüz öğrenciniz yok</div>
                    <p class="text-muted">
                        Koç–öğrenci ataması yönetim panelinden yapılır:
                        Kullanıcılar → öğrenci → Koçlar.
                    </p>
                </div>
            </div>
        </div>
    @else
        {{-- Telefonda tablo dort dugmeyi alt alta diziyordu. Satirin tamami
             plana goturur; notlar, rapor ve konular oradaki sekmelerde. --}}
        <div class="card">
            <div class="card-header">
                <h4>Öğrenciler ({{ $ogrenciler->count() }})</h4>
            </div>
            <div class="card-body p-0">
                @foreach($ogrenciler as $ogrenci)
                    @php
                        // Maddesi olmayan ogrenci sayimda hic gorunmez;
                        // onu "0/0" diye cizmek "plani var ama bos" demek
                        // olurdu - dogrusu "plan yok".
                        $sayim = $ilerleme[$ogrenci->id] ?? null;
                    @endphp
                    <a href="{{ route('coach.plan.show', $ogrenci) }}" class="student-row">
                        <span class="student-row-main">
                            <strong>{{ $ogrenci->name }}</strong>
                            @if($ogrenci->gradeEnum())
                                <span class="text-muted">
                                    {{ $ogrenci->gradeEnum()->label() }}@if($ogrenci->fieldEnum()) · {{ $ogrenci->fieldEnum()->label() }}@endif
                                </span>
                            @endif

                            {{-- Dusus sinyalleri (Dalga 16). Yorumsuz: yalnizca
                                 sayi ve olgu. Veli ve ogrenci bunlari GORMEZ (SS6.1-5). --}}
                            @foreach($sinyaller[$ogrenci->id] ?? [] as $sinyal)
                                <span class="badge badge-{{ $sinyal->severity }}">{{ $sinyal->label }}</span>
                            @endforeach
                        </span>

                        @if($sayim)
                            <span class="badge {{ $sayim[0] === $sayim[1] ? 'badge-success' : 'badge-info' }}">
                                {{ $sayim[0] }} / {{ $sayim[1] }}
                            </span>
                        @else
                            <span class="text-muted">Plan yok</span>
                        @endif
                        <span class="student-row-arrow" aria-hidden="true">›</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endsection
