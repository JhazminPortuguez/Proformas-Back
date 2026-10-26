<?php

namespace App\Http\Requests;

use App\Models\PagoCuenta;
use Illuminate\Foundation\Http\FormRequest;

class AnularPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pago = $this->route('pago');

        return $pago instanceof PagoCuenta
            && ($this->user()?->can('anular', $pago) ?? false);
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
            'motivo.required' => 'El motivo de anulación es obligatorio.',
            'motivo.string' => 'El motivo de anulación debe ser texto.',
            'motivo.min' => 'El motivo de anulación debe tener al menos 3 caracteres.',
            'motivo.max' => 'El motivo de anulación no debe superar los 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo' => 'motivo de anulación',
        ];
    }
}
