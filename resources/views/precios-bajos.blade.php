@extends('layouts.front')

@section('title', 'Precios Bajos')

@section('content')
    <h1>Precios Bajos</h1>
    <p style="color:#706f6c; margin-bottom: 1rem;">Ofertas de Mercado Libre y más. Solo para miembros.</p>

    @if($productos->isEmpty())
        <p class="empty">Aún no hay ofertas cargadas. Vuelve más tarde.</p>
    @else
        <div class="grid">
            @foreach($productos as $p)
                <article class="card">
                    @if($p->url_imagen)
                        <a href="{{ $p->url_afiliado ?: app(\App\Services\AffiliateService::class)->getAffiliateLinkForProduct($p->url_producto, $p->tienda) }}" target="_blank" rel="noopener nofollow">
                            <img src="{{ $p->url_imagen }}" alt="" loading="lazy">
                        </a>
                    @endif
                    <div class="body">
                        <p class="nombre">{{ Str::limit($p->nombre, 80) }}</p>
                        <p class="precio">$ {{ number_format($p->precio_actual, 0, '.', ',') }} MXN</p>
                        @if($p->precio_original && (float)$p->precio_original > (float)$p->precio_actual)
                            <p class="meta">Antes $ {{ number_format($p->precio_original, 0, '.', ',') }} · {{ $p->descuento ?? 0 }}% OFF</p>
                        @else
                            <p class="meta">{{ $p->tienda ?? '—' }}</p>
                        @endif
                        <a class="oferta" href="{{ $p->url_afiliado ?: app(\App\Services\AffiliateService::class)->getAffiliateLinkForProduct($p->url_producto, $p->tienda) }}" target="_blank" rel="noopener nofollow">Ver oferta →</a>
                    </div>
                </article>
            @endforeach
        </div>

        @if($productos->hasPages())
            <div class="pagination">
                @if($productos->onFirstPage())
                    <span>« Anterior</span>
                @else
                    <a href="{{ $productos->previousPageUrl() }}">« Anterior</a>
                @endif
                @foreach($productos->getUrlRange(max(1, $productos->currentPage() - 2), min($productos->lastPage(), $productos->currentPage() + 2)) as $page => $url)
                    @if($page == $productos->currentPage())
                        <span>{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
                @if($productos->hasMorePages())
                    <a href="{{ $productos->nextPageUrl() }}">Siguiente »</a>
                @else
                    <span>Siguiente »</span>
                @endif
            </div>
        @endif
    @endif
@endsection
