<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

trait RespondePaginado
{
    protected function respuestaPaginada(
        AnonymousResourceCollection $recursos,
        LengthAwarePaginator $pagina,
    ): JsonResponse {
        return response()->json([
            'data' => $recursos->resolve(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'last_page' => $pagina->lastPage(),
                'from' => $pagina->firstItem(),
                'to' => $pagina->lastItem(),
            ],
        ]);
    }
}
