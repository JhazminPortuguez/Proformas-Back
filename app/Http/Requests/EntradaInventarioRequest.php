<?php

namespace App\Http\Requests;

use App\Models\Producto;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntradaInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('registrarEntrada', Producto::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'producto_id' => [
                'required',
                'integer',
                Rule::exists('productos', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'cantidad' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:9999999999.999'],
            'costo_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'producto_id.required' => 'El producto es obligatorio.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
            'cantidad.required' => 'La cantidad de entrada es obligatoria.',
            'cantidad.numeric' => 'La cantidad de entrada debe ser numérica.',
            'cantidad.decimal' => 'La cantidad de entrada debe tener como máximo 3 decimales.',
            'cantidad.min' => 'La cantidad de entrada debe ser mayor que cero.',
            'costo_unitario.numeric' => 'El costo unitario debe ser numérico.',
            'costo_unitario.decimal' => 'El costo unitario debe tener como máximo 2 decimales.',
            'costo_unitario.min' => 'El costo unitario no puede ser negativo.',
            'motivo.max' => 'El motivo no puede superar los 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'producto_id' => 'producto',
            'costo_unitario' => 'costo unitario',
        ];
    }
}
