@extends('layouts.app')

@section('title', $student->name . ' - Haftalık Rapor')
@section('page-title', $student->name)

@section('page-actions')
    <a href="{{ route('parent.dashboard') }}" class="btn btn-sm btn-secondary">← Çocuklarım</a>
@endsection

@section('content')
    @include('parent._sekmeler')

    @include('_haftalik-rapor')
@endsection
