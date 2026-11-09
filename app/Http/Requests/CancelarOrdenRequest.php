<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cancelar', $this->route('orden')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
            // Por material consumido: cuánto vuelve al stock (0 a lo consumido).
            'devoluciones' => ['sometimes', 'array', 'max:100'],
            'devoluciones.*.orden_item_id' => ['required', 'integer'],
            'devoluciones.*.cantidad' => ['required', 'numeric', 'decimal:0,3', 'min:0'],
            'cobrar_materiales' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'devoluciones.*.cantidad.min' => 'La cantidad devuelta no puede ser negativa.',
            'devoluciones.*.cantidad.numeric' => 'La cantidad devuelta debe ser un número.',
            'devoluciones.*.cantidad.decimal' => 'La cantidad devuelta admite hasta 3 decimales.',
        ];
    }
}
