<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_desactivado_pierde_el_acceso_aunque_tenga_sesion(): void
    {
        $user = User::factory()->create(['activo' => true]);

        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['activo' => false]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está desactivada.');
    }

    public function test_admin_desactiva_usuario_y_la_sesion_ya_no_entra(): void
    {
        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $user = User::factory()->create(['activo' => true]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}", [
            'activo' => false,
        ])->assertOk();

        $user->refresh();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está desactivada.');
    }
}
