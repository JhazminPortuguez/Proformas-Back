<?php

namespace App\Policies;

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

    public function create(User $user): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function delete(User $user, Producto $producto): bool
    {
        return $user->rol?->puedeEliminar() ?? false;
    }
}
