<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmética monetaria exacta basada en bcmath (centavos / strings).
 *
 * Las decisiones de negocio (comparar, sumar, redondear) nunca se toman con
 * floats. Los floats solo se aceptan como entrada y se normalizan a string.
 */
final class Dinero
{
    public const ESCALA_MONTO = 2;

    public const ESCALA_CANTIDAD = 3;

    public const MONTO_MAXIMO = '9999999999.99';

    private const ESCALA_TRABAJO = 6;

    public static function redondear(float|int|string|null $monto, int $escala = self::ESCALA_MONTO): string
    {
        $valor = self::normalizar($monto);
        $escala = max(0, $escala);

        if (bccomp($valor, '0', self::ESCALA_TRABAJO) === 0) {
            return self::formatear('0', $escala);
        }

        $ajuste = '0.'.str_repeat('0', $escala).'5';

        if (bccomp($valor, '0', self::ESCALA_TRABAJO) < 0) {
            $ajuste = '-'.$ajuste;
        }

        $redondeado = bcadd($valor, $ajuste, $escala + 1);

        return self::formatear(bcadd($redondeado, '0', $escala), $escala);
    }

    public static function totalLinea(
        float|int|string $cantidad,
        float|int|string $precio,
    ): string {
        $producto = bcmul(
            self::normalizar($cantidad),
            self::normalizar($precio),
            self::ESCALA_TRABAJO
        );

        return self::redondear($producto);
    }

    public static function sumar(float|int|string ...$montos): string
    {
        $acumulado = '0';

        foreach ($montos as $monto) {
            $acumulado = bcadd($acumulado, self::normalizar($monto), self::ESCALA_TRABAJO);
        }

        return self::redondear($acumulado);
    }

    public static function restar(float|int|string $minuendo, float|int|string $sustraendo): string
    {
        return self::redondear(
            bcsub(
                self::normalizar($minuendo),
                self::normalizar($sustraendo),
                self::ESCALA_TRABAJO
            )
        );
    }

    public static function porcentaje(float|int|string $base, float|int|string $tasa): string
    {
        $producto = bcmul(
            self::normalizar($base),
            self::normalizar($tasa),
            self::ESCALA_TRABAJO
        );

        $resultado = bcdiv($producto, '100', self::ESCALA_TRABAJO);

        return self::redondear($resultado);
    }

    public static function comparar(float|int|string $a, float|int|string $b): int
    {
        return bccomp(
            self::normalizar($a),
            self::normalizar($b),
            self::ESCALA_TRABAJO
        );
    }

    public static function mayorQue(float|int|string $a, float|int|string $b): bool
    {
        return self::comparar($a, $b) > 0;
    }

    public static function esCero(float|int|string|null $monto): bool
    {
        return self::comparar($monto ?? 0, 0) === 0;
    }

    public static function cantidad(float|int|string|null $cantidad): string
    {
        return self::redondear($cantidad ?? 0, self::ESCALA_CANTIDAD);
    }

    /**
     * Convierte un monto a centavos enteros (string) sin perder precisión.
     */
    public static function aCentavos(float|int|string|null $monto): string
    {
        return bcmul(self::redondear($monto), '100', 0);
    }

    public static function desdeCentavos(float|int|string $centavos): string
    {
        return self::redondear(
            bcdiv(self::normalizar($centavos), '100', self::ESCALA_TRABAJO)
        );
    }

    public static function formatear(float|int|string|null $monto, int $escala = self::ESCALA_MONTO): string
    {
        $valor = self::normalizar($monto);
        $escala = max(0, $escala);

        if ($escala === 0) {
            $entero = bcadd($valor, '0', 0);

            return $entero === '-0' ? '0' : $entero;
        }

        $formateado = bcadd($valor, '0', $escala);

        if (bccomp($formateado, '0', $escala) === 0) {
            return '0.'.str_repeat('0', $escala);
        }

        return $formateado;
    }

    private static function normalizar(float|int|string|null $monto): string
    {
        if ($monto === null) {
            return '0';
        }

        if (is_string($monto)) {
            $monto = trim($monto);

            if ($monto === '') {
                return '0';
            }

            if (! is_numeric($monto)) {
                throw new InvalidArgumentException("Monto no numérico: {$monto}");
            }

            // bcmath no acepta notación científica; se normaliza a decimal.
            if (stripos($monto, 'e') !== false) {
                return number_format((float) $monto, 10, '.', '');
            }

            return $monto;
        }

        if (is_int($monto)) {
            return (string) $monto;
        }

        if (! is_finite($monto)) {
            throw new InvalidArgumentException('Monto no finito.');
        }

        return number_format($monto, 10, '.', '');
    }
}
