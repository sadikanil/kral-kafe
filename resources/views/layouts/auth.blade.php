<!DOCTYPE html>
<html lang="tr">

{{--
    Giris sayfalari ve hata sayfalari (errors/*) bu duzeni kullanir. Hata
    sayfasi oturum baslamadan da cizilebilir (bilinmeyen adres, bakim): burada
    veritabanina ya da giris yapmis kullaniciya dayanan hicbir sey yok.
    Stiller app.css'te (auth-*).
--}}

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Giriş - Kral Kafe')</title>

    {{-- Yazi tipi sistemden (app.css --font-sans); ucuncu taraf font yok. --}}
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">
    {{-- Cift gonderim kilidi: sifre sifirlama e-postasi iki kez gitmesin. --}}
    <script src="{{ \App\Support\Asset::url('js/kabuk.js') }}" defer></script>
</head>

<body>
    <main class="auth-page">
        <div class="auth-container">
            <div class="auth-logo">
                @include('layouts._marka', ['sinif' => 'auth-logo-icon'])
            </div>

            <div class="auth-card animate-slide-up">
                {{-- Suresi dolan sayfadan (419) donuste mesaj burada; giris
                     ekraninin kendisi yalnizca 'status' gosteriyor. --}}
                @if(session('error'))
                    <div class="alert alert-danger mb-3" role="alert">{{ session('error') }}</div>
                @endif

                @yield('content')
            </div>
        </div>
    </main>
</body>

</html>
