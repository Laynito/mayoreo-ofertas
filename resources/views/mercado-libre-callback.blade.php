<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mercado Libre – Callback</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.25rem; }
        .box { background: #f5f5f5; padding: 1rem; border-radius: 8px; word-break: break-all; }
        code { background: #eee; padding: 0.2em 0.4em; border-radius: 4px; }
        .cmd { margin-top: 1rem; font-size: 0.9rem; }
        .error { color: #c00; }
    </style>
</head>
<body>
    @if(isset($error))
        <h1 class="error">Error: {{ $error }}</h1>
        <p>{{ $description ?? '' }}</p>
    @else
        <h1>Code recibido</h1>
        <p>Copia el <strong>code</strong> y ejecuta en el servidor:</p>
        <div class="box">
            <strong>Code:</strong><br>
            <code id="code">{{ $code }}</code>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('code').textContent)">Copiar</button>
        </div>
        <div class="box cmd">
            <strong>Comando (en la raíz del proyecto):</strong><br>
            <code>php artisan ml:exchange-code "{{ $code }}"</code>
        </div>
        <p style="margin-top: 1rem; color: #666;">Redirect URI usada: <code>{{ $redirect_uri ?? '—' }}</code></p>
    @endif
</body>
</html>
