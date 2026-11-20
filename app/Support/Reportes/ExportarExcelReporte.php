<?php

declare(strict_types=1);

namespace App\Support\Reportes;

use App\Models\Empresa;
use App\Support\Csv;
use App\Support\EmpresaActual;
use App\Support\PeriodoReporte;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel (.xlsx real, PhpSpreadsheet) del reporte. Una hoja "Resumen" con la
 * empresa, los filtros, la fecha de generación y los indicadores, y una hoja
 * por tabla con:
 * - encabezados en negrita y filtro automático en esa fila;
 * - montos como número con 2 decimales, fechas como fecha (dd/mm/aaaa),
 *   cantidades y enteros como número;
 * - textos siempre como texto (un "=…" no se convierte en fórmula);
 * - fila de totales en negrita, fuera del rango del filtro;
 * - ancho de columnas ajustado al contenido.
 */
final class ExportarExcelReporte
{
    public const TIPO_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const FORMATO_DINERO = '"Bs" #,##0.00';

    private const FORMATO_FECHA = 'dd/mm/yyyy';

    private const FORMATO_ENTERO = '#,##0';

    private const FORMATO_CANTIDAD = '#,##0.###';

    private const FORMATO_PORCENTAJE = '0.0%';

    private const COLOR = '0F4C81';

    public function libro(DocumentoReporte $documento): Spreadsheet
    {
        $libro = new Spreadsheet;
        $empresa = Empresa::query()->find(EmpresaActual::id());
        $generado = CarbonImmutable::now(PeriodoReporte::ZONA_HORARIA);

        $libro->getProperties()
            ->setTitle($documento->titulo)
            ->setCreator($empresa?->nombre ?? 'Proformas')
            ->setCompany($empresa?->nombre ?? '');

        $this->hojaResumen($libro->getActiveSheet(), $documento, $empresa, $generado);

        foreach ($documento->tablas as $tabla) {
            $this->hojaTabla($libro->createSheet(), $tabla);
        }

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    public function ejecutar(DocumentoReporte $documento, string $archivo): StreamedResponse
    {
        $libro = $this->libro($documento);

        return new StreamedResponse(function () use ($libro): void {
            (new Xlsx($libro))->save('php://output');
            $libro->disconnectWorksheets();
        }, 200, [
            'Content-Type' => self::TIPO_MIME,
            'Content-Disposition' => 'attachment; filename="'.$archivo.'"',
            'Cache-Control' => 'max-age=0, no-store',
        ]);
    }

    private function hojaResumen(Worksheet $hoja, DocumentoReporte $documento, ?Empresa $empresa, CarbonImmutable $generado): void
    {
        $hoja->setTitle('Resumen');

        $fila = 1;
        $this->texto($hoja, 1, $fila, $documento->titulo);
        $hoja->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::COLOR);
        $fila++;

        foreach (array_filter([
            $empresa?->nombre,
            $empresa?->nit ? 'NIT: '.$empresa->nit : null,
            implode(' · ', array_filter([$empresa?->direccion, $empresa?->telefono, $empresa?->email])) ?: null,
            'Generado el '.$generado->format('d/m/Y').' a las '.$generado->format('H:i').' (hora de Bolivia)',
        ]) as $linea) {
            $this->texto($hoja, 1, $fila++, $linea);
        }

        $fila++;

        foreach ($documento->filtros as $filtro) {
            $this->texto($hoja, 1, $fila, $filtro['etiqueta']);
            $this->texto($hoja, 2, $fila, $filtro['valor']);
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
            $fila++;
        }

        $fila++;
        $encabezado = $fila;
        $this->texto($hoja, 1, $fila, 'Indicador');
        $this->texto($hoja, 2, $fila, 'Valor');
        $this->estiloEncabezado($hoja, "A{$fila}:B{$fila}");
        $fila++;

        foreach ($documento->resumen as $dato) {
            $this->texto($hoja, 1, $fila, $dato['etiqueta']);
            $this->valor($hoja, 2, $fila, $dato['valor'], $dato['tipo']);
            $fila++;
        }

        $hoja->setAutoFilter("A{$encabezado}:B".($fila - 1));

        if ($documento->nota) {
            $fila++;
            $this->texto($hoja, 1, $fila, $documento->nota);
            $hoja->getStyle("A{$fila}")->getFont()->setItalic(true)->getColor()->setRGB('64748B');
        }

        $hoja->getColumnDimension('A')->setWidth(38);
        $hoja->getColumnDimension('B')->setWidth(22);
    }

    private function hojaTabla(Worksheet $hoja, TablaReporte $tabla): void
    {
        $hoja->setTitle($this->nombreHoja($tabla->titulo));

        $columnas = count($tabla->columnas);
        $ultima = Coordinate::stringFromColumnIndex($columnas);

        foreach ($tabla->columnas as $indice => $columna) {
            $this->texto($hoja, $indice + 1, 1, $columna['titulo']);
        }

        $this->estiloEncabezado($hoja, "A1:{$ultima}1");
        $hoja->freezePane('A2');

        $fila = 2;

        foreach ($tabla->filas as $datos) {
            foreach ($tabla->columnas as $indice => $columna) {
                $this->valor($hoja, $indice + 1, $fila, $datos[$indice] ?? null, $columna['tipo']);
            }

            $fila++;
        }

        if ($tabla->filas === []) {
            $this->texto($hoja, 1, $fila, $tabla->vacio);
            $hoja->getStyle("A{$fila}")->getFont()->setItalic(true);
            $fila++;
        }

        // El filtro cubre encabezados y datos; la fila de totales queda fuera
        // para que ordenar o filtrar no la mueva.
        $hoja->setAutoFilter("A1:{$ultima}".max(1, $fila - 1));

        if ($tabla->totales !== null) {
            foreach ($tabla->columnas as $indice => $columna) {
                $valor = $tabla->totales[$indice] ?? null;

                if ($indice === 0) {
                    $this->texto($hoja, 1, $fila, (string) $valor);
                } else {
                    $this->valor($hoja, $indice + 1, $fila, $valor, $columna['tipo']);
                }
            }

            $estilo = $hoja->getStyle("A{$fila}:{$ultima}{$fila}");
            $estilo->getFont()->setBold(true);
            $estilo->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::COLOR);
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        }

        foreach (range(1, $columnas) as $indice) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->setAutoSize(true);
        }

        $hoja->calculateColumnWidths();

        // Un tope razonable para nombres muy largos; el resto se ve al
        // seleccionar la celda.
        foreach ($tabla->columnas as $posicion => $columna) {
            $dimension = $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($posicion + 1));
            // El encabezado va en negrita: cuenta como un poco más ancho.
            $minimo = max(10, (int) ceil(mb_strlen($columna['titulo']) * 1.25));
            $ancho = min(max($dimension->getWidth(), $minimo), 60);
            // El cálculo automático no cuenta el prefijo "Bs " del formato.
            $extra = $columna['tipo'] === TablaReporte::DINERO ? 4 : 0;
            $dimension->setAutoSize(false)->setWidth($ancho + 2 + $extra);
        }
    }

    private function valor(Worksheet $hoja, int $columna, int $fila, int|float|string|null $valor, string $tipo): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $celda = Coordinate::stringFromColumnIndex($columna).$fila;

        switch ($tipo) {
            case TablaReporte::DINERO:
                $hoja->setCellValueExplicit($celda, (float) $valor, DataType::TYPE_NUMERIC);
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATO_DINERO);
                break;
            case TablaReporte::CANTIDAD:
                $hoja->setCellValueExplicit($celda, (float) $valor, DataType::TYPE_NUMERIC);
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(
                    fmod((float) $valor, 1.0) === 0.0 ? self::FORMATO_ENTERO : self::FORMATO_CANTIDAD,
                );
                break;
            case TablaReporte::ENTERO:
                $hoja->setCellValueExplicit($celda, (int) $valor, DataType::TYPE_NUMERIC);
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATO_ENTERO);
                break;
            case TablaReporte::PORCENTAJE:
                $hoja->setCellValueExplicit($celda, (float) $valor / 100, DataType::TYPE_NUMERIC);
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATO_PORCENTAJE);
                break;
            case TablaReporte::FECHA:
                $hoja->setCellValueExplicit(
                    $celda,
                    FechaExcel::PHPToExcel(CarbonImmutable::createFromFormat('!Y-m-d', (string) $valor, 'UTC')),
                    DataType::TYPE_NUMERIC,
                );
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATO_FECHA);
                break;
            default:
                $this->texto($hoja, $columna, $fila, (string) $valor);
        }
    }

    /**
     * Siempre como texto. Si empieza con =, +, -, @, tabulación o retorno
     * (la misma regla del CSV), la celda lleva además quotePrefix: Excel la
     * mantiene como texto aunque el usuario la edite y pulse Enter.
     */
    private function texto(Worksheet $hoja, int $columna, int $fila, string $valor): void
    {
        $celda = Coordinate::stringFromColumnIndex($columna).$fila;

        $hoja->setCellValueExplicit($celda, $valor, DataType::TYPE_STRING);

        if (Csv::pareceFormula($valor)) {
            $hoja->getStyle($celda)->setQuotePrefix(true);
        }
    }

    private function estiloEncabezado(Worksheet $hoja, string $rango): void
    {
        $estilo = $hoja->getStyle($rango);
        $estilo->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR);
    }

    /** Excel admite hasta 31 caracteres y no admite : \ / ? * [ ]. */
    private function nombreHoja(string $titulo): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $titulo), 0, 31);
    }
}
