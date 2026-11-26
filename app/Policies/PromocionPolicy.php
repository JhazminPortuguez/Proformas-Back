<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Promocion;
use App\Models\User;

/**
 * Ronda C: el personal ve las promociones (para elegirlas en la proforma);
 * solo el administrador las crea, edita, desactiva o elimina.
 */
class PromocionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, Promocion $promocion): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function create(User $user): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function update(User $user, Promocion $promocion): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function delete(User $user, Promocion $promocion): bool
    {
        return $user->rol === Rol::Admin;
    }
}
