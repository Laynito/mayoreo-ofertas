<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} – Ofertas mayoristas</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        .welcome-page * { box-sizing: border-box; margin: 0; padding: 0; }
        .welcome-page {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fdfdfc;
            color: #1b1b18;
            padding: 1.5rem;
            text-align: center;
        }
        .welcome-page .logo {
            font-size: clamp(1.75rem, 5vw, 2.5rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
        }
        .welcome-page .tagline {
            font-size: 1rem;
            color: #706f6c;
            max-width: 28rem;
            line-height: 1.5;
            margin-bottom: 2rem;
        }
        .welcome-page .cta {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #1b1b18;
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            border-radius: 0.25rem;
            transition: opacity 0.15s;
        }
        .welcome-page .cta:hover { opacity: 0.9; }
    </style>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="welcome-page">
    <div class="logo">Mayoreo Cloud</div>
    <p class="tagline">Accede a las mejores ofertas mayoristas de México. Exclusivo para miembros.</p>
    <a href="{{ route('login') }}" class="cta">Entrar para ver Precios Bajos</a>
</body>
</html>
