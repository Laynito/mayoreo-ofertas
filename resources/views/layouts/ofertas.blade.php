<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Ofertas') – Cazador De Precios</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        .ofertas-page * { box-sizing: border-box; margin: 0; padding: 0; }
        .ofertas-page {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            min-height: 100vh;
            background: #0f0f0f;
            color: #f5f5f5;
        }
        .ofertas-page .header {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #0f0f0f;
            border-bottom: 1px solid #2a2a2a;
            padding: 0.75rem 1rem;
        }
        .ofertas-page .header-inner {
            max-width: 56rem;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .ofertas-page .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.125rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
            letter-spacing: -0.02em;
        }
        .ofertas-page .logo:hover { color: #fbbf24; }
        .ofertas-page .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 50%;
            overflow: hidden;
            background: #000;
            flex-shrink: 0;
        }
        .ofertas-page .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center;
            display: block;
        }
        @media (min-width: 640px) {
            .ofertas-page .logo-icon {
                width: 4rem;
                height: 4rem;
            }
            .ofertas-page .logo { font-size: 1.25rem; }
        }
        .ofertas-page .nav {
            display: flex;
            gap: 0.25rem;
        }
        .ofertas-page .nav a {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            color: #a3a3a3;
            background: transparent;
            transition: color 0.15s, background 0.15s;
        }
        .ofertas-page .nav a:hover {
            color: #fff;
            background: #262626;
        }
        .ofertas-page .nav a.active {
            color: #0f0f0f;
            background: #fbbf24;
        }
        .ofertas-page main {
            max-width: 56rem;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem;
        }
        .ofertas-page .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            letter-spacing: -0.02em;
        }
        .ofertas-page .page-subtitle {
            font-size: 0.9375rem;
            color: #a3a3a3;
            margin-bottom: 1rem;
        }
        .ofertas-page .search-form {
            margin-bottom: 1.5rem;
        }
        .ofertas-page .search-label {
            display: block;
            font-size: 0.8125rem;
            color: #a3a3a3;
            margin-bottom: 0.5rem;
        }
        .ofertas-page .search-row {
            display: flex;
            gap: 0.5rem;
            max-width: 24rem;
        }
        .ofertas-page .search-input {
            flex: 1;
            padding: 0.625rem 0.875rem;
            border-radius: 0.5rem;
            border: 1px solid #2a2a2a;
            background: #1a1a1a;
            color: #f5f5f5;
            font-size: 1rem;
        }
        .ofertas-page .search-input::placeholder { color: #737373; }
        .ofertas-page .search-input:focus {
            outline: none;
            border-color: #fbbf24;
        }
        .ofertas-page .search-btn {
            padding: 0.625rem 1rem;
            border-radius: 0.5rem;
            border: none;
            background: #fbbf24;
            color: #0f0f0f;
            font-weight: 600;
            font-size: 0.9375rem;
            cursor: pointer;
        }
        .ofertas-page .search-btn:hover { background: #fcd34d; }
        .ofertas-page .card-numero {
            position: absolute;
            top: 0.5rem;
            left: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            background: rgba(0,0,0,0.7);
            font-size: 0.75rem;
            font-weight: 600;
            color: #fbbf24;
            z-index: 1;
        }
        .ofertas-page .card { position: relative; }
        .ofertas-page .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
        }
        @media (min-width: 640px) {
            .ofertas-page .grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 1.25rem;
            }
        }
        .ofertas-page .card {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 0.75rem;
            overflow: hidden;
            transition: border-color 0.15s, transform 0.15s;
        }
        .ofertas-page .card:hover {
            border-color: #404040;
            transform: translateY(-2px);
        }
        .ofertas-page .card a.card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .ofertas-page .card img {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            background: #262626;
        }
        .ofertas-page .card .body { padding: 0.75rem; }
        .ofertas-page .card .nombre {
            font-size: 0.8125rem;
            font-weight: 500;
            line-height: 1.35;
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            color: #e5e5e5;
        }
        .ofertas-page .card .precio {
            font-size: 1rem;
            font-weight: 700;
            color: #fbbf24;
        }
        .ofertas-page .card .meta {
            font-size: 0.75rem;
            color: #737373;
            margin-top: 0.25rem;
        }
        .ofertas-page .card .btn-ver {
            display: block;
            width: 100%;
            margin-top: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            text-align: center;
            background: #fbbf24;
            color: #0f0f0f;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: background 0.15s, filter 0.15s;
        }
        .ofertas-page .card .btn-ver:hover {
            background: #fcd34d;
            filter: brightness(1.05);
        }
        .ofertas-page .empty {
            text-align: center;
            padding: 3rem 1rem;
            color: #737373;
            font-size: 1rem;
        }
        .ofertas-page .empty .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #a3a3a3;
            margin-bottom: 0.5rem;
        }
        .ofertas-page .pagination {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .ofertas-page .pagination a,
        .ofertas-page .pagination span {
            padding: 0.5rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            color: #e5e5e5;
        }
        .ofertas-page .pagination a:hover {
            background: #262626;
            border-color: #404040;
            color: #fff;
        }
        .ofertas-page .pagination span {
            background: #262626;
            color: #a3a3a3;
            border-color: #404040;
        }
    </style>
    @stack('styles')
</head>
<body class="ofertas-page">
    <header class="header">
        <div class="header-inner">
            <a href="{{ route('ofertas.dia') }}" class="logo">
                <span class="logo-icon">
                    <img src="{{ asset('logo/logo.png') }}" alt="Cazador De Precios" width="64" height="64">
                </span>
                <span>Cazador De Precios</span>
            </a>
            <nav class="nav">
                <a href="{{ route('ofertas.dia') }}" class="{{ request()->routeIs('ofertas.dia') ? 'active' : '' }}">Ofertas del día</a>
                <a href="{{ route('ofertas.todas') }}" class="{{ request()->routeIs('ofertas.todas') ? 'active' : '' }}">Todas las ofertas</a>
            </nav>
        </div>
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
