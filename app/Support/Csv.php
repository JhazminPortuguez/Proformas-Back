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
            fputcsv($salida, $encabezados, self::SEPARADOR, '"', '');

            foreach ($filas as $fila) {
                fputcsv($salida, array_map(
                    fn ($valor): string => $valor === null ? '' : (string) $valor,
                    $fila,
                ), self::SEPARADOR, '"', '');
            }

            fclose($salida);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Monto o cantidad con coma decimal y sin separador de miles: "1234,50". */
    public static function numero(string $valor): string
    {
        return str_replace('.', ',', $valor);
    }
}
