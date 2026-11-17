<?php

declare(strict_types=1);

namespace App\Support\Reportes;

/**
 * Lo que se exporta de un reporte a PDF o Excel, independiente del formato.
 *
 * - `resumen`: los indicadores de la pantalla (salen de `ejecutar()`), así los
 *   totales del archivo coinciden con lo que ve el administrador.
 * - `tablas`: el detalle, con columnas tipadas para dar formato (montos con 2
 *   decimales, fechas como fecha, etc.) y una fila de totales opcional.
 * - `filtros`: se completan en el controlador con lo que pidió el usuario.
 */
final class DocumentoReporte
{
    /**
     * @param  list<array{etiqueta: string, valor: int|float|string|null, tipo: string}>  $resumen
     * @param  list<TablaReporte>  $tablas
     * @param  list<array{etiqueta: string, valor: string}>  $filtros
     */
    public function __construct(
        public readonly string $titulo,
        public readonly string $clave,
        public readonly array $resumen,
        public readonly array $tablas,
        public readonly bool $horizontal = false,
        public readonly array $filtros = [],
        public readonly ?string $nota = null,
    ) {}

    /**
     * @param  list<array{etiqueta: string, valor: string}>  $filtros
     */
    public function conFiltros(array $filtros): self
    {
        return new self(
            $this->titulo,
            $this->clave,
            $this->resumen,
            $this->tablas,
            $this->horizontal,
            $filtros,
            $this->nota,
        );
    }

    /** El mismo documento con cada tabla recortada a `$maximo` filas. */
    public function conTablasLimitadas(int $maximo): self
    {
        return new self(
            $this->titulo,
            $this->clave,
            $this->resumen,
            array_map(fn (TablaReporte $tabla): TablaReporte => $tabla->limitada($maximo), $this->tablas),
            $this->horizontal,
            $this->filtros,
            $this->nota,
        );
    }

    /**
     * @return array{etiqueta: string, valor: int|float|string|null, tipo: string}
     */
    public static function dato(string $etiqueta, int|float|string|null $valor, string $tipo = TablaReporte::ENTERO): array
    {
        return ['etiqueta' => $etiqueta, 'valor' => $valor, 'tipo' => $tipo];
    }
}
