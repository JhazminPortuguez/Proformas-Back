<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\User;

class ClientePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, Cliente $cliente): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function create(User $user): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function delete(User $user, Cliente $cliente): bool
    {
        return $user->rol?->puedeEliminar() ?? false;
    }
}
