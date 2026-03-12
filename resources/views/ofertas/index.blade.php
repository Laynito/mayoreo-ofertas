@extends('layouts.ofertas')

@section('title', $titulo)

@section('content')
    <h1 class="page-title">{{ $titulo }}</h1>
    <p class="page-subtitle">{{ $subtitulo }}</p>

    <form class="search-form" action="{{ $esTodas ? route('ofertas.todas') : route('ofertas.dia') }}" method="get" role="search">
        <label for="q" class="search-label">Buscar por número de oferta o nombre</label>
        <div class="search-row">
            <input type="search" id="q" name="q" value="{{ old('q', $buscar ?? '') }}" placeholder="Ej: 42 o multímetro" class="search-input" autocomplete="off">
            <button type="submit" class="search-btn">Buscar</button>
        </div>
    </form>

    @if($productos->isEmpty())
        <div class="empty">
            @if(!empty($buscar))
                <p class="empty-title">No hay ofertas con "{{ e($buscar) }}"</p>
                <p>Prueba otro número de oferta o nombre, o revisa <a href="{{ $esTodas ? route('ofertas.todas') : route('ofertas.dia') }}" style="color:#fbbf24; text-decoration:underline;">sin filtro</a>.</p>
            @else
                <p class="empty-title">Aún no hay ofertas aquí</p>
                <p>Vuelve más tarde o revisa <a href="{{ route('ofertas.todas') }}" style="color:#fbbf24; text-decoration:underline;">todas las ofertas</a>.</p>
            @endif
        </div>
    @else
        <div class="grid">
            @foreach($productos as $p)
                @php
                    $link = route('out', ['producto' => $p->id]);
                @endphp
                <article class="card">
                    <span class="card-numero">Oferta #{{ $p->id }}</span>
                    @if($p->url_imagen)
                        <a href="{{ $link }}" target="_blank" rel="noopener nofollow" class="card-link">
                            <img src="{{ $p->url_imagen }}" alt="" loading="lazy" width="180" height="180">
                        </a>
                    @endif
                    <div class="body">
                        <p class="nombre">{{ Str::limit($p->nombre, 60) }}</p>
                        <p class="precio">${{ number_format($p->precio_actual, 0, '.', ',') }} MXN</p>
                        @if($p->precio_original && (float) $p->precio_original > (float) $p->precio_actual)
                            <p class="meta">Antes ${{ number_format($p->precio_original, 0, '.', ',') }} · {{ $p->descuento ?? 0 }}% OFF · {{ $p->tienda ?? '' }}</p>
                        @else
                            <p class="meta">{{ $p->tienda ?? '—' }}</p>
                        @endif
                        <a class="btn-ver" href="{{ $link }}" target="_blank" rel="noopener nofollow">Ver oferta</a>
                    </div>
                </article>
            @endforeach
        </div>

        @if($productos->hasPages())
            <nav class="pagination" aria-label="Paginación">
                @if($productos->onFirstPage())
                    <span aria-disabled="true">« Anterior</span>
                @else
                    <a href="{{ $productos->previousPageUrl() }}">« Anterior</a>
                @endif
                @foreach($productos->getUrlRange(max(1, $productos->currentPage() - 2), min($productos->lastPage(), $productos->currentPage() + 2)) as $page => $url)
                    @if($page == $productos->currentPage())
                        <span aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
                @if($productos->hasMorePages())
                    <a href="{{ $productos->nextPageUrl() }}">Siguiente »</a>
                @else
                    <span>Siguiente »</span>
                @endif
            </nav>
        @endif
    @endif
@endsection
