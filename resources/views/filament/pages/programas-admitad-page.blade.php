<x-filament-panels::page>
    @if($error)
        <div class="fi-fo-field-wrp rounded-xl bg-danger-50 dark:bg-danger-500/10 px-4 py-3 text-sm text-danger-600 dark:text-danger-400 mb-6" role="alert">
            {{ $error }}
        </div>
    @else
        <p class="text-gray-500 dark:text-gray-400 mb-4">
            Programas de afiliados disponibles en Admitad. Filtra por <strong>conectados</strong> e <strong>idioma</strong> para ver solo los que ya tienes aprobados o en tu idioma.
        </p>

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6 overflow-hidden">
            <div class="fi-section-header px-6 py-4">
                <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white mb-3">Filtros</h3>
            </div>
            <div class="fi-section-content px-6 pb-6">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1">
                        <label for="filter_connection" class="text-sm font-medium text-gray-700 dark:text-gray-300">Estado</label>
                        <select id="filter_connection" wire:model="connectionStatus" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:border-primary-500 focus:ring-primary-500 min-w-[200px]">
                            @foreach(\App\Filament\Pages\ProgramasAdmitadPage::getConnectionStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="filter_language" class="text-sm font-medium text-gray-700 dark:text-gray-300">Idioma</label>
                        <select id="filter_language" wire:model="language" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:border-primary-500 focus:ring-primary-500 min-w-[180px]">
                            @foreach(\App\Filament\Pages\ProgramasAdmitadPage::getLanguageOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="filter_limit" class="text-sm font-medium text-gray-700 dark:text-gray-300">Mostrar</label>
                        <select id="filter_limit" wire:model="limit" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:border-primary-500 focus:ring-primary-500 min-w-[100px]">
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <x-filament::button wire:click="refreshPrograms" size="sm">Aplicar filtros</x-filament::button>
                </div>
            </div>
        </div>

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
            <div class="fi-section-header flex flex-col gap-2 sm:flex-row sm:items-center justify-between px-6 py-4">
                <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">Listado ({{ $meta['count'] ?? count($programs) }} programas)</h3>
                <x-filament::button wire:click="refreshPrograms" size="sm">Actualizar</x-filament::button>
            </div>
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
                    <thead class="divide-y divide-gray-200 dark:divide-white/5">
                        <tr class="bg-gray-50 dark:bg-gray-800/50">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">ID</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Nombre</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">URL</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Estado</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Conectado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @forelse($programs as $p)
                            <tr class="fi-ta-row">
                                <td class="px-4 py-3 text-sm text-gray-950 dark:text-white">{{ $p['id'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ $p['name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if(!empty($p['site_url']))
                                        <a href="{{ $p['site_url'] }}" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline">{{ Str::limit($p['site_url'], 40) }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $p['status'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm">{{ (($p['connected'] ?? false) || (($p['connection_status'] ?? '') === 'active')) ? 'Sí' : 'No' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No hay programas o la API no devolvió datos.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
