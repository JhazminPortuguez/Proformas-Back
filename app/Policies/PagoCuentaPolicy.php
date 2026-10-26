<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\PagoCuenta;
use App\Models\User;

class PagoCuentaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, PagoCuenta $pago): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function anular(User $user, PagoCuenta $pago): bool
    {
        return $user->rol === Rol::Admin;
    }
}
