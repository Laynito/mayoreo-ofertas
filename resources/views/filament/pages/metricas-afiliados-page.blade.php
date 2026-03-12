<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Clicks por marketplace</x-slot>
            <x-slot name="description">Cada vez que alguien hace clic en "Ver oferta" (web o Telegram) se registra aquí. Los enlaces pasan por /out/{id} y luego redirigen a ML, Coppel, etc.</x-slot>

            @if(empty($porMarketplace))
                <p class="text-gray-500 dark:text-gray-400">Aún no hay clicks registrados.</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($porMarketplace as $row)
                        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $row['nombre'] }}</p>
                            <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($row['total']) }}</p>
                            <p class="text-xs text-gray-400">clicks</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Total: {{ number_format($totalClicks) }} clicks</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Últimos 15 clicks</x-slot>

            @if(empty($ultimosClicks))
                <p class="text-gray-500 dark:text-gray-400">No hay registros.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-2 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Marketplace</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ultimosClicks as $c)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="px-2 py-2">
                                    <a href="{{ filament()->getUrl() }}/productos/{{ $c['producto_id'] }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $c['nombre'] }}
                                    </a>
                                </td>
                                <td class="px-2 py-2">{{ $c['marketplace'] }}</td>
                                <td class="px-2 py-2 text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($c['created_at'])->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
