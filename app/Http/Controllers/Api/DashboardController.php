<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoProforma;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\Servicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->rol?->esInterno(), 403);

        $porEstado = array_fill_keys(
            array_map(fn (EstadoProforma $estado) => $estado->value, EstadoProforma::cases()),
            0
        );

        Proforma::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->each(function ($total, $estado) use (&$porEstado): void {
                $porEstado[(string) $estado] = (int) $total;
            });

        $inicioMes = now()->startOfMonth()->toDateString();
        $finMes = now()->endOfMonth()->toDateString();

        $montoMes = Proforma::query()
            ->whereBetween('fecha_emision', [$inicioMes, $finMes])
            ->sum('total');

        $actividadProformas = Proforma::query()
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Proforma $proforma) => [
                'tipo' => 'proforma',
                'id' => $proforma->id,
                'descripcion' => $proforma->numero,
                'estado' => $proforma->estado?->value,
                'monto' => (float) $proforma->total,
                'fecha' => $proforma->created_at?->toISOString(),
            ]);

        $actividadClientes = Cliente::query()
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Cliente $cliente) => [
                'tipo' => 'cliente',
                'id' => $cliente->id,
                'descripcion' => $cliente->nombre_razon,
                'estado' => $cliente->estado?->value,
                'monto' => null,
                'fecha' => $cliente->created_at?->toISOString(),
            ]);

        $actividadReciente = $actividadProformas
            ->concat($actividadClientes)
            ->sortByDesc('fecha')
            ->take(5)
            ->values()
            ->all();

        return response()->json([
            'clientes' => Cliente::query()->count(),
            'servicios' => Servicio::query()->count(),
            'proformas' => Proforma::query()->count(),
            'proformas_por_estado' => $porEstado,
            'monto_proformas_mes' => round((float) $montoMes, 2),
            'actividad_reciente' => $actividadReciente,
            'ordenes_pendientes' => null,
            'saldo_por_cobrar' => null,
        ]);
    }
}
