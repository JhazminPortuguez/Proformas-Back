<?php

namespace App\Http\Requests;

use App\Models\Producto;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AjusteInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('registrarAjuste', Producto::class) ?? false;
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
            'stock_nuevo' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999999.999'],
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
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
            'stock_nuevo.required' => 'El stock nuevo es obligatorio.',
            'stock_nuevo.numeric' => 'El stock nuevo debe ser numérico.',
            'stock_nuevo.decimal' => 'El stock nuevo debe tener como máximo 3 decimales.',
            'stock_nuevo.min' => 'El stock nuevo no puede ser negativo.',
            'motivo.required' => 'El motivo del ajuste es obligatorio.',
            'motivo.min' => 'El motivo del ajuste debe tener al menos 3 caracteres.',
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
            'stock_nuevo' => 'stock nuevo',
        ];
    }
}
