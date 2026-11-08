<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Models\Empresa;
use App\Models\Proforma;
use App\Support\EmpresaActual;
use App\Support\Monedas;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * PDF oficial de la proforma. Lo usan el sistema interno y el portal del
 * cliente, así ambos descargan exactamente el mismo documento.
 */
final class DescargarPdfProforma
{
    public function ejecutar(Proforma $proforma): Response
    {
        $proforma->load('cliente', 'items.producto', 'items.servicio', 'aceptador');

        $empresa = Empresa::query()->find(EmpresaActual::id())
            ?? new Empresa(['nombre' => 'Empresa']);

        $pdf = Pdf::loadView('proformas.pdf', [
            'proforma' => $proforma,
            'cliente' => $proforma->cliente,
            'items' => $proforma->items,
            'empresa' => $empresa,
            'moneda' => $proforma->moneda ?: Monedas::POR_DEFECTO,
            'simbolo' => Monedas::simbolo($proforma->moneda ?: Monedas::POR_DEFECTO),
        ])->setPaper('a4');

        return $pdf->download("{$proforma->numero}.pdf");
    }
}
