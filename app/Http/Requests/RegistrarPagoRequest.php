<?php

namespace App\Http\Requests;

use App\Enums\MetodoPago;
use App\Models\CuentaPorCobrar;
use App\Support\Dinero;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cuenta = $this->route('cuenta');

        return $cuenta instanceof CuentaPorCobrar
            && ($this->user()?->can('registrarPago', $cuenta) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.Dinero::MONTO_MAXIMO],
            'metodo' => ['required', Rule::enum(MetodoPago::class)],
            'fecha_pago' => ['nullable', 'date'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto.required' => 'El monto del pago es obligatorio.',
            'monto.numeric' => 'El monto del pago debe ser numérico.',
            'monto.decimal' => 'El monto del pago debe tener como máximo 2 decimales.',
            'monto.min' => 'El monto del pago debe ser mayor que cero.',
            'monto.max' => 'El monto del pago excede el máximo permitido.',
            'metodo.required' => 'El método de pago es obligatorio.',
            'metodo.enum' => 'El método de pago seleccionado no es válido.',
            'fecha_pago.date' => 'La fecha de pago debe ser una fecha válida.',
            'referencia.max' => 'La referencia no debe superar los 100 caracteres.',
            'observaciones.max' => 'Las observaciones no deben superar los 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monto' => 'monto del pago',
            'metodo' => 'método de pago',
            'fecha_pago' => 'fecha de pago',
        ];
    }
}
