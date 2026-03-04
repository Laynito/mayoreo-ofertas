@extends('layouts.ofertas')

@section('content')
    <h1 class="text-2xl font-bold text-slate-800 mb-2">Ofertas</h1>
    <p class="text-slate-600 mb-6">Compara precios y ahorra. Ordena por mayor descuento o busca por nombre o tienda.</p>
    @livewire('tabla-ofertas-publica')
@endsection
