<?php

declare(strict_types=1);

namespace App\Support;

final class Dinero
{
    public static function redondear(float|int|string|null $monto): float
    {
        return round((float) ($monto ?? 0), 2);
    }

    public static function totalLinea(int $cantidad, float|int|string $precio): float
    {
        return self::redondear($cantidad * (float) $precio);
    }
}
