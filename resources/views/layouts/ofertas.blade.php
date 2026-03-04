<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ofertas — {{ config('app.name') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect fill='%23f59e0b' width='32' height='32' rx='4'/></svg>" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen antialiased">
    <header class="bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ url('/') }}" class="text-xl font-semibold text-amber-600 hover:text-amber-700">
                {{ config('app.name') }}
            </a>
            <a href="{{ url('/admin') }}" class="text-sm text-slate-500 hover:text-slate-700" target="_blank" rel="noopener">
                Admin
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    <footer class="max-w-7xl mx-auto px-4 py-4 text-center text-sm text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name') }}. Ofertas México.
    </footer>

    @livewireScripts
</body>
</html>
