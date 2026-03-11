<x-filament-panels::page>
    @if($error)
        <div class="fi-fo-field-wrp rounded-xl bg-danger-50 dark:bg-danger-500/10 px-4 py-3 text-sm text-danger-600 dark:text-danger-400 mb-6" role="alert">
            {{ $error }}
        </div>
    @else
        <p class="text-gray-500 dark:text-gray-400 mb-4">
            Cupones de Admitad (región {{ $region ?? 'MX' }}). Incluye enlaces de afiliado cuando están disponibles. También puedes usar <code class="text-sm bg-gray-100 dark:bg-gray-800 px-1 rounded">php artisan admitad:coupons</code>.
        </p>
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
            <div class="fi-section-header flex flex-col gap-2 sm:flex-row sm:items-center justify-between px-6 py-4">
                <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">Listado ({{ $meta['count'] ?? count($coupons) }} cupones)</h3>
                <x-filament::button wire:click="refreshCoupons" size="sm">Actualizar</x-filament::button>
            </div>
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
                    <thead class="divide-y divide-gray-200 dark:divide-white/5">
                        <tr class="bg-gray-50 dark:bg-gray-800/50">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Nombre</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Programa</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Descuento</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Válido hasta</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Enlace</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @forelse($coupons as $c)
                            <tr class="fi-ta-row">
                                <td class="px-4 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ $c['name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $c['campaign']['name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm">{{ $c['discount'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $c['date_end'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if(!empty($c['goto_link']))
                                        <a href="{{ $c['goto_link'] }}" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline">Ir</a>
                                    @elseif(!empty($c['frameset_link']))
                                        <a href="{{ $c['frameset_link'] }}" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline">Ir</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No hay cupones o la API no devolvió datos. Comprueba el scope "coupons" en ADMITAD_SCOPE.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
