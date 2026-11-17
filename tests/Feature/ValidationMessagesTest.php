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

    public function test_un_valor_fuera_del_enum_se_explica_en_espanol(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        // Antes: "The selected estado is invalid." (faltaba la clave `enum`).
        $this->actingAs($admin)->postJson('/api/v1/clientes', [
            'nombre_razon' => 'Cliente enum',
            'estado' => 'ACTIVO',
        ])->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El valor seleccionado para estado no es válido.');
    }

    public function test_los_campos_del_cliente_se_nombran_en_espanol(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);

        $this->actingAs($admin)->postJson('/api/v1/clientes', [
            'nombre_razon' => str_repeat('a', 201),
            'nit_ci' => str_repeat('1', 31),
            'telefono' => str_repeat('7', 31),
            'email' => 'no-es-correo',
            'direccion' => str_repeat('d', 256),
        ])->assertStatus(422)
            ->assertJsonPath('errors.nombre_razon.0', 'El campo nombre o razón social no debe tener más de 200 caracteres.')
            ->assertJsonPath('errors.nit_ci.0', 'El campo NIT/CI no debe tener más de 30 caracteres.')
            ->assertJsonPath('errors.telefono.0', 'El campo teléfono no debe tener más de 30 caracteres.')
            ->assertJsonPath('errors.email.0', 'El campo correo electrónico debe ser una dirección de correo válida.')
            ->assertJsonPath('errors.direccion.0', 'El campo dirección no debe tener más de 255 caracteres.');
    }

    public function test_el_archivo_en_espanol_traduce_todas_las_reglas_de_laravel(): void
    {
        $ingles = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $espanol = require lang_path('es/validation.php');

        $faltan = array_diff(array_keys($ingles), array_keys($espanol));

        $this->assertSame([], array_values($faltan), 'Reglas sin mensaje en español: '.implode(', ', $faltan));
    }
}
