<x-filament-panels::page>
    <p class="text-gray-500 dark:text-gray-400 mb-6">
        Convierte URLs en enlaces de afiliado de Admitad (Deeplink). Necesitas el <strong>ID del espacio publicitario</strong> (en Admitad → Espacios) y el <strong>ID del programa</strong> (desde Programas Admitad).
    </p>

    <form wire:submit="generate">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" size="lg">
                Generar enlaces
            </x-filament::button>
        </div>
    </form>

    @if(count($generated_links) > 0)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mt-8 overflow-hidden">
            <div class="fi-section-header px-6 py-4">
                <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">Enlaces generados ({{ count($generated_links) }})</h3>
            </div>
            <div class="fi-section-content px-6 pb-6 space-y-3">
                @foreach($generated_links as $item)
                    @php
                        $link = is_array($item) ? ($item['link'] ?? '') : $item;
                    @endphp
                    @if($link)
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ $link }}" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 hover:underline break-all text-sm">{{ Str::limit($link, 80) }}</a>
                            <x-filament::button size="xs" tag="a" href="{{ $link }}" target="_blank" rel="noopener">Abrir</x-filament::button>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
