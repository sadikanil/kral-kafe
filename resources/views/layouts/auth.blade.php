<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Giriş - Kral Kafe')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">

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
                <div class="auth-logo-icon">☕</div>
                <div class="auth-logo-text">Kral Kafe</div>
            </div>

            <div class="auth-card animate-slide-up">
                @yield('content')
            </div>
        </div>
    </div>
</body>

</html>