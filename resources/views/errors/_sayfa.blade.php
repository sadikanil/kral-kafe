{{--
    Hata sayfalarinin ortak govdesi (Faz 2 / H). Laravel'in varsayilanlari
    Ingilizce ve baska gorunumdeydi. Kod dosyalari (404, 500...) yalnizca
    metni verir: $kod, $baslik, $mesaj, $yenile (yeniden dene dugmesi).

    Sayfa oturum baslamadan da cizilebilir (bilinmeyen adres, bakim); burada
    giris yapmis kullaniciya ya da veritabanina dayanan bir sey olmamali.
--}}
@php
    // Yeniden denemek GET'te ayni adres; POST'ta (suresi dolan form) formu
    // yeniden gondermek ayni hatayi verir, formun sayfasina donulur.
    $yenileAdresi = request()->isMethod('GET') ? request()->fullUrl() : url()->previous();
@endphp

@extends('layouts.auth')

@section('title', $baslik . ' - Kral Kafe')

@section('content')
    <h1 class="auth-title">{{ $baslik }}</h1>
    <p class="auth-subtitle">{{ $mesaj }}</p>

    @if($yenile ?? false)
        <a href="{{ $yenileAdresi }}" class="btn btn-primary btn-block">{{ $yenile }}</a>
    @endif
    @unless($kod === 503)
        <a href="{{ route('home') }}" class="btn {{ ($yenile ?? false) ? 'btn-secondary mt-3' : 'btn-primary' }} btn-block">Ana sayfaya dön</a>
    @endunless

    <p class="text-muted text-center mt-3">Hata kodu: {{ $kod }}</p>
@endsection
