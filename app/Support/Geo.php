<?php

namespace App\Support;

class Geo
{
    /**
     * Retorna a URL do Google Maps para uma dada coordenada.
     */
    public static function mapsUrl(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $template = config('qrdobem.maps_url');
        return sprintf($template, $lat, $lng);
    }
}
