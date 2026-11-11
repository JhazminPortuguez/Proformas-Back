<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use App\Support\EmpresaActual;
use App\Support\PeriodoReporte;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filtros comunes de /api/v1/reportes. Los reportes son solo del administrador.
 */
class ReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol === Rol::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasta = ['nullable', 'date_format:Y-m-d'];

        if ($this->filled('desde')) {
            $hasta[] = 'after_or_equal:desde';
        }

        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => $hasta,
            'cliente_id' => [
                'nullable',
                'integer',
                Rule::exists('clientes', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'operario_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'formato' => ['nullable', Rule::in(['json', 'csv'])],
        ];
    }

    public const MENSAJE_RANGO_AMPLIO = 'El rango del reporte no puede superar 1 año. Elige un periodo de 12 meses o menos.';

    /**
     * R-03: un rango de más de 1 año (por ejemplo, del 1/1/2026 al 1/1/2027)
     * se rechaza para no cargar años de movimientos en una sola consulta.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $desde = $this->input('desde');
                $hasta = $this->input('hasta');

                if (! is_string($desde) || ! is_string($hasta) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $limite = CarbonImmutable::createFromFormat('!Y-m-d', $desde)->addYear()->subDay();

                if (CarbonImmutable::createFromFormat('!Y-m-d', $hasta)->gt($limite)) {
                    $validator->errors()->add('hasta', self::MENSAJE_RANGO_AMPLIO);
                }
            },
        ];
    }

    public function periodo(): PeriodoReporte
    {
        return PeriodoReporte::crear(
            $this->validated('desde'),
            $this->validated('hasta'),
        );
    }

    public function clienteId(): ?int
    {
        $valor = $this->validated('cliente_id');

        return $valor === null ? null : (int) $valor;
    }

    public function operarioId(): ?int
    {
        $valor = $this->validated('operario_id');

        return $valor === null ? null : (int) $valor;
    }

    public function quiereCsv(): bool
    {
        return $this->validated('formato') === 'csv';
    }
}
