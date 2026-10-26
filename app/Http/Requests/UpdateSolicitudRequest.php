<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solicitud')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'descripcion' => ['sometimes', 'required', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.descripcion' => ['required_with:items', 'string', 'max:500'],
            'items.*.cantidad' => ['required_with:items', 'numeric', 'decimal:0,3', 'min:0.001', 'max:100000'],
            'items.*.unidad_medida' => ['nullable', 'string', 'max:30'],
            'items.*.observaciones' => ['nullable', 'string', 'max:500'],
            'estado' => ['prohibited'],
        ];
    }
}
