<?php

declare(strict_types=1);

namespace App\Support\Reportes;

/**
 * Una tabla del reporte. Cada columna tiene un tipo que decide su formato:
 * en Excel, el formato numérico o de fecha de la celda; en el PDF, cómo se
 * escribe el valor (Bs 1.234,50; 17/11/2026…).
 */
final class TablaReporte
{
    public const TEXTO = 'texto';

    public const FECHA = 'fecha';

    public const DINERO = 'dinero';

    public const CANTIDAD = 'cantidad';

    public const ENTERO = 'entero';

    public const PORCENTAJE = 'porcentaje';

    /**
     * @param  list<array{titulo: string, tipo: string}>  $columnas
     * @param  list<list<int|float|string|null>>  $filas  fechas como 'Y-m-d', números como número o string decimal
     * @param  list<int|float|string|null>|null  $totales  misma cantidad de celdas que columnas
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $columnas,
        public readonly array $filas,
        public readonly ?array $totales = null,
        public readonly string $vacio = 'Sin datos para los filtros elegidos.',
        public readonly int $omitidas = 0,
    ) {}

    /**
     * Las primeras `$maximo` filas, para el PDF. Los totales no cambian
     * (siguen siendo los de todas las filas) y `omitidas` dice cuántas
     * quedaron fuera, para avisarlo en el documento.
     */
    public function limitada(int $maximo): self
    {
        $total = count($this->filas);

        if ($total <= $maximo) {
            return $this;
        }

        return new self(
            $this->titulo,
            $this->columnas,
            array_slice($this->filas, 0, $maximo),
            $this->totales,
            $this->vacio,
            $total - $maximo,
        );
    }

    /**
     * @return array{titulo: string, tipo: string}
     */
    public static function columna(string $titulo, string $tipo = self::TEXTO): array
    {
        return ['titulo' => $titulo, 'tipo' => $tipo];
    }

    public static function esNumerica(string $tipo): bool
    {
        return in_array($tipo, [self::DINERO, self::CANTIDAD, self::ENTERO, self::PORCENTAJE], true);
    }
}
