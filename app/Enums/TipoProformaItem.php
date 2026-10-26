<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoProformaItem: string
{
    case Servicio = 'SERVICIO';
    case Material = 'MATERIAL';
    case Libre = 'LIBRE';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicio => 'Servicio',
            self::Material => 'Material',
            self::Libre => 'Línea libre',
        };
    }

    public function requiereCatalogo(): bool
    {
        return $this !== self::Libre;
    }
}
