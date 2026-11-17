<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reportes\ReporteCobros;
use App\Actions\Reportes\ReporteInventario;
use App\Actions\Reportes\ReporteOrdenes;
use App\Actions\Reportes\ReporteServiciosProformas;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReporteRequest;
use App\Models\Cliente;
use App\Models\User;
use App\Support\Csv;
use App\Support\PeriodoReporte;
use App\Support\Reportes\DocumentoReporte;
use App\Support\Reportes\ExportarExcelReporte;
use App\Support\Reportes\ExportarPdfReporte;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reportes del administrador. Cada endpoint acepta `desde`, `hasta` (por
 * defecto el mes actual en Bolivia), sus filtros y `formato`: json (la
 * pantalla), csv, pdf o xlsx. Los archivos usan los mismos filtros que la
 * pantalla.
 */
class ReporteController extends Controller
{
    public function ordenes(ReporteRequest $request, ReporteOrdenes $reporte): Response
    {
        $periodo = $request->periodo();

        return match ($request->formato()) {
            'csv' => $this->csv('ordenes', $periodo, $reporte->detalle($periodo, $request->clienteId(), $request->operarioId())),
            'pdf', 'xlsx' => $this->exportar($request, $reporte->documento($periodo, $request->clienteId(), $request->operarioId()), conOperario: true),
            default => response()->json($reporte->ejecutar($periodo, $request->clienteId(), $request->operarioId())),
        };
    }

    public function serviciosProformas(ReporteRequest $request, ReporteServiciosProformas $reporte): Response
    {
        $periodo = $request->periodo();

        return match ($request->formato()) {
            'csv' => $this->csv('servicios', $periodo, $reporte->detalle($periodo, $request->clienteId())),
            'pdf', 'xlsx' => $this->exportar($request, $reporte->documento($periodo, $request->clienteId())),
            default => response()->json($reporte->ejecutar($periodo, $request->clienteId())),
        };
    }

    public function inventario(ReporteRequest $request, ReporteInventario $reporte): Response
    {
        $periodo = $request->periodo();

        return match ($request->formato()) {
            'csv' => $this->csv('inventario', $periodo, $reporte->detalle($periodo, $request->clienteId(), $request->operarioId())),
            'pdf', 'xlsx' => $this->exportar($request, $reporte->documento($periodo, $request->clienteId(), $request->operarioId()), conOperario: true),
            default => response()->json($reporte->ejecutar($periodo, $request->clienteId(), $request->operarioId())),
        };
    }

    public function cobros(ReporteRequest $request, ReporteCobros $reporte): Response
    {
        $periodo = $request->periodo();

        return match ($request->formato()) {
            'csv' => $this->csv('cobros', $periodo, $reporte->detalle($request->clienteId())),
            'pdf', 'xlsx' => $this->exportar($request, $reporte->documento($periodo, $request->clienteId())),
            default => response()->json($reporte->ejecutar($periodo, $request->clienteId())),
        };
    }

    /**
     * Opciones del filtro de cliente: solo lo que muestra el selector, sin
     * datos de contacto.
     */
    public function clientes(ReporteRequest $request): JsonResponse
    {
        return response()->json([
            'data' => Cliente::query()
                ->orderBy('nombre_razon')
                ->orderBy('id')
                ->get(['id', 'nombre_razon', 'nit_ci']),
        ]);
    }

    /**
     * @param  array{encabezados: list<string>, filas: list<list<string|int|null>>}  $detalle
     */
    private function csv(string $reporte, PeriodoReporte $periodo, array $detalle): Response
    {
        return Csv::descargar($this->archivo($reporte, $periodo, 'csv'), $detalle['encabezados'], $detalle['filas']);
    }

    /**
     * PDF o Excel con los filtros que pidió el usuario.
     */
    private function exportar(ReporteRequest $request, DocumentoReporte $documento, bool $conOperario = false): Response
    {
        $periodo = $request->periodo();
        $formato = $request->formato();

        $filtros = [
            ['etiqueta' => 'Periodo', 'valor' => $this->fecha($periodo->fechaDesde()).' al '.$this->fecha($periodo->fechaHasta())],
            ['etiqueta' => 'Cliente', 'valor' => $request->clienteId()
                ? (string) Cliente::query()->whereKey($request->clienteId())->value('nombre_razon')
                : 'Todos'],
        ];

        if ($conOperario) {
            $filtros[] = ['etiqueta' => 'Operario', 'valor' => $request->operarioId()
                ? (string) User::query()->whereKey($request->operarioId())->value('name')
                : 'Todos'];
        }

        $documento = $documento->conFiltros($filtros);
        $archivo = $this->archivo($documento->clave, $periodo, $formato);

        return $formato === 'pdf'
            ? app(ExportarPdfReporte::class)->ejecutar($documento, $archivo)
            : app(ExportarExcelReporte::class)->ejecutar($documento, $archivo);
    }

    private function fecha(string $fecha): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $fecha)->format('d/m/Y');
    }

    private function archivo(string $reporte, PeriodoReporte $periodo, string $extension): string
    {
        return sprintf('reporte-%s_%s_%s.%s', $reporte, $periodo->fechaDesde(), $periodo->fechaHasta(), $extension);
    }
}
