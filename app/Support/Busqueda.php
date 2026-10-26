<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Búsqueda textual tolerante a mayúsculas y acentos del español.
 *
 * PostgreSQL resuelve `lower()` con el locale de la base, pero no elimina
 * acentos. SQLite solo baja ASCII. Esta clase centraliza una expresión SQL
 * equivalente para que "nandu" encuentre "Ñandú" en ambos motores.
 */
final class Busqueda
{
    /**
     * @var array<string, string>
     */
    private const MAPA_MINUSCULAS = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ];

    /**
     * SQLite no baja los caracteres acentuados con `lower()`, así que también
     * reemplazamos las variantes mayúsculas después de aplicar lower().
     *
     * @var array<string, string>
     */
    private const MAPA_SQLITE = self::MAPA_MINUSCULAS + [
        'Á' => 'a',
        'É' => 'e',
        'Í' => 'i',
        'Ó' => 'o',
        'Ú' => 'u',
        'Ü' => 'u',
        'Ñ' => 'n',
    ];

    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($texto, self::MAPA_MINUSCULAS);
    }

    /**
     * Expresión SQL normalizada para una columna o expresión.
     */
    public static function expresion(string $columna): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => sprintf(
                "translate(lower(%s), '%s', '%s')",
                $columna,
                implode('', array_keys(self::MAPA_MINUSCULAS)),
                implode('', array_values(self::MAPA_MINUSCULAS)),
            ),
            'sqlite' => self::expresionSqlite($columna),
            default => "lower({$columna})",
        };
    }

    /**
     * Condición `LIKE` completa, con escape de comodines del usuario.
     *
     * PostgreSQL y MySQL usan la barra invertida como escape por defecto.
     * SQLite necesita la cláusula explícita. Además, pdo_pgsql interpreta
     * erróneamente la secuencia `escape '\'` al contar marcadores `?`
     * (el backslash "escapa" la comilla y los marcadores siguientes se
     * pierden), por eso en PostgreSQL se omite la cláusula.
     */
    public static function like(string $columna): string
    {
        $condicion = self::expresion($columna).' like ?';

        if (DB::connection()->getDriverName() === 'sqlite') {
            $condicion .= " escape '\\'";
        }

        return $condicion;
    }

    /**
     * Patrón `%término%` normalizado y con `%`, `_` y `\` escapados.
     */
    public static function patron(string $termino): string
    {
        $normalizado = self::normalizar($termino);
        $escapado = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $normalizado,
        );

        return '%'.$escapado.'%';
    }

    private static function expresionSqlite(string $columna): string
    {
        $expresion = "lower({$columna})";

        foreach (self::MAPA_SQLITE as $acento => $plano) {
            $expresion = "replace({$expresion}, '{$acento}', '{$plano}')";
        }

        return $expresion;
    }
}
