<?php

namespace App\Livewire;

use App\Models\Producto;
use Livewire\Component;
use Livewire\WithPagination;

class TablaOfertasPublica extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public string $ordenarPor = 'mayor_descuento'; // mayor_descuento | nombre | tienda | precio

    protected $queryString = [
        'busqueda' => ['except' => ''],
        'ordenarPor' => ['except' => 'mayor_descuento'],
    ];

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function ordenarPor(string $valor): void
    {
        $this->ordenarPor = $valor;
        $this->resetPage();
    }

    public function render()
    {
        $productos = Producto::query()
            ->whereNotNull('precio_oferta')
            ->whereColumn('precio_oferta', '<', 'precio_original')
            ->when($this->busqueda !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('nombre', 'like', '%' . $this->busqueda . '%')
                        ->orWhere('tienda_origen', 'like', '%' . $this->busqueda . '%');
                });
            })
            ->when($this->ordenarPor === 'mayor_descuento', fn ($q) => $q->orderByDesc('porcentaje_ahorro'))
            ->when($this->ordenarPor === 'nombre', fn ($q) => $q->orderBy('nombre'))
            ->when($this->ordenarPor === 'tienda', fn ($q) => $q->orderBy('tienda_origen'))
            ->when($this->ordenarPor === 'precio', fn ($q) => $q->orderByRaw('COALESCE(precio_oferta, precio_original) ASC'))
            ->paginate(15);

        return view('livewire.tabla-ofertas-publica', [
            'productos' => $productos,
        ]);
    }
}
