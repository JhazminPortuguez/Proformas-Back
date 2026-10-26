<?php

namespace Database\Factories;

use App\Enums\EstadoOrden;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenServicio>
 */
class OrdenServicioFactory extends Factory
{
    protected $model = OrdenServicio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proforma_id' => Proforma::factory()->aceptada(),
            'cliente_id' => function (array $attributes): int {
                return (int) Proforma::query()
                    ->withoutGlobalScope('empresa')
                    ->whereKey($attributes['proforma_id'])
                    ->value('cliente_id');
            },
            'numero' => sprintf('OS-%d-%06d', now()->year, fake()->unique()->numberBetween(100000, 899999)),
            'operario_id' => null,
            'estado' => EstadoOrden::Pendiente,
            'fecha_programada' => null,
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'observaciones' => null,
            'motivo_cancelacion' => null,
            'cancelada_at' => null,
            'cerrada_at' => null,
            'cerrada_por' => null,
        ];
    }

    public function paraProforma(Proforma $proforma): static
    {
        return $this->state(fn () => [
            'proforma_id' => $proforma->id,
            'cliente_id' => $proforma->cliente_id,
        ]);
    }

    public function asignada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrden::Asignada,
        ]);
    }

    public function enProceso(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrden::EnProceso,
            'fecha_inicio' => now()->subHour(),
        ]);
    }

    public function finalizada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrden::Finalizada,
            'fecha_inicio' => now()->subHours(2),
            'fecha_fin' => now()->subHour(),
        ]);
    }

    public function cancelada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoOrden::Cancelada,
            'motivo_cancelacion' => 'Cancelada en pruebas.',
            'cancelada_at' => now(),
        ]);
    }
}
