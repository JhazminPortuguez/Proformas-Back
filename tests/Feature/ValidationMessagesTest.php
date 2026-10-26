<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_errores_de_validacion_se_muestran_en_espanol(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->postJson('/api/v1/servicios', [
            'precio_base' => 'no-es-numero',
        ])->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'El campo nombre es obligatorio.')
            ->assertJsonPath('errors.precio_base.0', 'El campo precio base debe ser un número.');
    }
}
