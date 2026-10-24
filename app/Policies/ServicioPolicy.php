<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Servicio;
use App\Models\User;

class ServicioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, Servicio $servicio): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function create(User $user): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function update(User $user, Servicio $servicio): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function delete(User $user, Servicio $servicio): bool
    {
        return $user->rol === Rol::Admin;
    }
}
