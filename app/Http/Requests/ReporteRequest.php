<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use App\Support\EmpresaActual;
use App\Support\PeriodoReporte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
