<?php

declare(strict_types=1);

namespace App\Enums;

enum Rol: string
{
    /**
     * Ronda B: rol técnico. Configura el sistema y gestiona a los
     * administradores, pero no participa en la operación. Solo se crea con
     * `php artisan sistema:crear-superusuario`.
     */
    case Superusuario = 'superusuario';
    case Admin = 'admin';
    case Operario = 'operario';
    case Cliente = 'cliente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Superusuario => 'Superusuario',
            self::Admin => 'Administrador',
            self::Operario => 'Operario',
            self::Cliente => 'Cliente',
        };
    }

    /**
     * Roles que pueden acceder a la operación interna (no al portal cliente).
     * El superusuario no participa en la operación.
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

    /** Entra a la pantalla de Usuarios (cada uno gestiona roles distintos). */
    public function puedeGestionarUsuarios(): bool
    {
        return $this === self::Admin || $this === self::Superusuario;
    }

    /** Configuración de la empresa, parámetros y estado del sistema. */
    public function puedeConfigurarSistema(): bool
    {
        return $this === self::Superusuario;
    }

    /**
     * Roles de los usuarios que este rol puede ver, editar, desactivar o
     * eliminar en la pantalla de Usuarios.
     *
     * - Superusuario: administradores, operarios y otros superusuarios.
     * - Administrador: operarios y usuarios cliente.
     *
     * @return list<self>
     */
    public function rolesQueGestiona(): array
    {
        return match ($this) {
            self::Superusuario => [self::Superusuario, self::Admin, self::Operario],
            self::Admin => [self::Operario, self::Cliente],
            default => [],
        };
    }

    /**
     * Roles que este rol puede dar al crear un usuario o al cambiarle el rol.
     * Nadie da el rol de superusuario desde la pantalla: solo el comando.
     *
     * @return list<self>
     */
    public function rolesQueAsigna(): array
    {
        return array_values(array_filter(
            $this->rolesQueGestiona(),
            fn (self $rol): bool => $rol !== self::Superusuario,
        ));
    }

    public function gestionaA(?self $rol): bool
    {
        return $rol !== null && in_array($rol, $this->rolesQueGestiona(), true);
    }

    public function asigna(?self $rol): bool
    {
        return $rol !== null && in_array($rol, $this->rolesQueAsigna(), true);
    }
}
