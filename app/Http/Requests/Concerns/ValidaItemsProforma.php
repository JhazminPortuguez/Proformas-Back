<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\TipoProformaItem;
use App\Support\EmpresaActual;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidaItemsProforma
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasItems(bool $obligatorios): array
    {
        return [
            'items' => [$obligatorios ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'items.*.tipo' => ['nullable', Rule::enum(TipoProformaItem::class)],
            'items.*.producto_id' => [
                'nullable',
                'integer',
                Rule::exists('productos', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'items.*.servicio_id' => [
                'nullable',
                'integer',
                Rule::exists('servicios', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'items.*.cantidad' => ['required_with:items', 'numeric', 'decimal:0,3', 'min:0.001', 'max:100000'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'items.*.descripcion' => ['nullable', 'string', 'max:500'],
            'items.*.unidad_medida' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function validarItemsProforma(Validator $validator, array $items): void
    {
        foreach ($items as $indice => $item) {
            $tipo = TipoProformaItem::tryFrom((string) ($item['tipo'] ?? TipoProformaItem::Material->value))
                ?? TipoProformaItem::Material;

            if ($tipo === TipoProformaItem::Material && empty($item['producto_id'])) {
                $validator->errors()->add(
                    "items.$indice.producto_id",
                    'Debe seleccionar un producto para la línea de material.'
                );
            }

            if ($tipo === TipoProformaItem::Servicio && empty($item['servicio_id'])) {
                $validator->errors()->add(
                    "items.$indice.servicio_id",
                    'Debe seleccionar un servicio para la línea de servicio.'
                );
            }

            if ($tipo === TipoProformaItem::Libre) {
                if (trim((string) ($item['descripcion'] ?? '')) === '') {
                    $validator->errors()->add(
                        "items.$indice.descripcion",
                        'La línea libre requiere una descripción.'
                    );
                }

                if (! array_key_exists('precio_unitario', $item) || $item['precio_unitario'] === null || $item['precio_unitario'] === '') {
                    $validator->errors()->add(
                        "items.$indice.precio_unitario",
                        'La línea libre requiere un precio unitario.'
                    );
                }
            }
        }
    }
}
