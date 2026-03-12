@extends('layouts.ofertas')

@section('title', 'Aviso de privacidad')

@section('content')
    <div style="max-width: 56rem; margin: 0 auto; padding: 2rem 1rem;">
        <h1 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 1.5rem;">Aviso de privacidad</h1>
        <p style="color: #a3a3a3; margin-bottom: 1rem;">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>
        <div style="line-height: 1.7; color: #e5e5e5;">
            <p style="margin-bottom: 1rem;">En <strong>Cazador De Precios</strong> (mayoreo.cloud) respetamos tu privacidad. Este aviso describe qué información recabamos y cómo la usamos.</p>
            <p style="margin-bottom: 1rem;"><strong>1. Información que recabamos.</strong> Al visitar el sitio podemos registrar dirección IP, tipo de navegador y páginas visitadas (logs del servidor). Si haces clic en un enlace de oferta, podemos registrar ese clic de forma anónima para métricas internas.</p>
            <p style="margin-bottom: 1rem;"><strong>2. Uso.</strong> Usamos la información para mejorar el servicio, analizar el uso del sitio y, en su caso, para programas de afiliados con terceros (sin identificar personalmente a los usuarios).</p>
            <p style="margin-bottom: 1rem;"><strong>3. Cookies.</strong> El sitio puede usar cookies técnicas necesarias para el funcionamiento. No vendemos datos personales a terceros.</p>
            <p style="margin-bottom: 1rem;"><strong>4. Terceros.</strong> Los enlaces te llevan a sitios externos (Mercado Libre, Coppel, etc.). Sus políticas de privacidad aplican cuando los visites.</p>
            <p style="margin-bottom: 1rem;"><strong>5. Derechos.</strong> Puedes solicitar acceso, rectificación o eliminación de datos que te identifiquen, en la medida que la ley aplicable lo permita.</p>
        </div>
        <p style="margin-top: 2rem;"><a href="{{ route('ofertas.dia') }}" style="color: #fbbf24; text-decoration: none;">← Volver a ofertas</a></p>
    </div>
@endsection
