@extends('layouts.ofertas')

@section('title', 'Términos de uso')

@section('content')
    <div style="max-width: 56rem; margin: 0 auto; padding: 2rem 1rem;">
        <h1 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 1.5rem;">Términos de uso</h1>
        <p style="color: #a3a3a3; margin-bottom: 1rem;">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>
        <div style="line-height: 1.7; color: #e5e5e5;">
            <p style="margin-bottom: 1rem;">Bienvenido a <strong>Cazador De Precios</strong> (mayoreo.cloud). Al usar este sitio aceptas los siguientes términos.</p>
            <p style="margin-bottom: 1rem;"><strong>1. Servicio.</strong> Este sitio muestra ofertas y enlaces a productos de terceros (Mercado Libre, Coppel, etc.). No vendemos productos; actuamos como intermediario de información y enlaces de afiliado.</p>
            <p style="margin-bottom: 1rem;"><strong>2. Precios y disponibilidad.</strong> Los precios y la disponibilidad pueden cambiar en las tiendas. Verifica siempre en el sitio del comercio antes de comprar.</p>
            <p style="margin-bottom: 1rem;"><strong>3. Enlaces de afiliado.</strong> Parte de los enlaces pueden ser de afiliados. Si realizas una compra a través de ellos, podemos recibir una comisión sin costo adicional para ti.</p>
            <p style="margin-bottom: 1rem;"><strong>4. Uso aceptable.</strong> No uses el sitio para fines ilegales ni para sobrecargar o dañar la infraestructura. Nos reservamos el derecho de limitar el acceso.</p>
            <p style="margin-bottom: 1rem;"><strong>5. Contacto.</strong> Para dudas sobre estos términos, utiliza los canales de contacto que indiquemos en el sitio.</p>
        </div>
        <p style="margin-top: 2rem;"><a href="{{ route('ofertas.dia') }}" style="color: #fbbf24; text-decoration: none;">← Volver a ofertas</a></p>
    </div>
@endsection
