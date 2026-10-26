<?php

namespace App\Http\Controllers\Api;

use App\Actions\Dashboard\ObtenerResumenDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ObtenerResumenDashboard $resumen): JsonResponse
    {
        abort_unless($request->user()?->rol?->esInterno(), 403);

        return response()->json($resumen->ejecutar());
    }
}
