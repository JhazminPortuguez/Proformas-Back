<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolTest extends TestCase
{
    use RefreshDatabase;

    public function test_rol_por_defecto_es_cliente(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Rol::Cliente, $user->rol);
    }

    public function test_rol_es_asignable_masivamente(): void
    {
        $user = User::factory()->create(['rol' => Rol::Admin]);

        $this->assertSame(Rol::Admin, $user->fresh()->rol);
    }

    public function test_solo_admin_y_operario_son_internos(): void
    {
        $this->assertTrue(Rol::Admin->esInterno());
        $this->assertTrue(Rol::Operario->esInterno());
        $this->assertFalse(Rol::Cliente->esInterno());
    }

    public function test_admin_y_operario_gestionan_datos_pero_no_el_cliente(): void
    {
        $this->assertTrue(Rol::Admin->puedeGestionarDatos());
        $this->assertTrue(Rol::Operario->puedeGestionarDatos());
        $this->assertFalse(Rol::Cliente->puedeGestionarDatos());
    }

    public function test_solo_admin_elimina_y_gestiona_usuarios(): void
    {
        $this->assertTrue(Rol::Admin->puedeEliminar());
        $this->assertFalse(Rol::Operario->puedeEliminar());
        $this->assertFalse(Rol::Cliente->puedeEliminar());

        $this->assertTrue(Rol::Admin->puedeGestionarUsuarios());
        $this->assertFalse(Rol::Operario->puedeGestionarUsuarios());
        $this->assertFalse(Rol::Cliente->puedeGestionarUsuarios());
    }
}
