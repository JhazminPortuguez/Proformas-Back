<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('producto')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'precio_unitario' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'unidad_medida' => ['nullable', 'string', 'max:30'],
            // El stock y el costo solo cambian mediante movimientos de inventario.
            'stock_actual' => ['prohibited'],
            'stock_minimo' => ['sometimes', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999999.999'],
            'costo_promedio' => ['prohibited'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }
}
