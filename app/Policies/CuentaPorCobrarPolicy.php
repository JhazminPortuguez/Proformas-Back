<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\CuentaPorCobrar;
use App\Models\User;

class CuentaPorCobrarPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, CuentaPorCobrar $cuenta): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function registrarPago(User $user, CuentaPorCobrar $cuenta): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function anularPago(User $user, CuentaPorCobrar $cuenta): bool
    {
        return $user->rol === Rol::Admin;
    }
}
