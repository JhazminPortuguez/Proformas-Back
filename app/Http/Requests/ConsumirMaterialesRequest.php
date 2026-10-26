<?php

namespace App\Http\Requests;

use App\Models\OrdenServicio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsumirMaterialesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orden = $this->route('orden');

        return $orden instanceof OrdenServicio
            && ($this->user()?->can('consumirMateriales', $orden) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orden = $this->route('orden');
        $ordenId = $orden instanceof OrdenServicio ? $orden->id : 0;

        return [
            'items' => ['nullable', 'array'],
            'items.*.orden_item_id' => [
                'required',
                'integer',
                Rule::exists('orden_items', 'id')->where('orden_id', $ordenId),
            ],
            'items.*.cantidad' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:9999999999.999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.array' => 'Los ítems deben ser un arreglo.',
            'items.*.orden_item_id.required' => 'La línea de la orden es obligatoria.',
            'items.*.orden_item_id.exists' => 'La línea indicada no pertenece a esta orden.',
            'items.*.cantidad.required' => 'La cantidad a consumir es obligatoria.',
            'items.*.cantidad.numeric' => 'La cantidad a consumir debe ser numérica.',
            'items.*.cantidad.decimal' => 'La cantidad a consumir debe tener como máximo 3 decimales.',
            'items.*.cantidad.min' => 'La cantidad a consumir debe ser mayor que cero.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.orden_item_id' => 'línea de la orden',
            'items.*.cantidad' => 'cantidad a consumir',
        ];
    }
}
