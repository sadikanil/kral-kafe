@extends('layouts.coach')

@section('title', 'Çalışma Planı - Kral Kafe')
@section('page-title', 'Çalışma Planı')

@section('content')

    <div class="card mb-3">
        <div class="card-body">
            <p class="text-muted mb-0">
                Haftalık hedef <strong>ne kadar</strong>, plan <strong>ne</strong> sorusunu cevaplar.
                Öğrenci maddeleri kendi panelinden işaretler; velisi de aynı listeyi görür.
                Geçmiş dönemler değişmez — her dönem kendi maddelerini taşır.
            </p>
        </div>
    </div>

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
        <div class="card">
            <div class="card-header">
                <h4>Öğrenciler ({{ $ogrenciler->count() }})</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Öğrenci</th>
                                <th>Bu haftanın planı</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ogrenciler as $ogrenci)
                                @php
                                    // Maddesi olmayan ogrenci sayimda hic gorunmez;
                                    // onu "0/0" diye cizmek "plani var ama bos" demek
                                    // olurdu - dogrusu "plan yok".
                                    $sayim = $ilerleme[$ogrenci->id] ?? null;
                                @endphp
                                <tr>
                                    <td><strong>{{ $ogrenci->name }}</strong></td>
                                    <td>
                                        @if($sayim)
                                            <span class="badge {{ $sayim[0] === $sayim[1] ? 'badge-success' : 'badge-info' }}">
                                                {{ $sayim[0] }} / {{ $sayim[1] }}
                                            </span>
                                        @else
                                            <span class="text-muted">Plan yok</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('coach.plan.show', $ogrenci) }}" class="btn btn-sm btn-primary">
                                            Planı aç
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
