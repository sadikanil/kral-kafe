@extends('layouts.app')

@section('title', $student->name . ' - Haftalık Rapor')
@section('page-title', $student->name . ' · Haftalık Rapor')

@section('topbar-actions')
    <a href="{{ route('parent.student', $student) }}" class="btn btn-sm btn-secondary">← Ayrıntı</a>
@endsection

@section('content')
    @include('_haftalik-rapor')
@endsection
