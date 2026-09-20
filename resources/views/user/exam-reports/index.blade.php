@extends('layouts.user')

@section('title', 'Deneme Raporlarım - Kral Kafe')
@section('page-title', 'Deneme Raporlarım')

@section('content')
    @if($reports->isEmpty())
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">📄</div>
                    <div class="empty-state-title">Henüz rapor yok</div>
                    <p class="text-muted">Deneme sonuç belgelerin kafe yönetimi tarafından buraya eklenir; yapay zeka analiziyle birlikte görürsün.</p>
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Tarih</th><th>Rapor</th><th>Deneme</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->created_at->timezone(config('kafe.timezone'))->format('d.m.Y') }}</td>
                                    <td><strong>{{ $report->title }}</strong></td>
                                    <td class="text-muted">{{ $report->examEvent?->title ?? '—' }}</td>
                                    <td><span class="badge badge-{{ $report->statusBadge() }}">{{ $report->statusLabel() }}</span></td>
                                    <td class="text-right">
                                        <a href="{{ route('user.exam-reports.show', $report) }}" class="btn btn-primary btn-sm">Analizi gör</a>
                                        <a href="{{ route('user.exam-reports.pdf', $report) }}" class="btn btn-secondary btn-sm" target="_blank">PDF</a>
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
