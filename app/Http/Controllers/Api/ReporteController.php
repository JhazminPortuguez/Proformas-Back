<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reportes\ReporteOrdenes;
use App\Actions\Reportes\ReporteServiciosProformas;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReporteRequest;
use App\Support\Csv;
use App\Support\PeriodoReporte;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes del administrador. Cada endpoint acepta `desde`, `hasta` (por
 * defecto el mes actual en Bolivia), sus filtros y `formato=csv`.
 */
class ReporteController extends Controller
{
    public function ordenes(ReporteRequest $request, ReporteOrdenes $reporte): JsonResponse|StreamedResponse
    {
        $periodo = $request->periodo();

        if ($request->quiereCsv()) {
            $detalle = $reporte->detalle($periodo, $request->clienteId(), $request->operarioId());

            return Csv::descargar($this->archivo('ordenes', $periodo), $detalle['encabezados'], $detalle['filas']);
        }

        return response()->json($reporte->ejecutar($periodo, $request->clienteId(), $request->operarioId()));
    }

    public function serviciosProformas(ReporteRequest $request, ReporteServiciosProformas $reporte): JsonResponse|StreamedResponse
    {
        $periodo = $request->periodo();

        if ($request->quiereCsv()) {
            $detalle = $reporte->detalle($periodo, $request->clienteId());

            return Csv::descargar($this->archivo('servicios', $periodo), $detalle['encabezados'], $detalle['filas']);
        }

        return response()->json($reporte->ejecutar($periodo, $request->clienteId()));
    }

    private function archivo(string $reporte, PeriodoReporte $periodo): string
    {
        return sprintf('reporte-%s_%s_%s.csv', $reporte, $periodo->fechaDesde(), $periodo->fechaHasta());
    }
}
