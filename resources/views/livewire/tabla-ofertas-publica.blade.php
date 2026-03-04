<div>
    {{-- Buscador y filtro --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-stretch sm:items-center justify-between">
        <div class="relative flex-1 max-w-md">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </span>
            <input
                type="search"
                wire:model.live.debounce.300ms="busqueda"
                placeholder="Buscar por nombre o tienda..."
                class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
            />
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <span class="text-sm text-slate-600 whitespace-nowrap">Ordenar:</span>
            <select
                wire:model.live="ordenarPor"
                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
            >
                <option value="mayor_descuento">Mayor descuento</option>
                <option value="nombre">Nombre</option>
                <option value="tienda">Tienda</option>
                <option value="precio">Menor precio</option>
            </select>
        </div>
    </div>

    {{-- Tabla tipo data-table --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-2 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-20">Foto</th>
                        <th scope="col" class="py-3 px-2 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Producto</th>
                        <th scope="col" class="py-3 px-2 text-left text-xs font-medium text-slate-500 uppercase tracking-wider hidden sm:table-cell">Tienda</th>
                        <th scope="col" class="py-3 px-2 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Precio</th>
                        <th scope="col" class="py-3 px-2 text-center text-xs font-medium text-slate-500 uppercase tracking-wider w-24">Ahorro</th>
                        <th scope="col" class="py-3 px-2 text-center text-xs font-medium text-slate-500 uppercase tracking-wider w-20 hidden md:table-cell">Stock</th>
                        <th scope="col" class="py-3 pl-2 pr-4 text-right text-xs font-medium text-slate-500 uppercase tracking-wider w-36">Acción</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    @forelse($productos as $producto)
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 pl-4 pr-2 whitespace-nowrap">
                                <img
                                    src="{{ $producto->imagen_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($producto->nombre) . '&size=80' }}"
                                    alt=""
                                    class="h-12 w-12 rounded-lg object-cover bg-slate-100"
                                    loading="lazy"
                                />
                            </td>
                            <td class="py-3 px-2">
                                <span class="font-medium text-slate-900 line-clamp-2">{{ $producto->nombre }}</span>
                                <span class="sm:hidden text-xs text-slate-500">{{ $producto->tienda_origen }}</span>
                            </td>
                            <td class="py-3 px-2 hidden sm:table-cell">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700">
                                    {{ $producto->tienda_origen }}
                                </span>
                            </td>
                            <td class="py-3 px-2 text-right whitespace-nowrap">
                                @if($producto->precio_original > 0 && $producto->precio_oferta && (float)$producto->precio_oferta < (float)$producto->precio_original)
                                    <span class="text-slate-400 line-through text-sm mr-1">${{ number_format($producto->precio_original, 0) }}</span>
                                @endif
                                <span class="font-semibold text-amber-600">${{ number_format($producto->precio_final, 0) }}</span>
                            </td>
                            <td class="py-3 px-2 text-center">
                                @if($producto->porcentaje_ahorro !== null && (float)$producto->porcentaje_ahorro > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ (float)$producto->porcentaje_ahorro > 50 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                        -{{ number_format($producto->porcentaje_ahorro, 0) }}%
                                    </span>
                                @else
                                    <span class="text-slate-400 text-xs">—</span>
                                @endif
                            </td>
                            <td class="py-3 px-2 text-center hidden md:table-cell">
                                @if($producto->stock_disponible > 0)
                                    <span class="text-xs text-green-600 font-medium">En stock</span>
                                @else
                                    <span class="text-xs text-slate-400">Sin stock</span>
                                @endif
                            </td>
                            <td class="py-3 pl-2 pr-4 text-right">
                                @php $urlComprar = $producto->url_afiliado_completa ?: $producto->url_original; @endphp
                                @if($urlComprar)
                                    <a
                                        href="{{ $urlComprar }}"
                                        target="_blank"
                                        rel="noopener noreferrer sponsored"
                                        class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-amber-500 text-white hover:bg-amber-600 transition-colors"
                                    >
                                        Comprar oferta
                                    </a>
                                @else
                                    <span class="text-slate-400 text-sm">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-500">
                                No hay ofertas que coincidan con tu búsqueda.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($productos->hasPages())
            <div class="px-4 py-3 border-t border-slate-200 bg-slate-50">
                {{ $productos->links() }}
            </div>
        @endif
    </div>
</div>
