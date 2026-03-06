<?php

namespace App\Services;

use App\Models\RedirectLink;
use Illuminate\Support\Str;

/**
 * Crea enlaces de redirección mayoreo.cloud/r/{codigo}. Guarda el mismo subid que se envía a Admitad para estadísticas consistentes.
 */
class RedirectLinkService
{
    private const LONGITUD_CODIGO = 10;

    /**
     * Crea un enlace de redirección y devuelve la URL corta (ej. https://mayoreo.cloud/r/abc123xyz).
     * $subid debe ser el mismo valor enviado a la API de Admitad.
     */
    public function crear(string $urlDestino, string $subid = 'Mayoreo_Cloud_Bot'): string
    {
        $codigo = $this->generarCodigoUnico();
        RedirectLink::create([
            'codigo' => $codigo,
            'url_destino' => $urlDestino,
            'subid' => $subid !== '' ? $subid : 'Mayoreo_Cloud_Bot',
        ]);
        return url('/r/' . $codigo);
    }

    private function generarCodigoUnico(): string
    {
        do {
            $codigo = Str::random(self::LONGITUD_CODIGO);
        } while (RedirectLink::where('codigo', $codigo)->exists());

        return $codigo;
    }
}
