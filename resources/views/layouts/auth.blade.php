<!DOCTYPE html>
<html lang="tr">

{{--
    Giris sayfalari ve hata sayfalari (errors/*) bu duzeni kullanir. Hata
    sayfasi oturum baslamadan da cizilebilir (bilinmeyen adres, bakim): burada
    veritabanina ya da giris yapmis kullaniciya dayanan hicbir sey yok.
--}}
@php
    // Surum icerikten; ayrintisi layouts/app'te.
    $surumlu = fn (string $yol) => asset($yol) . (is_file($dosya = public_path($yol)) ? '?v=' . substr(md5_file($dosya), 0, 12) : '');
@endphp

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Giriş - Kral Kafe')</title>

    {{-- Yazi tipi sistemden (app.css --font-sans); ucuncu taraf font yok. --}}
    <link rel="stylesheet" href="{{ $surumlu('css/app.css') }}">
    {{-- Cift gonderim kilidi: sifre sifirlama e-postasi iki kez gitmesin. --}}
    <script src="{{ $surumlu('js/kabuk.js') }}" defer></script>

    <style>
        .auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
        }

        .auth-container {
            width: 100%;
            max-width: 420px;
        }

        .auth-logo {
            text-align: center;
            margin-bottom: 2rem;
        }

        .auth-logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 40px -10px rgba(99, 102, 241, 0.5);
        }

        .auth-logo-text {
            font-size: 1.75rem;
            font-weight: 700;
            color: white;
        }

        .auth-card {
            background: white;
            border-radius: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            padding: 2.5rem;
        }

        .auth-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .auth-subtitle {
            font-size: 0.9375rem;
            color: var(--gray-500);
            text-align: center;
            margin-bottom: 2rem;
        }
    </style>
</head>

<body>
    <div class="auth-page">
        <div class="auth-container">
            <div class="auth-logo">
                <div class="auth-logo-icon" aria-hidden="true">☕</div>
                <div class="auth-logo-text">Kral Kafe</div>
            </div>

            <div class="auth-card animate-slide-up">
                {{-- Suresi dolan sayfadan (419) donuste mesaj burada; giris
                     ekraninin kendisi yalnizca 'status' gosteriyor. --}}
                @if(session('error'))
                    <div class="alert alert-danger mb-3" role="alert"><span aria-hidden="true">❌</span> {{ session('error') }}</div>
                @endif

                @yield('content')
            </div>
        </div>
    </div>
</body>

</html>