@extends('layouts.app')

@section('title', $report->title . ' - Kral Kafe')
@section('page-title', $report->title)

@section('page-actions')
    <a href="{{ route('user.exam-reports.pdf', $report) }}" class="btn btn-secondary btn-sm" target="_blank">PDF'i aç</a>
    <a href="{{ route('user.exam-reports.index') }}" class="btn btn-secondary btn-sm">← Raporlarım</a>
@endsection

@section('content')
    @if($report->examEvent)
        <p class="text-muted">Takvimdeki deneme: {{ $report->examEvent->title }} · {{ $report->examEvent->dateLabel() }}</p>
    @endif

    @include('exam-reports._analiz', ['report' => $report])
@endsection
