<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar – {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        .login-page * { box-sizing: border-box; margin: 0; padding: 0; }
        .login-page {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fdfdfc;
            color: #1b1b18;
            padding: 1.5rem;
        }
        .login-page .logo { font-size: 1.5rem; font-weight: 700; margin-bottom: 1.5rem; }
        .login-page .card {
            width: 100%;
            max-width: 22rem;
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .login-page h1 { font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem; }
        .login-page label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem; }
        .login-page input[type="email"],
        .login-page input[type="password"] {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d4d4d4;
            border-radius: 0.25rem;
            font-size: 1rem;
            margin-bottom: 1rem;
        }
        .login-page input:focus { outline: none; border-color: #1b1b18; }
        .login-page .error { font-size: 0.875rem; color: #b91c1c; margin-bottom: 0.5rem; }
        .login-page .remember { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; font-size: 0.875rem; }
        .login-page button[type="submit"] {
            width: 100%;
            padding: 0.625rem 1rem;
            background: #1b1b18;
            color: #fff;
            border: none;
            border-radius: 0.25rem;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
        }
        .login-page button[type="submit"]:hover { opacity: 0.9; }
        .login-page .back { margin-top: 1rem; font-size: 0.875rem; }
        .login-page .back a { color: #706f6c; text-decoration: none; }
        .login-page .back a:hover { text-decoration: underline; }
    </style>
</head>
<body class="login-page">
    <div class="logo">Mayoreo Cloud</div>
    <div class="card">
        <h1>Acceso a Precios Bajos</h1>
        @if ($errors->any())
            <p class="error">{{ $errors->first() }}</p>
        @endif
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label for="email">Correo</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <label class="remember">
                <input type="checkbox" name="remember">
                Recordarme
            </label>
            <button type="submit">Entrar</button>
        </form>
    </div>
    <p class="back"><a href="{{ url('/') }}">← Volver al inicio</a></p>
</body>
</html>
