<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Cliente;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * C-02: dos clientes de la misma empresa no pueden tener el mismo NIT/CI.
 * Vacío y "0" (consumidor final) no cuentan. Se compara sin espacios y sin
 * distinguir mayúsculas. El mensaje nombra al cliente que ya lo tiene.
 */
final class NitCiUnico implements ValidationRule
{
    public function __construct(private readonly ?int $ignorarClienteId = null) {}

    public static function normalizar(?string $nitCi): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', (string) $nitCi));
    }

    public static function cuenta(?string $nitCi): bool
    {
        $valor = self::normalizar($nitCi);

        return $valor !== '' && $valor !== '0';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::cuenta($value)) {
            return;
        }

        // El cliente pertenece a la empresa actual por el scope global.
        $existente = Cliente::query()
            ->whereNotNull('nit_ci')
            ->when($this->ignorarClienteId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->whereRaw("upper(replace(nit_ci, ' ', '')) = ?", [self::normalizar($value)])
            ->orderBy('id')
            ->first(['id', 'nombre_razon']);

        if ($existente !== null) {
            $fail("El NIT/CI {$value} ya está registrado para el cliente «{$existente->nombre_razon}».");
        }
    }
}
