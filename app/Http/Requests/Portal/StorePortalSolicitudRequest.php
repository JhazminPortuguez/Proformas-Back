<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud de cotización desde el portal: descripción e ítems. Sin cliente
 * (sale de la sesión), sin adjuntos, sin dirección y sin tipo de servicio.
 */
class StorePortalSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.descripcion' => ['required', 'string', 'max:500'],
            'items.*.cantidad' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:100000'],
            'items.*.unidad_medida' => ['nullable', 'string', 'max:30'],
            'items.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'descripcion' => 'descripción',
            'items' => 'ítems',
            'items.*.descripcion' => 'descripción del ítem',
            'items.*.cantidad' => 'cantidad',
            'items.*.unidad_medida' => 'unidad',
            'items.*.observaciones' => 'observaciones',
        ];
    }
}
