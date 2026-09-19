@extends('layouts.auth')

@section('title', 'Giriş Yap - Kral Kafe')

@section('content')
    <h2 class="auth-title">Hoş Geldiniz</h2>
    <p class="auth-subtitle">Devam etmek için giriş yapın</p>

    @if(session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        
        <div class="form-group">
            <label for="email" class="form-label">E-posta Adresi</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
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
        
        <div class="form-group">
            <label for="password" class="form-label">Şifre</label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                class="form-control @error('password') is-invalid @enderror" 
                placeholder="••••••••"
                required
            >
            @error('password')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
        
        <div class="form-group">
            <div class="form-check">
                <input 
                    type="checkbox" 
                    id="remember" 
                    name="remember" 
                    class="form-check-input"
                    {{ old('remember') ? 'checked' : '' }}
                >
                <label for="remember" class="form-check-label">Beni hatırla</label>
                <a href="{{ route('password.request') }}" class="form-check-link">Şifremi unuttum</a>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary btn-block btn-lg">
            Giriş Yap
        </button>
    </form>
@endsection
