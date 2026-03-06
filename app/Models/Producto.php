<?php

namespace App\Models;

use App\Services\CalculadoraOfertas;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'tienda_id',
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
        'affiliate_url',
        'permite_descuento_adicional',
        'activo',
    ];

    protected static function booted(): void
    {
        static::saving(function (Producto $producto): void {
            if ($producto->tienda_id !== null && $producto->relationLoaded('tienda') && $producto->tienda !== null) {
                $producto->tienda_origen = $producto->tienda->nombre;
            } elseif ($producto->tienda_id !== null) {
                $tienda = Tienda::find($producto->tienda_id);
                if ($tienda !== null) {
                    $producto->tienda_origen = $tienda->nombre;
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'precio_original' => 'decimal:2',
            'precio_oferta' => 'decimal:2',
            'porcentaje_ahorro' => 'decimal:2',
            'ultima_actualizacion_precio' => 'datetime',
            'permite_descuento_adicional' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /**
     * Tienda a la que pertenece el producto (configuración en Administración → Tiendas).
     */
    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class);
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
     * URL de afiliado para enviar a Telegram (botón "Ver en Tienda").
     * Si el listener de monetización guardó url_afiliado (Admitad/Coppel, etc.), se prioriza; si no, url_original (fábrica por tienda).
     */
    public function getUrlAfiliadoCompletaAttribute(): ?string
    {
        if (! empty($this->url_afiliado)) {
            return $this->url_afiliado;
        }
        if (! empty($this->url_original)) {
            return $this->url_original;
        }
        return $this->affiliate_url;
    }
}
