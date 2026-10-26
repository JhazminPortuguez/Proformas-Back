<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoOrden: string
{
    case Pendiente = 'PENDIENTE';
    case Asignada = 'ASIGNADA';
    case EnProceso = 'EN_PROCESO';
    case Finalizada = 'FINALIZADA';
    case Cancelada = 'CANCELADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Asignada => 'Asignada',
            self::EnProceso => 'En proceso',
            self::Finalizada => 'Finalizada',
            self::Cancelada => 'Cancelada',
        };
    }

    public function esFinal(): bool
    {
        return in_array($this, [self::Finalizada, self::Cancelada], true);
    }

    public function esActiva(): bool
    {
        return ! $this->esFinal();
    }

    /**
     * Flujo permitido, sin saltos ni reactivación:
     * PENDIENTE -> ASIGNADA|CANCELADA
     * ASIGNADA -> EN_PROCESO|CANCELADA
     * EN_PROCESO -> FINALIZADA|CANCELADA
     * FINALIZADA|CANCELADA son finales.
     */
    public function puedeTransicionarA(self $nuevo): bool
    {
        if ($this === $nuevo) {
            return true;
        }

        return match ($this) {
            self::Pendiente => in_array($nuevo, [self::Asignada, self::Cancelada], true),
            self::Asignada => in_array($nuevo, [self::EnProceso, self::Cancelada], true),
            self::EnProceso => in_array($nuevo, [self::Finalizada, self::Cancelada], true),
            self::Finalizada, self::Cancelada => false,
        };
    }
}
