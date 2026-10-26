<?php

namespace Database\Factories;

use App\Enums\EstadoProforma;
use App\Enums\OrigenProforma;
use App\Models\Cliente;
use App\Models\Proforma;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proforma>
 */
class ProformaFactory extends Factory
{
    protected $model = Proforma::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'numero' => sprintf('PR-%d-%06d', now()->year, fake()->unique()->numberBetween(100000, 899999)),
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => now()->toDateString(),
            'fecha_validez' => now()->addDays(30)->toDateString(),
            'estado' => EstadoProforma::Borrador,
            'moneda' => 'BOB',
            'impuesto_tasa' => 0,
            'subtotal' => 0,
            'descuento' => 0,
            'impuesto' => 0,
            'total' => 0,
            'observaciones' => null,
            'origen' => OrigenProforma::Interna,
        ];
    }

    public function enviada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoProforma::Enviada,
            'enviada_at' => now(),
        ]);
    }

    public function aceptada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoProforma::Aceptada,
            'enviada_at' => now()->subDay(),
            'aceptada_at' => now(),
        ]);
    }

    public function rechazada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoProforma::Rechazada,
            'enviada_at' => now()->subDay(),
            'rechazada_at' => now(),
            'motivo_rechazo' => 'No aprobada por el cliente.',
        ]);
    }
}
