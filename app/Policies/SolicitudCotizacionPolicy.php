<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\SolicitudCotizacion;
use App\Models\User;

class SolicitudCotizacionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, SolicitudCotizacion $solicitud): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function create(User $user): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function update(User $user, SolicitudCotizacion $solicitud): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function delete(User $user, SolicitudCotizacion $solicitud): bool
    {
        return $user->rol === Rol::Admin;
    }

    public function convertir(User $user, SolicitudCotizacion $solicitud): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }
}
