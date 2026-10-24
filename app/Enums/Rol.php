<?php

declare(strict_types=1);

namespace App\Enums;

enum Rol: string
{
    case Admin = 'admin';
    case Operario = 'operario';
    case Cliente = 'cliente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Operario => 'Operario',
            self::Cliente => 'Cliente',
        };
    }

    /**
     * Roles que pueden acceder a la operación interna (no al portal cliente).
     */
    public function esInterno(): bool
    {
        return $this === self::Admin || $this === self::Operario;
    }

    /**
     * Admin y operario pueden crear/editar datos operativos.
     */
    public function puedeGestionarDatos(): bool
    {
        return $this->esInterno();
    }

    public function puedeEliminar(): bool
    {
        return $this === self::Admin;
    }

    public function puedeGestionarUsuarios(): bool
    {
        return $this === self::Admin;
    }
}
