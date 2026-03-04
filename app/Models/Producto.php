<?php

namespace App\Models;

use App\Services\CalculadoraOfertas;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'tienda_origen',
        'categoria_origen',
        'sku_tienda',
        'nombre',
        'imagen_url',
        'precio_original',
        'precio_oferta',
        'porcentaje_ahorro',
        'stock_disponible',
        'ultima_actualizacion_precio',
        'url_original',
        'url_afiliado',
        'permite_descuento_adicional',
    ];

    protected function casts(): array
    {
        return [
            'precio_original' => 'decimal:2',
            'precio_oferta' => 'decimal:2',
            'porcentaje_ahorro' => 'decimal:2',
            'ultima_actualizacion_precio' => 'datetime',
            'permite_descuento_adicional' => 'boolean',
        ];
    }

    /**
     * Historial de precios del producto (para gráficas de evolución).
     */
    public function historialPrecios(): HasMany
    {
        return $this->hasMany(HistorialPrecio::class);
    }

    /**
     * Precio final a mostrar (respeta permite_descuento_adicional).
     * Para aplicar un descuento adicional use el servicio: CalculadoraOfertas::precioFinal($producto, $porcentaje).
     */
    public function getPrecioFinalAttribute(): float
    {
        return app(CalculadoraOfertas::class)->precioFinal($this, null);
    }

    /**
     * URL de afiliado con formato Admitad (url_original + subid).
     * Si url_afiliado ya está guardada, se devuelve; si no, se genera desde url_original.
     */
    public function getUrlAfiliadoCompletaAttribute(): ?string
    {
        if ($this->url_afiliado) {
            return $this->url_afiliado;
        }

        $urlOriginal = $this->url_original;
        if (! $urlOriginal) {
            return null;
        }

        $idAdmitad = config('services.admitad.id', env('ADMITAD_SUBID', ''));

        return app(CalculadoraOfertas::class)->urlAfiliadoAdmitad($urlOriginal, $idAdmitad);
    }
}
