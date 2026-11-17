<?php

declare(strict_types=1);

namespace App\Support\Reportes;

use App\Models\Empresa;
use App\Support\EmpresaActual;
use App\Support\PeriodoReporte;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * PDF del reporte (dompdf, como la proforma): logo y datos de la empresa,
 * título, filtros, fecha y hora de generación en Bolivia, resumen, tablas
 * con totales y "Página X de Y". Carta; horizontal si la tabla es ancha.
 */
final class ExportarPdfReporte
{
    public function ejecutar(DocumentoReporte $documento, string $archivo): Response
    {
        $empresa = Empresa::query()->find(EmpresaActual::id())
            ?? new Empresa(['nombre' => 'Empresa']);

        $pdf = Pdf::loadView('reportes.pdf', [
            'documento' => $documento,
            'empresa' => $empresa,
            'generado' => CarbonImmutable::now(PeriodoReporte::ZONA_HORARIA),
        ])
            ->setPaper('letter', $documento->horizontal ? 'landscape' : 'portrait')
            // Solo los glifos usados: sin esto DejaVu se incrusta entera (~0,9 MB).
            ->setOption('isFontSubsettingEnabled', true);

        $pdf->render();

        // El total de páginas solo se conoce al final: dompdf lo escribe en
        // cada página con page_text.
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 112,
            $canvas->get_height() - 26,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $fuente,
            8,
            [0.39, 0.45, 0.55],
        );

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$archivo.'"',
        ]);
    }

    /**
     * Valor de una celda para el PDF, con el formato que se ve en pantalla.
     */
    public static function formatear(int|float|string|null $valor, string $tipo): string
    {
        if ($valor === null || $valor === '') {
            return $tipo === TablaReporte::PORCENTAJE ? '—' : '';
        }

        return match ($tipo) {
            TablaReporte::DINERO => 'Bs '.number_format((float) $valor, 2, ',', '.'),
            TablaReporte::CANTIDAD => self::cantidad((float) $valor),
            TablaReporte::ENTERO => number_format((float) $valor, 0, ',', '.'),
            TablaReporte::PORCENTAJE => number_format((float) $valor, 1, ',', '.').' %',
            TablaReporte::FECHA => CarbonImmutable::parse((string) $valor)->format('d/m/Y'),
            default => (string) $valor,
        };
    }

    private static function cantidad(float $valor): string
    {
        $texto = number_format($valor, 3, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }
}
