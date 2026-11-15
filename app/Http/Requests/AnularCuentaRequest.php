<?php

namespace App\Http\Requests;

use App\Models\CuentaPorCobrar;
use Illuminate\Foundation\Http\FormRequest;

class AnularCuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cuenta = $this->route('cuenta');

        return $cuenta instanceof CuentaPorCobrar
            && ($this->user()?->can('anular', $cuenta) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo de la anulación es obligatorio.',
            'motivo.min' => 'Explica el motivo de la anulación (al menos 3 caracteres).',
            'motivo.max' => 'El motivo no debe superar los 1000 caracteres.',
        ];
    }
}
