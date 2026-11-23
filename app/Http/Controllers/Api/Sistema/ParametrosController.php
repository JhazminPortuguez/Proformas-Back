<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Support\EmpresaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ronda B, parte 1: parámetros del sistema. Los lee el personal (son los
 * valores iniciales de una proforma nueva) y solo los cambia el superusuario.
 */
class ParametrosController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $rol = $request->user()?->rol;

        abort_unless($rol?->esInterno() || $rol?->puedeConfigurarSistema(), 403, 'No tienes permisos para realizar esta acción.');

        return response()->json($this->datos($this->empresa()));
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->rol?->puedeConfigurarSistema(), 403, 'No tienes permisos para realizar esta acción.');

        $datos = $request->validate([
            'impuesto_por_defecto' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'dias_validez_por_defecto' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'impuesto_por_defecto.required' => 'Escribe el impuesto por defecto.',
            'impuesto_por_defecto.numeric' => 'El impuesto debe ser un número.',
            'impuesto_por_defecto.min' => 'El impuesto debe estar entre 0 y 100 %.',
            'impuesto_por_defecto.max' => 'El impuesto debe estar entre 0 y 100 %.',
            'impuesto_por_defecto.decimal' => 'El impuesto admite como máximo 2 decimales.',
            'dias_validez_por_defecto.integer' => 'Los días de validez deben ser un número entero.',
            'dias_validez_por_defecto.min' => 'Los días de validez deben estar entre 1 y 365.',
            'dias_validez_por_defecto.max' => 'Los días de validez deben estar entre 1 y 365.',
        ]);

        $empresa = $this->empresa();
        $empresa->update($datos);

        return response()->json($this->datos($empresa->fresh()));
    }

    /** @return array{impuesto_por_defecto: string, dias_validez_por_defecto: int|null} */
    private function datos(Empresa $empresa): array
    {
        return [
            'impuesto_por_defecto' => (string) $empresa->impuesto_por_defecto,
            'dias_validez_por_defecto' => $empresa->dias_validez_por_defecto,
        ];
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail(EmpresaActual::id());
    }
}
