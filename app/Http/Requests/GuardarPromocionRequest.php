<?php

namespace App\Http\Requests;

use App\Enums\AlcancePromocion;
use App\Enums\TipoPromocion;
use App\Models\Promocion;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ronda C: crear o editar una promoción (solo el administrador).
 *
 * - Porcentaje: de 1 a 100. Monto fijo: más de Bs 0. 2x1: sin valor.
 * - "Hasta" no puede ser anterior a "desde".
 * - Un servicio o producto concreto, o cualquier ítem.
 */
class GuardarPromocionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $promocion = $this->route('promocion');

        return $promocion instanceof Promocion
            ? ($this->user()?->can('update', $promocion) ?? false)
            : ($this->user()?->can('create', Promocion::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $datos = [];

        if (is_string($this->input('nombre'))) {
            $datos['nombre'] = trim($this->input('nombre'));
        }

        // Solo se guarda la referencia que corresponde al alcance, y el 2x1
        // no lleva valor.
        if ($this->input('aplica_a') !== AlcancePromocion::Servicio->value) {
            $datos['servicio_id'] = null;
        }

        if ($this->input('aplica_a') !== AlcancePromocion::Producto->value) {
            $datos['producto_id'] = null;
        }

        if ($this->input('tipo') === TipoPromocion::DosPorUno->value) {
            $datos['valor'] = null;
        }

        $this->merge($datos);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tipo = $this->input('tipo');

        $valor = ['nullable', 'numeric', 'decimal:0,2'];

        if ($tipo === TipoPromocion::Porcentaje->value) {
            $valor = ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:100'];
        } elseif ($tipo === TipoPromocion::MontoFijo->value) {
            $valor = ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'];
        }

        return [
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::enum(TipoPromocion::class)],
            'valor' => $valor,
            'aplica_a' => ['required', Rule::enum(AlcancePromocion::class)],
            'servicio_id' => [
                'nullable',
                'required_if:aplica_a,'.AlcancePromocion::Servicio->value,
                'integer',
                Rule::exists('servicios', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'producto_id' => [
                'nullable',
                'required_if:aplica_a,'.AlcancePromocion::Producto->value,
                'integer',
                Rule::exists('productos', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $porcentaje = $this->input('tipo') === TipoPromocion::Porcentaje->value;

        return [
            'nombre.required' => 'Escribe el nombre de la promoción.',
            'nombre.max' => 'El nombre admite como máximo 120 caracteres.',
            'tipo.required' => 'Elige el tipo de promoción.',
            'valor.required' => $porcentaje ? 'Escribe el porcentaje (de 1 a 100).' : 'Escribe el monto del descuento en Bs.',
            'valor.min' => $porcentaje ? 'El porcentaje debe estar entre 1 y 100.' : 'El monto debe ser mayor a Bs 0.',
            'valor.max' => $porcentaje ? 'El porcentaje debe estar entre 1 y 100.' : 'El monto excede el máximo permitido.',
            'valor.decimal' => 'El valor admite como máximo 2 decimales.',
            'valor.numeric' => 'El valor debe ser un número.',
            'aplica_a.required' => 'Elige a qué se aplica la promoción.',
            'servicio_id.required_if' => 'Elige el servicio de la promoción.',
            'producto_id.required_if' => 'Elige el producto de la promoción.',
            'servicio_id.exists' => 'El servicio seleccionado no existe.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
            'vigente_desde.required' => 'Elige desde qué fecha vale la promoción.',
            'vigente_hasta.required' => 'Elige hasta qué fecha vale la promoción.',
            'vigente_desde.date_format' => 'Ingresa una fecha "desde" válida.',
            'vigente_hasta.date_format' => 'Ingresa una fecha "hasta" válida.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $desde = $this->input('vigente_desde');
                $hasta = $this->input('vigente_hasta');

                if (
                    is_string($desde) && is_string($hasta)
                    && ! $validator->errors()->hasAny(['vigente_desde', 'vigente_hasta'])
                    && $hasta < $desde
                ) {
                    $validator->errors()->add('vigente_hasta', 'La fecha "hasta" no puede ser anterior a la fecha "desde".');
                }
            },
        ];
    }
}
