<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoProforma: string
{
    case Borrador = 'BORRADOR';
    case Enviada = 'ENVIADA';
    case Aceptada = 'ACEPTADA';
    case Rechazada = 'RECHAZADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviada => 'Enviada',
            self::Aceptada => 'Aceptada',
            self::Rechazada => 'Rechazada',
        };
    }

    public function esFinal(): bool
    {
        return in_array($this, [self::Aceptada, self::Rechazada], true);
    }

    /**
     * Flujo permitido: BORRADOR -> ENVIADA -> ACEPTADA|RECHAZADA.
     * Las estados finales no vuelven atrás.
     */
    public function puedeTransicionarA(self $nuevo): bool
    {
        if ($this === $nuevo) {
            return true;
        }

        return match ($this) {
            self::Borrador => $nuevo === self::Enviada,
            self::Enviada => in_array($nuevo, [self::Aceptada, self::Rechazada], true),
            self::Aceptada, self::Rechazada => false,
        };
    }

    public function permiteEditarContenido(): bool
    {
        return $this === self::Borrador;
    }
}
