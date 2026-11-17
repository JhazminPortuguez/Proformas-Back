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
    /**
     * Filas de detalle por tabla en el PDF. dompdf maqueta cada celda en
     * memoria: con ~1000 filas pedía ~580 MB y ~40 s y la petición caía con
     * un 500. Los totales siguen siendo de todas las filas y el documento
     * avisa que el Excel y el CSV traen el detalle completo.
     */
    public const MAXIMO_FILAS = 200;

    public function ejecutar(DocumentoReporte $documento, string $archivo, int $maximoFilas = self::MAXIMO_FILAS): Response
    {
        $documento = $documento->conTablasLimitadas($maximoFilas);

        // 200 filas piden ~120 MB: se da margen sobre el límite habitual de
        // 128 MB solo mientras se genera el PDF.
        if ($this->bytes((string) ini_get('memory_limit')) < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }

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

    /** "128M" → bytes; -1 (sin límite) cuenta como infinito. */
    private function bytes(string $limite): float
    {
        if ($limite === '-1') {
            return INF;
        }

        $numero = (float) $limite;

        return match (strtoupper(substr($limite, -1))) {
            'G' => $numero * 1024 ** 3,
            'M' => $numero * 1024 ** 2,
            'K' => $numero * 1024,
            default => $numero,
        };
    }

    private static function cantidad(float $valor): string
    {
        $texto = number_format($valor, 3, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }
}
