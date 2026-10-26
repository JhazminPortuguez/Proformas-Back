<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\OrdenServicio;
use App\Models\User;

class OrdenServicioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rol?->esInterno() ?? false;
    }

    public function view(User $user, OrdenServicio $orden): bool
    {
        return $this->esAdmin($user)
            || ($this->esOperario($user) && $this->esSuya($user, $orden));
    }

    public function create(User $user): bool
    {
        return $user->rol?->puedeGestionarDatos() ?? false;
    }

    public function asignar(User $user, OrdenServicio $orden): bool
    {
        return $this->esAdmin($user);
    }

    public function iniciar(User $user, OrdenServicio $orden): bool
    {
        return $this->puedeOperar($user, $orden);
    }

    public function finalizar(User $user, OrdenServicio $orden): bool
    {
        return $this->puedeOperar($user, $orden);
    }

    public function cancelar(User $user, OrdenServicio $orden): bool
    {
        return $this->esAdmin($user);
    }

    public function cerrar(User $user, OrdenServicio $orden): bool
    {
        return $this->esAdmin($user);
    }

    /**
     * El rol y la asignación se validan aquí; el estado de la orden se valida
     * en la acción para devolver 422 (no 403) cuando no admite consumos.
     */
    public function consumirMateriales(User $user, OrdenServicio $orden): bool
    {
        return $this->puedeOperar($user, $orden);
    }

    private function puedeOperar(User $user, OrdenServicio $orden): bool
    {
        return $this->esAdmin($user)
            || ($this->esOperario($user) && $this->esSuya($user, $orden));
    }

    private function esAdmin(User $user): bool
    {
        return $user->rol === Rol::Admin;
    }

    private function esOperario(User $user): bool
    {
        return $user->rol === Rol::Operario;
    }

    private function esSuya(User $user, OrdenServicio $orden): bool
    {
        return $orden->operario_id !== null
            && (int) $orden->operario_id === (int) $user->id;
    }
}
