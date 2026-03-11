<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if(!empty($verification_meta))
    {!! $verification_meta !!}
    @endif
    <title>@yield('title', 'Precios Bajos') – {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        .front-page * { box-sizing: border-box; margin: 0; padding: 0; }
        .front-page {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            min-height: 100vh;
            background: #fdfdfc;
            color: #1b1b18;
        }
        .front-page .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e5e5;
            background: #fff;
        }
        .front-page .header .logo { font-size: 1.25rem; font-weight: 700; text-decoration: none; color: inherit; }
        .front-page .header .nav a {
            margin-left: 1rem;
            font-size: 0.875rem;
            color: #706f6c;
            text-decoration: none;
        }
        .front-page .header .nav a:hover { color: #1b1b18; }
        .front-page main { max-width: 64rem; margin: 0 auto; padding: 1.5rem; }
        .front-page h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; }
        .front-page .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1.25rem;
        }
        .front-page .card {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .front-page .card img { width: 100%; aspect-ratio: 1; object-fit: cover; }
        .front-page .card .body { padding: 1rem; }
        .front-page .card .nombre { font-weight: 500; margin-bottom: 0.5rem; line-height: 1.3; font-size: 0.9375rem; }
        .front-page .card .precio { font-size: 1.125rem; font-weight: 600; }
        .front-page .card .meta { font-size: 0.8125rem; color: #706f6c; margin-top: 0.25rem; }
        .front-page .card a.oferta {
            display: inline-block;
            margin-top: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #1b1b18;
            text-decoration: underline;
        }
        .front-page .card a.oferta:hover { opacity: 0.8; }
        .front-page .empty { text-align: center; padding: 3rem 1rem; color: #706f6c; }
        .front-page .pagination { margin-top: 1.5rem; display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap; }
        .front-page .pagination a, .front-page .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e5e5e5;
            border-radius: 0.25rem;
            font-size: 0.875rem;
            text-decoration: none;
            color: #1b1b18;
        }
        .front-page .pagination a:hover { background: #f5f5f5; }
        .front-page .pagination span { background: #f5f5f5; color: #706f6c; }
    </style>
    @stack('styles')
</head>
<body class="front-page">
    <header class="header">
        <a href="{{ route('precios-bajos') }}" class="logo">Mayoreo Cloud</a>
        <nav class="nav">
            <a href="{{ route('precios-bajos') }}">Precios Bajos</a>
            <a href="{{ url('/admin') }}" target="_blank" rel="noopener">Admin</a>
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                @csrf
                <button type="submit" style="background:none;border:none;cursor:pointer;font-size:0.875rem;color:#706f6c;margin-left:1rem;">Cerrar sesión</button>
            </form>
        </nav>
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
