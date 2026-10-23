<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_api_responde_cors_al_front_con_credenciales(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3001'])
            ->getJson('/api/clientes');

        $response->assertStatus(401);
        $this->assertSame(
            'http://localhost:3001',
            $response->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertSame(
            'true',
            $response->headers->get('Access-Control-Allow-Credentials')
        );
    }

    public function test_preflight_con_credenciales_responde_204(): void
    {
        $response = $this->call('OPTIONS', '/api/clientes', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3001',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, X-XSRF-TOKEN',
        ]);

        $response->assertStatus(204);
        $this->assertSame(
            'true',
            $response->headers->get('Access-Control-Allow-Credentials')
        );
    }

    public function test_origen_no_listado_no_recibe_cors(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://malicioso.test'])
            ->getJson('/api/clientes');

        $response->assertStatus(401);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
