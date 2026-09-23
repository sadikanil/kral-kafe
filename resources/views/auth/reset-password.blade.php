@extends('layouts.auth')

@section('title', 'Şifre Belirle - Kral Kafe')

@section('content')
    <h1 class="auth-title">Yeni Şifre Belirle</h1>
    <p class="auth-subtitle">Hesabınız için yeni bir şifre oluşturun.</p>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div class="form-group">
            <label for="email" class="form-label">E-posta Adresi</label>
            <input
                type="email"
                id="email"
                name="email"
                autocomplete="username"
                class="form-control @error('email') is-invalid @enderror"
                value="{{ old('email', $email) }}"
                required
                autofocus
            >
            @error('email')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Yeni Şifre</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="new-password"
                class="form-control @error('password') is-invalid @enderror"
                placeholder="••••••••"
                required
            >
            @error('password')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>

        <div class="form-group">
            <label for="password_confirmation" class="form-label">Yeni Şifre (Tekrar)</label>
            <input
                type="password"
                id="password_confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                class="form-control"
                placeholder="••••••••"
                required
            >
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">
            Şifreyi Güncelle
        </button>
    </form>

    <p class="text-center mt-3 mb-0">
        <a href="{{ route('login') }}">← Giriş sayfasına dön</a>
    </p>
@endsection
