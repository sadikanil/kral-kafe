@extends('layouts.app')

@section('title', $summary['student']->name . ' - Kral Kafe')
@section('page-title', $summary['student']->name)

@section('topbar-actions')
    <a href="{{ route('parent.report', $summary['student']) }}" class="btn btn-sm btn-primary">Haftalık rapor</a>
    <a href="{{ route('parent.payments', $summary['student']) }}" class="btn btn-sm btn-secondary">Ödemeler</a>
    <a href="{{ route('parent.dashboard') }}" class="btn btn-sm btn-secondary">← Çocuklarım</a>
@endsection

@section('content')
    @if($lessons !== [])
        <div class="card mb-3">
            <div class="card-header"><h4>👨‍🏫 Özel dersler · önümüzdeki 2 hafta</h4></div>
            <div class="card-body">
                @foreach($lessons as $ders)
                    <div class="mb-1">
                        {{ \Illuminate\Support\Carbon::parse($ders['date'])->locale('tr')->translatedFormat('d M D') }}
                        {{ $ders['starts_at'] }}–{{ $ders['ends_at'] }}
                        @if($ders['status'] === 'cancelled')<span class="badge badge-danger">İptal</span>@endif
                        @if($ders['status'] === 'moved')<span class="badge badge-warning">Taşındı</span>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{--
        Calisma plani (Dalga 30c): kocun takvimi, salt okunur. Veli hem
        maddeleri hem durumlarini gorur (karar 2); okul, ozel ders ve
        denemeler ayni takvimde.
    --}}
    @include('_plan-takvimi', [
        'mode' => 'parent',
        'days' => $planDays,
        'navUrl' => fn ($h) => route('parent.student', [$summary['student'], 'hafta' => $h]),
    ])

    @include('_calisma-kayitlari')

    @include('_zayif-konular')

    @include('_koc-notlari')

    @include('_deneme-sonuclari')

    @php
        use App\Support\Duration;
        $tz = config('kafe.timezone');
        $gunAdlari = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
    @endphp

    <div class="card mb-3">
        <div class="card-body">
            @include('parent._ozet', ['summary' => $summary])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h4>Son {{ count($days) }} gün</h4>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                @foreach($days as $gun)
                    @php $carbon = \Illuminate\Support\Carbon::parse($gun['date']); @endphp
                    <div class="text-center rounded p-2 {{ $gun['attended'] ? 'shadow' : '' }}"
                        style="min-width: 58px; {{ $gun['attended'] ? 'background: var(--success, #16a34a); color: #fff;' : 'background: var(--gray-100, #f3f4f6); color: var(--gray-500, #6b7280);' }}"
                        title="{{ $carbon->format('d.m.Y') }}">
                        <div style="font-size: 0.75rem;">{{ $gunAdlari[$carbon->dayOfWeekIso - 1] }}</div>
                        <div><strong>{{ $carbon->format('d') }}</strong></div>
                        <div style="font-size: 0.75rem;">{{ $gun['attended'] ? Duration::human($gun['minutes']) : '—' }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h4>Geliş ve çıkışlar</h4>
        </div>
        <div class="card-body p-0">
            @if($sessions->isEmpty())
                <div class="p-4 text-center text-muted">Henüz kayıtlı bir çalışma yok.</div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Geliş</th>
                                <th>Çıkış</th>
                                <th>Süre</th>
                                <th>Masa</th>
                                <th>Not</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sessions as $oturum)
                                <tr>
                                    <td>{{ $oturum->started_at->timezone($tz)->format('d.m.Y') }}</td>
                                    <td>{{ $oturum->started_at->timezone($tz)->format('H:i') }}</td>
                                    <td>
                                        @if($oturum->ended_at)
                                            {{ $oturum->ended_at->timezone($tz)->format('H:i') }}
                                        @else
                                            <span class="badge badge-success">Sürüyor</span>
                                        @endif
                                    </td>
                                    <td>{{ Duration::human($oturum->minutesSoFar()) }}</td>
                                    <td>{{ $oturum->table->name }}</td>
                                    <td class="text-muted">
                                        @if($oturum->end_reason && $oturum->end_reason !== \App\Enums\SessionEndReason::Manual)
                                            {{ $oturum->end_reason->label() }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
