<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolTest extends TestCase
{
    use RefreshDatabase;

    public function test_rol_por_defecto_es_vendedor(): void
    {
        $user = User::factory()->create();

        $this->assertSame('vendedor', $user->rol);
    }

    public function test_rol_es_asignable_masivamente(): void
    {
        $user = User::factory()->create(['rol' => 'admin']);

        $this->assertSame('admin', $user->fresh()->rol);
    }
}
