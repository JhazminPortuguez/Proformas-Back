<?php

declare(strict_types=1);

namespace App\Support;

final class Monedas
{
    public const POR_DEFECTO = 'BOB';

    /** @var list<string> */
    public const SOPORTADAS = ['BOB', 'USD', 'EUR'];

    public static function etiqueta(string $moneda): string
    {
        return match (strtoupper($moneda)) {
            'BOB' => 'Bolivianos',
            'USD' => 'Dólares',
            'EUR' => 'Euros',
            default => strtoupper($moneda),
        };
    }

    public static function simbolo(string $moneda): string
    {
        return match (strtoupper($moneda)) {
            'BOB' => 'Bs',
            'USD' => '$',
            'EUR' => '€',
            default => strtoupper($moneda),
        };
    }
}
