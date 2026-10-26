<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Rol;
use App\Models\Proforma;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class EliminarUsuario
{
    private const TABLAS_ORDENES = ['ordenes', 'ordenes_servicio'];

    private const COLUMNAS_ORDENES = ['created_by', 'updated_by', 'operario_id'];

    public function ejecutar(User $usuario): void
    {
        if ($usuario->rol === Rol::Admin && User::query()->where('rol', Rol::Admin)->count() <= 1) {
            throw ValidationException::withMessages([
                'rol' => 'No se puede eliminar al último administrador.',
            ]);
        }

        if ($this->tieneHistorial($usuario)) {
            abort(409, 'No se puede eliminar el usuario porque tiene historial asociado.');
        }

        $usuario->delete();
    }

    private function tieneHistorial(User $usuario): bool
    {
        $proformas = Proforma::query()
            ->withoutGlobalScopes()
            ->where(function ($query) use ($usuario): void {
                $query->where('created_by', $usuario->id)
                    ->orWhere('updated_by', $usuario->id)
                    ->orWhere('aceptada_por', $usuario->id);
            })
            ->exists();

        if ($proformas) {
            return true;
        }

        $solicitudes = SolicitudCotizacion::query()
            ->withoutGlobalScopes()
            ->where(function ($query) use ($usuario): void {
                $query->where('created_by', $usuario->id)
                    ->orWhere('updated_by', $usuario->id);
            })
            ->exists();

        if ($solicitudes) {
            return true;
        }

        foreach (self::TABLAS_ORDENES as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach (self::COLUMNAS_ORDENES as $columna) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    continue;
                }

                if (DB::table($tabla)->where($columna, $usuario->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
