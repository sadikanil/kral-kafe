@extends('layouts.app')

@section('title', 'Deneme Takvimi - Kral Kafe')
@section('page-title', 'Deneme Takvimi')

@section('content')
    @php $calendarRoute = request()->route()->getName(); @endphp

    @include('exams._hatirlatici', ['upcomingExams' => $upcoming->take(3)])

    <div class="card mb-3">
        <div class="card-body">
            @include('exams._takvim')
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h4>Yaklaşan denemeler</h4></div>
        <div class="card-body p-0">
            @if($upcoming->isEmpty())
                <div class="p-4 text-center text-muted">Planlanmış deneme yok.</div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Tarih</th><th>Saat</th><th>Deneme</th><th>Tür</th><th>Kalan</th><th>Not</th></tr>
                        </thead>
                        <tbody>
                            @foreach($upcoming as $deneme)
                                <tr>
                                    <td>{{ $deneme->dateLabel() }}</td>
                                    <td>{{ $deneme->starts_at ?? '—' }}</td>
                                    <td><strong>{{ $detay ? $deneme->title : 'Deneme' }}</strong></td>
                                    <td><span class="badge badge-{{ $deneme->exam_type->badgeClass() }}">{{ $deneme->exam_type->label() }}</span></td>
                                    <td>{{ $deneme->countdownLabel() }}</td>
                                    <td class="text-muted">{{ $detay ? $deneme->note : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
