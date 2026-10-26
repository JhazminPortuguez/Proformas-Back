<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoMovimientoInventario: string
{
    case Entrada = 'ENTRADA';
    case Salida = 'SALIDA';
    case Ajuste = 'AJUSTE';
    case Consumo = 'CONSUMO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Salida => 'Salida',
            self::Ajuste => 'Ajuste',
            self::Consumo => 'Consumo',
        };
    }

    /**
     * Dirección esperada del signo de la cantidad. Un ajuste puede ser
     * positivo o negativo, por eso no impone dirección.
     */
    public function signo(): int
    {
        return match ($this) {
            self::Entrada => 1,
            self::Salida, self::Consumo => -1,
            self::Ajuste => 0,
        };
    }

    public function esEntrada(): bool
    {
        return $this === self::Entrada;
    }
}
