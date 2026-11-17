<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga CSV pensada para abrirse con Excel en español: UTF-8 con BOM,
 * columnas separadas por punto y coma y decimales con coma.
 */
final class Csv
{
    private const SEPARADOR = ';';

    /**
     * @param  list<string>  $encabezados
     * @param  iterable<array<int, string|int|float|null>>  $filas
     */
    public static function descargar(string $archivo, array $encabezados, iterable $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($encabezados, $filas): void {
            $salida = fopen('php://output', 'w');

            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, array_map(self::celda(...), $encabezados), self::SEPARADOR, '"', '');

            foreach ($filas as $fila) {
                fputcsv($salida, array_map(self::celda(...), $fila), self::SEPARADOR, '"', '');
            }

            fclose($salida);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Valor de una celda. Un texto que empieza con =, +, -, @, tabulación o
     * retorno de carro se antepone con un apóstrofo para que Excel no lo
     * ejecute como fórmula (CSV injection). Los números, también negativos y
     * con coma decimal ("-12,50"), no se tocan.
     */
    public static function celda(string|int|float|null $valor): string
    {
        if ($valor === null) {
            return '';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $valor) === 1) {
            return $valor;
        }

        if (self::pareceFormula($valor)) {
            return "'".$valor;
        }

        return $valor;
    }

    /**
     * Un texto que Excel podría tomar como fórmula: empieza con =, +, -, @,
     * tabulación o retorno de carro. Lo usan el CSV (apóstrofo) y el Excel
     * (celda con quotePrefix).
     */
    public static function pareceFormula(string $valor): bool
    {
        return $valor !== '' && str_contains("=+-@\t\r", $valor[0]);
    }

    /** Monto con coma decimal y sin separador de miles: "1234,50". */
    public static function numero(string $valor): string
    {
        return str_replace('.', ',', $valor);
    }

    /** Cantidad sin ceros sobrantes: "2.000" → "2", "1.500" → "1,5". */
    public static function cantidad(string $valor): string
    {
        if (str_contains($valor, '.')) {
            $valor = rtrim(rtrim($valor, '0'), '.');
        }

        return self::numero($valor);
    }
}
