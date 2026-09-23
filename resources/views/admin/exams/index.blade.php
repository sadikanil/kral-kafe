@extends('layouts.app')

@section('title', 'Deneme Takvimi - Kral Kafe')
@section('page-title', 'Deneme Takvimi')

@section('topbar-actions')
    <a href="{{ route('admin.exams.create') }}" class="btn btn-primary btn-sm">+ Yeni Deneme</a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            @include('exams._takvim', ['calendarRoute' => 'admin.exams.index', 'editable' => true])
        </div>
    </div>

    @include('exams._serbest', ['editable' => true])

    <div class="card mb-3">
        <div class="card-header"><h4>Yaklaşan denemeler</h4></div>
        <div class="card-body p-0">
            @if($upcoming->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <div class="empty-state-title">Planlanmış deneme yok</div>
                    <p class="text-muted">Eklenen denemeler öğrenci ve veli panellerinde hatırlatıcı olarak görünür.</p>
                    <a href="{{ route('admin.exams.create') }}" class="btn btn-primary btn-sm">+ Yeni Deneme</a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Tarih</th><th>Saat</th><th>Deneme</th><th>Tür</th><th>Kalan</th><th class="text-right">İşlem</th></tr>
                        </thead>
                        <tbody>
                            @foreach($upcoming as $deneme)
                                <tr>
                                    <td>{{ $deneme->dateLabel() }}</td>
                                    <td>{{ $deneme->starts_at ?? '—' }}</td>
                                    <td><strong>{{ $deneme->title }}</strong>@if($deneme->note)<br><small class="text-muted">{{ $deneme->note }}</small>@endif</td>
                                    <td><span class="badge badge-{{ $deneme->exam_type->badgeClass() }}">{{ $deneme->exam_type->label() }}</span></td>
                                    <td>{{ $deneme->countdownLabel() }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.exams.edit', $deneme) }}" class="btn btn-secondary btn-sm">Düzenle</a>
                                        <form action="{{ route('admin.exams.destroy', $deneme) }}" method="POST" class="d-inline-block"
                                            onsubmit="return confirm('Bu deneme takvimden kaldırılsın mı?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm">Kaldır</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if($past->isNotEmpty())
        <div class="card">
            <div class="card-header"><h4>Geçmiş denemeler</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Tarih</th><th>Deneme</th><th>Tür</th><th class="text-right">İşlem</th></tr></thead>
                        <tbody>
                            @foreach($past as $deneme)
                                <tr>
                                    <td>{{ $deneme->exam_date->format('d.m.Y') }}</td>
                                    <td>{{ $deneme->title }}</td>
                                    <td><span class="badge badge-{{ $deneme->exam_type->badgeClass() }}">{{ $deneme->exam_type->label() }}</span></td>
                                    <td class="text-right"><a href="{{ route('admin.exams.edit', $deneme) }}" class="btn btn-secondary btn-sm">Düzenle</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
