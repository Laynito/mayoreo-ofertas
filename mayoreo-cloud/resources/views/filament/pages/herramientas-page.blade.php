<x-filament-panels::page>
    <p class="text-gray-500 dark:text-gray-400 mb-6">
        Usa los botones del encabezado para ejecutar el scraper, revisar la cola fallida o limpiar logs.
    </p>

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex flex-col gap-2 sm:flex-row sm:items-center px-6 py-4">
            <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">Estado del scraper</h3>
        </div>
        <div class="fi-section-content px-6 pb-6">
            @if($scraperStatus ?? null)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    @if(($scraperStatus['status'] ?? '') === 'running')
                        <span class="inline-flex flex-wrap items-center gap-1.5">
                            <span class="fi-badge relative grid items-center font-semibold fi-color-warning fi-size-sm rounded-md px-2 py-0.5 text-xs inline-grid bg-warning-500/10 text-warning-600 dark:text-warning-400 dark:bg-warning-500/20">En curso</span>
                            Iniciado: {{ \Carbon\Carbon::parse($scraperStatus['started_at'] ?? '')->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}.
                            Recarga la página para ver si ya terminó.
                        </span>
                    @elseif(($scraperStatus['status'] ?? '') === 'success')
                        <span class="inline-flex flex-wrap items-center gap-1.5">
                            <span class="fi-badge relative grid items-center font-semibold fi-color-success fi-size-sm rounded-md px-2 py-0.5 text-xs inline-grid bg-success-500/10 text-success-600 dark:text-success-400 dark:bg-success-500/20">Completado</span>
                            Última ejecución: {{ isset($scraperStatus['finished_at']) ? \Carbon\Carbon::parse($scraperStatus['finished_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') : '—' }}.
                        </span>
                    @elseif(($scraperStatus['status'] ?? '') === 'failed')
                        <span class="inline-flex flex-wrap items-center gap-1.5">
                            <span class="fi-badge relative grid items-center font-semibold fi-color-danger fi-size-sm rounded-md px-2 py-0.5 text-xs inline-grid bg-danger-500/10 text-danger-600 dark:text-danger-400 dark:bg-danger-500/20">Fallido</span>
                            Finalizado con error: {{ isset($scraperStatus['finished_at']) ? \Carbon\Carbon::parse($scraperStatus['finished_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') : '—' }}.
                            Revisa <code class="text-xs bg-gray-100 dark:bg-gray-800 px-1 rounded">storage/logs/laravel.log</code>.
                        </span>
                    @else
                        Estado: {{ $scraperStatus['status'] ?? 'desconocido' }}.
                    @endif
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Aún no se ha ejecutado el scraper desde esta sesión, o no hay registro de estado. Al pulsar «Ejecutar scraper ahora» y confirmar, aquí aparecerá si está en curso o la hora de la última ejecución.
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
