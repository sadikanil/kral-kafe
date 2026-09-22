@extends('layouts.user')

@section('title', 'Haftalık Raporum - Kral Kafe')
@section('page-title', 'Haftalık Raporum')

@section('content')
    <div class="alert alert-info mb-3">
        💡 Bu rapor velinle de paylaşılıyor. Gördüğün her şeyi o da görüyor.
    </div>

    @include('_haftalik-rapor')
@endsection
