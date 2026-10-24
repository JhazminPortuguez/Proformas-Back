<?php

namespace Database\Factories;

use App\Enums\EstadoProforma;
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
            'numero' => 'PR-2026-'.fake()->unique()->numerify('######'),
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => now()->toDateString(),
            'fecha_validez' => now()->addDays(30)->toDateString(),
            'estado' => EstadoProforma::Borrador,
            'subtotal' => 0,
            'descuento' => 0,
            'impuesto' => 0,
            'total' => 0,
            'observaciones' => null,
        ];
    }
}
