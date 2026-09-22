@extends('layouts.parent')

@section('title', $summary['student']->name . ' - Kral Kafe')
@section('page-title', $summary['student']->name)

@section('topbar-actions')
    <a href="{{ route('parent.report', $summary['student']) }}" class="btn btn-sm btn-primary">Haftalık rapor</a>
    <a href="{{ route('parent.dashboard') }}" class="btn btn-sm btn-secondary">← Çocuklarım</a>
@endsection

@section('content')

    {{--
        Calisma plani (Dalga 13; aylik donem Dalga 14).

        Veli hem ORANI hem MADDELERI gorur (karar 2): "3/5" tek basina
        velinin cocuguyla konusmasina yetmiyor, neyin yapilip neyin
        kaldigi da gorunmeli. Madde yoksa blok hic cizilmez - bos bir
        liste "plansiz" demez.
    --}}
    @foreach($planPeriods as $blok)
        @php $biten = $blok['items']->where('status', 'done')->count(); @endphp

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>{{ $blok['period']->titleFor($blok['start']) }} planı</h4>
                <span class="badge {{ $biten === $blok['items']->count() ? 'badge-success' : 'badge-info' }}">
                    {{ $biten }} / {{ $blok['items']->count() }}
                </span>
            </div>
            <div class="card-body">
                @foreach($blok['items'] as $madde)
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <strong>{{ $madde->title }}</strong>
                            <div class="text-muted">{{ $madde->subject?->name ?? 'Genel' }}</div>
                        </div>
                        @if($madde->status === 'done')
                            <span class="badge badge-success">Tamamlandı</span>
                        @else
                            <span class="badge badge-warning">Bekliyor</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

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
