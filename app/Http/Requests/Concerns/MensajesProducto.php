<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Mensajes y nombres de campo del producto. El formulario de Inventario del
 * front valida con estos mismos textos antes de enviar.
 */
trait MensajesProducto
{
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del producto es obligatorio.',
            'precio_unitario.required' => 'El precio de venta es obligatorio.',
            'precio_unitario.numeric' => 'El precio de venta debe ser numérico.',
            'precio_unitario.decimal' => 'El precio de venta debe tener como máximo 2 decimales.',
            'precio_unitario.min' => 'El precio de venta no puede ser negativo.',
            'costo_promedio.numeric' => 'El costo debe ser numérico.',
            'costo_promedio.decimal' => 'El costo debe tener como máximo 2 decimales.',
            'costo_promedio.min' => 'El costo no puede ser negativo.',
            'costo_promedio.prohibited' => 'El costo cambia solo con las entradas de inventario.',
            'stock_actual.numeric' => 'El stock inicial debe ser numérico.',
            'stock_actual.decimal' => 'El stock inicial debe tener como máximo 3 decimales.',
            'stock_actual.min' => 'El stock inicial no puede ser negativo.',
            'stock_actual.prohibited' => 'El stock se cambia con una entrada o un ajuste de inventario.',
            'stock_minimo.numeric' => 'El stock mínimo debe ser numérico.',
            'stock_minimo.decimal' => 'El stock mínimo debe tener como máximo 3 decimales.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'precio_unitario' => 'precio de venta',
            'costo_promedio' => 'costo',
            'stock_actual' => 'stock inicial',
            'stock_minimo' => 'stock mínimo',
            'unidad_medida' => 'unidad de medida',
        ];
    }
}
