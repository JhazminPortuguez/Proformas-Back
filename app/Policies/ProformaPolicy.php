<?php

namespace App\Policies;

use App\Models\Proforma;
use App\Models\User;

class ProformaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, Proforma $proforma): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function create(User $user): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function update(User $user, Proforma $proforma): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function delete(User $user, Proforma $proforma): bool
    {
        return $user->rol?->puedeEliminar() ?? false;
    }
}
