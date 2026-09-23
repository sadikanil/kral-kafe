@extends('layouts.auth')

@section('title', 'Giriş Yap - Kral Kafe')

{{--
    Dalga 18: uc adim, tek sayfa.
      kimlik  -> telefon (ya da e-posta) sorulur
      sifre   -> sifresi olan hesap
      belirle -> yonetici yeni ekledi, sifre hic yok
--}}
@php
    $eposta = str_contains((string) $kimlik, '@');
    $gorunen = $kimlik && ! $eposta ? \App\Support\Telefon::format($kimlik) : $kimlik;
@endphp

@section('content')
    @if($adim === 'belirle')
        <h1 class="auth-title">Hoş Geldiniz</h1>
        <p class="auth-subtitle">İlk girişiniz. Kendinize bir şifre belirleyin.</p>
    @else
        <h1 class="auth-title">Hoş Geldiniz</h1>
        <p class="auth-subtitle">Devam etmek için giriş yapın</p>
    @endif

    @if(session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ $adim === 'belirle' ? route('login.set-password') : route('login') }}">
        @csrf

        @if($adim === 'kimlik')
            {{--
                type="tel" telefon tus takimini acar ve orada harf ya da '@'
                yok; yalnizca e-postali hesap (koc, yonetici) telefondan
                giremiyordu. Duz metin + inputmode ayni klavyeyi verir, e-posta
                klavyesi baglantiyla acilir. Mod hata donusunde gizli alandan
                geri gelir.
            --}}
            @if($epostaIle)
                <input type="hidden" name="ile" value="eposta">
            @endif

            <div class="form-group">
                <label for="kimlik" class="form-label">{{ $epostaIle ? 'E-posta adresi' : 'Telefon numarası' }}</label>
                <input
                    type="text"
                    id="kimlik"
                    name="kimlik"
                    inputmode="{{ $epostaIle ? 'email' : 'tel' }}"
                    autocomplete="username"
                    autocapitalize="off"
                    spellcheck="false"
                    class="form-control @error('kimlik') is-invalid @enderror"
                    value="{{ $kimlik }}"
                    placeholder="{{ $epostaIle ? 'ornek@eposta.com' : '05XX XXX XX XX' }}"
                    required
                    autofocus
                >
                @error('kimlik')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">Devam</button>

            <p class="text-center mt-3 mb-0">
                @if($epostaIle)
                    <a href="{{ route('login') }}">Telefonla gir</a>
                @else
                    <a href="{{ route('login', ['ile' => 'eposta']) }}">E-posta ile gir</a>
                @endif
            </p>
        @else
            <input type="hidden" name="adim" value="{{ $adim }}">

            {{--
                Kimlik gizli alan DEGIL: sifre yoneticileri kullanici adini
                sifreyle ayni formdaki gorunur alandan okur. Gizli alanla sifreyi
                adsiz kaydediyor, sonraki giriste dolduramiyordu. Gonderilen
                bicimli numara (0532 123 45 67) da Telefon::normalize'dan gecer.
            --}}
            <div class="form-group">
                <label for="kimlik" class="form-label">{{ $eposta ? 'E-posta adresi' : 'Telefon numarası' }}</label>
                <div class="form-control" style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                    <input
                        type="text"
                        id="kimlik"
                        name="kimlik"
                        value="{{ $gorunen }}"
                        autocomplete="username"
                        readonly
                        style="flex:1;min-width:0;border:0;padding:0;background:transparent;font:inherit;color:inherit"
                    >
                    <a href="{{ route('login') }}" class="form-check-link">Değiştir</a>
                </div>
                @error('kimlik')
                    <span class="invalid-feedback" style="display:block">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="password" class="form-label">{{ $adim === 'belirle' ? 'Yeni şifre' : 'Şifre' }}</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="{{ $adim === 'belirle' ? 'new-password' : 'current-password' }}"
                    class="form-control @error('password') is-invalid @enderror"
                    placeholder="••••••••"
                    required
                    autofocus
                >
                @error('password')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror
            </div>

            @if($adim === 'belirle')
                <div class="form-group">
                    <label for="password_confirmation" class="form-label">Yeni şifre (tekrar)</label>
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        autocomplete="new-password"
                        class="form-control"
                        placeholder="••••••••"
                        required
                    >
                    <span class="text-muted" style="font-size:.85rem">En az 6 karakter.</span>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">Şifreyi kaydet ve gir</button>
            @else
                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" id="remember" name="remember" class="form-check-input" checked>
                        <label for="remember" class="form-check-label">Beni hatırla</label>
                        <span class="form-check-link text-muted">Şifreni unuttuysan yöneticiye söyle</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">Giriş Yap</button>
            @endif
        @endif
    </form>
@endsection
