@extends('layouts.auth')

@section('title', 'Şifremi Unuttum - Kral Kafe')

@section('content')
    <h1 class="auth-title">Şifremi Unuttum</h1>
    <p class="auth-subtitle">
        E-posta adresinizi girin, şifrenizi sıfırlamanız için size bir bağlantı gönderelim.
    </p>

    @if(session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="form-group">
            <label for="email" class="form-label">E-posta Adresi</label>
            <input
                type="email"
                id="email"
                name="email"
                autocomplete="email"
                class="form-control @error('email') is-invalid @enderror"
                value="{{ old('email') }}"
                placeholder="ornek@email.com"
                required
                autofocus
            >
            @error('email')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">
            Sıfırlama Bağlantısı Gönder
        </button>
    </form>

    <p class="text-center mt-3 mb-0">
        <a href="{{ route('login') }}">← Giriş sayfasına dön</a>
    </p>
@endsection
