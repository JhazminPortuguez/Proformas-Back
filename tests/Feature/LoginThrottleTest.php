<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_se_bloquea_tras_cinco_intentos_fallidos(): void
    {
        User::factory()->create(['email' => 'a@x.com', 'password' => 'secret123']);

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'a@x.com',
                'password' => 'incorrecta',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@x.com',
            'password' => 'incorrecta',
        ])->assertStatus(429);
    }
}
