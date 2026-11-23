<?php

namespace App\Policies;

use App\Models\User;

/**
 * Ronda B: el superusuario gestiona administradores, operarios y otros
 * superusuarios; el administrador, operarios y usuarios cliente
 * (Rol::rolesQueGestiona). El rol que se puede dar lo revisa el controlador.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->puedeGestionarUsuarios() ?? false;
    }

    public function view(User $user, User $model): bool
    {
        return $this->gestiona($user, $model) || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->rol?->puedeGestionarUsuarios() ?? false;
    }

    public function update(User $user, User $model): bool
    {
        return $this->gestiona($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->gestiona($user, $model) && $user->isNot($model);
    }

    private function gestiona(User $user, User $model): bool
    {
        return ($user->rol?->puedeGestionarUsuarios() ?? false)
            && $user->rol->gestionaA($model->rol);
    }
}
