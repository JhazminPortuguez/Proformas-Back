<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Producto;
use App\Models\User;

class ProductoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, Producto $producto): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    /**
     * Los materiales son catálogo administrado: solo el admin los crea y edita.
     * El operario puede usarlos en una proforma mediante una línea libre.
     */
    public function create(User $user): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function delete(User $user, Producto $producto): bool
    {
        return $user->rol === Rol::Admin;
    }

    /**
     * Módulo de inventario. El operario consulta existencias y movimientos,
     * pero solo el administrador altera el stock.
     */
    public function verMovimientos(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function registrarEntrada(User $user): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function registrarAjuste(User $user): bool
    {
        return $user->rol === Rol::Admin;
    }
}
