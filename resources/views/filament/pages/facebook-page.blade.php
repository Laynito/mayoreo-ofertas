<x-filament-panels::page>
    @if(isset($pageInfo['error']))
        <div class="rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-4 mb-4">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ $pageInfo['error'] }}</p>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
            Configura <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">FB_PAGE_ID</code> y
            <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">FB_PAGE_ACCESS_TOKEN</code> en .env o en
            Marketplace → Facebook.
        </p>
        @return
    @endif

    {{-- Info de la página --}}
    <x-filament::section>
        <x-slot name="heading">Página conectada</x-slot>
        <p class="text-gray-600 dark:text-gray-400">
            <strong>{{ $pageInfo['name'] ?? '—' }}</strong>
            @if(!empty($pageInfo['id']))
                <span class="text-sm">(ID: {{ $pageInfo['id'] }})</span>
            @endif
        </p>
    </x-filament::section>

    {{-- Insights (alcance, impresiones) --}}
    <x-filament::section>
        <x-slot name="heading">Insights (últimos 7 días)</x-slot>
        @if($insightsError)
            <div class="rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-4 mb-4">
                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ $insightsError }}</p>
                <p class="text-sm text-amber-700 dark:text-amber-300 mt-2">
                    Los insights solo están disponibles para páginas con 100+ me gusta y token con permiso
                    <code class="bg-amber-100 dark:bg-amber-900/50 px-1 rounded">read_insights</code>.
                </p>
            </div>
        @elseif(!empty($insights))
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach($insights as $metric)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            {{ str_replace('page_', '', $metric['name'] ?? '') }}
                        </p>
                        <p class="text-2xl font-semibold mt-1">
                            {{ $this->formatInsightValue($metric['values'] ?? []) }}
                        </p>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 dark:text-gray-400">Sin datos de insights en el período.</p>
        @endif
    </x-filament::section>

    {{-- Publicaciones recientes --}}
    <x-filament::section>
        <x-slot name="heading">Publicaciones recientes</x-slot>
        @if($postsError)
            <div class="rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-4">
                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ $postsError }}</p>
            </div>
        @elseif(empty($posts))
            <p class="text-gray-500 dark:text-gray-400">No hay publicaciones o no se pudieron cargar.</p>
        @else
            <div class="space-y-3">
                @foreach($posts as $post)
                    @php
                        $id = $post['id'] ?? '';
                        $msg = \Illuminate\Support\Str::limit($post['message'] ?? '(sin texto)', 80);
                        $link = $post['permalink_url'] ?? '';
                        $created = $post['created_time'] ?? null;
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $msg }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ $this->formatPostDate($created) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            @if($link)
                                <a href="{{ $link }}" target="_blank" rel="noopener"
                                   class="text-sm text-primary-600 dark:text-primary-400 hover:underline">
                                    Ver en Facebook
                                </a>
                            @endif
                            <x-filament::button
                                color="danger"
                                size="sm"
                                wire:click="deletePost('{{ $id }}')"
                                wire:confirm="¿Eliminar esta publicación de la página?"
                            >
                                Eliminar
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
