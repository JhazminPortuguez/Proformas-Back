<?php

namespace Tests\Feature;

use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrdenServicioTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_orden_desde_proforma_aceptada_con_snapshot_y_evento_de_creacion(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);

        $response = $this->crearOrden($admin, $proforma, [
            'fecha_programada' => '2026-11-05',
            'observaciones' => 'Coordinar el acceso con el cliente.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('numero', sprintf('OS-%d-000001', now()->year))
            ->assertJsonPath('estado', 'PENDIENTE')
            ->assertJsonPath('proforma_id', $proforma->id)
            ->assertJsonPath('cliente_id', $proforma->cliente_id)
            ->assertJsonPath('operario_id', null)
            ->assertJsonPath('fecha_programada', '2026-11-05')
            ->assertJsonPath('observaciones', 'Coordinar el acceso con el cliente.')
            ->assertJsonPath('created_by', $admin->id)
            ->assertJsonPath('updated_by', $admin->id)
            ->assertJsonPath('items_count', 2)
            ->assertJsonPath('proforma.numero', $proforma->numero)
            ->assertJsonPath('eventos.0.tipo', 'CREACION')
            ->assertJsonPath('eventos.0.estado_anterior', null)
            ->assertJsonPath('eventos.0.estado_nuevo', 'PENDIENTE')
            ->assertJsonPath('eventos.0.user_id', $admin->id)
            ->assertJsonPath('acciones_disponibles', ['ver', 'asignar', 'cancelar']);

        $items = collect($response->json('items'))->keyBy('tipo');

        $this->assertSame(2.0, (float) $items['SERVICIO']['cantidad']);
        $this->assertSame(100.0, (float) $items['SERVICIO']['precio_unitario']);
        $this->assertSame(200.0, (float) $items['SERVICIO']['total_linea']);
        $this->assertSame(3.0, (float) $items['MATERIAL']['cantidad']);
        $this->assertSame(150.0, (float) $items['MATERIAL']['total_linea']);

        $this->assertDatabaseHas('ordenes_servicio', [
            'id' => $response->json('id'),
            'proforma_id' => $proforma->id,
            'cliente_id' => $proforma->cliente_id,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('orden_eventos', [
            'orden_id' => $response->json('id'),
            'tipo' => 'CREACION',
            'estado_anterior' => null,
            'estado_nuevo' => 'PENDIENTE',
        ]);
    }

    public function test_el_snapshot_de_items_no_cambia_si_se_modifica_la_proforma(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);

        $ordenId = $this->crearOrden($admin, $proforma)
            ->assertCreated()
            ->json('id');

        $proforma->items()->delete();
        $proforma->items()->create([
            'tipo' => 'LIBRE',
            'producto_id' => null,
            'servicio_id' => null,
            'nombre' => 'Nuevo ítem',
            'descripcion' => 'Contenido cambiado después de crear la orden',
            'unidad_medida' => 'unidad',
            'cantidad' => 9,
            'precio_unitario' => 999,
            'total_linea' => 8991,
        ]);

        $this->assertDatabaseCount('orden_items', 2);

        $this->actingAs($admin)->getJson("/api/v1/ordenes/{$ordenId}")
            ->assertOk()
            ->assertJsonPath('items_count', 2)
            ->assertJsonPath('items.0.tipo', 'SERVICIO')
            ->assertJsonPath('items.1.tipo', 'MATERIAL');
    }

    public function test_no_crea_orden_desde_proforma_borrador_enviada_o_rechazada(): void
    {
        $admin = $this->admin();

        foreach ([EstadoProforma::Borrador, EstadoProforma::Enviada, EstadoProforma::Rechazada] as $estado) {
            $proforma = Proforma::factory()->create([
                'estado' => $estado,
                'enviada_at' => $estado === EstadoProforma::Borrador ? null : now(),
            ]);

            $this->crearOrden($admin, $proforma)
                ->assertStatus(422)
                ->assertJsonValidationErrors('proforma_id');
        }

        $this->assertDatabaseCount('ordenes_servicio', 0);
    }

    public function test_no_duplica_orden_vigente_y_permite_crear_otra_si_la_anterior_fue_cancelada(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);

        $primera = $this->crearOrden($admin, $proforma)
            ->assertCreated()
            ->assertJsonPath('numero', sprintf('OS-%d-000001', now()->year))
            ->json('id');

        $this->crearOrden($admin, $proforma)
            ->assertStatus(422)
            ->assertJsonValidationErrors('proforma_id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$primera}/cancelar", [
            'motivo' => 'El cliente pidió reprogramar.',
        ])->assertOk();

        $this->crearOrden($admin, $proforma)
            ->assertCreated()
            ->assertJsonPath('numero', sprintf('OS-%d-000002', now()->year));

        $this->assertDatabaseCount('ordenes_servicio', 2);
    }

    public function test_correlativo_de_ordenes_es_secuencial_por_anio(): void
    {
        $admin = $this->admin();
        $primera = $this->proformaAceptada($admin);
        $segunda = $this->proformaAceptada($admin);

        $this->crearOrden($admin, $primera)
            ->assertCreated()
            ->assertJsonPath('numero', sprintf('OS-%d-000001', now()->year));

        $this->crearOrden($admin, $segunda)
            ->assertCreated()
            ->assertJsonPath('numero', sprintf('OS-%d-000002', now()->year));

        $this->assertDatabaseHas('orden_sequences', [
            'empresa_id' => 1,
            'anio' => now()->year,
            'ultimo_numero' => 2,
        ]);
    }

    public function test_crear_con_operario_asigna_y_audita_en_la_misma_operacion(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $proforma = $this->proformaAceptada($admin);

        $response = $this->crearOrden($admin, $proforma, ['operario_id' => $operario->id])
            ->assertCreated()
            ->assertJsonPath('estado', 'ASIGNADA')
            ->assertJsonPath('operario_id', $operario->id)
            ->assertJsonPath('acciones_disponibles', ['ver', 'iniciar', 'cancelar']);

        $eventos = collect($response->json('eventos'));

        $this->assertSame(['CREACION', 'ASIGNACION'], $eventos->pluck('tipo')->all());
        $this->assertSame('PENDIENTE', $eventos[1]['estado_anterior']);
        $this->assertSame('ASIGNADA', $eventos[1]['estado_nuevo']);
    }

    public function test_asignar_operario_valido_cambia_estado_y_registra_evento(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $proforma = $this->proformaAceptada($admin);

        $ordenId = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/asignar", [
            'operario_id' => $operario->id,
        ])
            ->assertOk()
            ->assertJsonPath('estado', 'ASIGNADA')
            ->assertJsonPath('operario_id', $operario->id)
            ->assertJsonPath('updated_by', $admin->id)
            ->assertJsonPath('eventos.1.tipo', 'ASIGNACION')
            ->assertJsonPath('eventos.1.estado_anterior', 'PENDIENTE')
            ->assertJsonPath('eventos.1.estado_nuevo', 'ASIGNADA');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/asignar", [
            'operario_id' => $operario->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_no_asigna_usuario_cliente_inactivo_de_otra_empresa_o_inexistente(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);
        $ordenId = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        $otraEmpresa = Empresa::factory()->create();
        $casos = [
            User::factory()->create(['rol' => Rol::Cliente])->id,
            User::factory()->create(['rol' => Rol::Operario, 'activo' => false])->id,
            User::factory()->create(['rol' => Rol::Operario, 'empresa_id' => $otraEmpresa->id])->id,
            999999,
        ];

        foreach ($casos as $operarioId) {
            $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/asignar", [
                'operario_id' => $operarioId,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('operario_id');
        }

        $this->assertDatabaseHas('ordenes_servicio', [
            'id' => $ordenId,
            'estado' => 'PENDIENTE',
            'operario_id' => null,
        ]);
    }

    public function test_operario_no_puede_asignar_ordenes(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $proforma = $this->proformaAceptada($admin);
        $ordenId = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        $this->actingAs($operario)->postJson("/api/v1/ordenes/{$ordenId}/asignar", [
            'operario_id' => $operario->id,
        ])->assertForbidden();
    }

    public function test_flujo_iniciar_finalizar_y_cerrar_registra_estados_fechas_y_eventos(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $proforma = $this->proformaAceptada($admin);
        $ordenId = $this->crearOrden($admin, $proforma, ['operario_id' => $operario->id])
            ->assertCreated()
            ->json('id');

        $this->actingAs($operario)->postJson("/api/v1/ordenes/{$ordenId}/iniciar", [
            'observaciones' => 'Se inicia en sitio.',
        ])
            ->assertOk()
            ->assertJsonPath('estado', 'EN_PROCESO')
            ->assertJsonPath('fecha_inicio', fn ($fecha) => $fecha !== null)
            ->assertJsonPath('eventos.2.tipo', 'INICIO')
            ->assertJsonPath('eventos.2.metadata.observaciones', 'Se inicia en sitio.')
            ->assertJsonPath('acciones_disponibles', ['ver', 'finalizar', 'consumir']);

        $this->actingAs($operario)->postJson("/api/v1/ordenes/{$ordenId}/finalizar", [
            'observaciones' => 'Trabajo concluido.',
        ])
            ->assertOk()
            ->assertJsonPath('estado', 'FINALIZADA')
            ->assertJsonPath('fecha_fin', fn ($fecha) => $fecha !== null)
            ->assertJsonPath('observaciones', 'Trabajo concluido.')
            ->assertJsonPath('eventos.3.tipo', 'FINALIZACION')
            ->assertJsonPath('acciones_disponibles', ['ver', 'consumir']);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cerrar")
            ->assertOk()
            ->assertJsonPath('estado', 'FINALIZADA')
            ->assertJsonPath('cerrada_at', fn ($fecha) => $fecha !== null)
            ->assertJsonPath('cerrada_por', $admin->id)
            ->assertJsonPath('eventos.4.tipo', 'CIERRE')
            ->assertJsonPath('eventos.4.estado_anterior', 'FINALIZADA')
            ->assertJsonPath('eventos.4.estado_nuevo', null)
            ->assertJsonPath('acciones_disponibles', ['ver']);

        $this->assertDatabaseHas('ordenes_servicio', [
            'id' => $ordenId,
            'estado' => 'FINALIZADA',
            'cerrada_por' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cerrar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_transiciones_invalidas_y_saltos_de_estado_son_rechazados(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $proforma = $this->proformaAceptada($admin);

        $pendiente = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        foreach (['iniciar', 'finalizar', 'cerrar'] as $accion) {
            $this->actingAs($admin)->postJson("/api/v1/ordenes/{$pendiente}/{$accion}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('estado');
        }

        $asignada = $this->crearOrden($admin, $this->proformaAceptada($admin), [
            'operario_id' => $operario->id,
        ])->assertCreated()->json('id');

        foreach (['finalizar', 'cerrar'] as $accion) {
            $this->actingAs($admin)->postJson("/api/v1/ordenes/{$asignada}/{$accion}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('estado');
        }

        $enProceso = $this->crearOrden($admin, $this->proformaAceptada($admin), [
            'operario_id' => $operario->id,
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$enProceso}/iniciar")->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$enProceso}/cerrar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$enProceso}/iniciar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $finalizada = $this->crearOrden($admin, $this->proformaAceptada($admin), [
            'operario_id' => $operario->id,
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$finalizada}/iniciar")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$finalizada}/finalizar")->assertOk();

        foreach (['iniciar', 'finalizar'] as $accion) {
            $this->actingAs($admin)->postJson("/api/v1/ordenes/{$finalizada}/{$accion}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('estado');
        }

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$finalizada}/cancelar", [
            'motivo' => 'Intento inválido.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_cancelar_exige_motivo_y_registra_motivo_fecha_y_evento(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);
        $ordenId = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cancelar", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cancelar", [
            'motivo' => 'El cliente desistió del servicio.',
        ])
            ->assertOk()
            ->assertJsonPath('estado', 'CANCELADA')
            ->assertJsonPath('motivo_cancelacion', 'El cliente desistió del servicio.')
            ->assertJsonPath('cancelada_at', fn ($fecha) => $fecha !== null)
            ->assertJsonPath('eventos.1.tipo', 'CANCELACION')
            ->assertJsonPath('eventos.1.estado_anterior', 'PENDIENTE')
            ->assertJsonPath('eventos.1.estado_nuevo', 'CANCELADA')
            ->assertJsonPath('eventos.1.metadata.motivo', 'El cliente desistió del servicio.')
            ->assertJsonPath('acciones_disponibles', ['ver']);

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cancelar", [
            'motivo' => 'Segundo intento.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_operario_solo_ve_y_actua_sobre_sus_ordenes_asignadas(): void
    {
        $admin = $this->admin();
        $operarioA = $this->operario();
        $operarioB = $this->operario();

        $ordenA = $this->crearOrden($admin, $this->proformaAceptada($admin), [
            'operario_id' => $operarioA->id,
        ])->assertCreated()->json('id');

        $ordenB = $this->crearOrden($admin, $this->proformaAceptada($admin), [
            'operario_id' => $operarioB->id,
        ])->assertCreated()->json('id');

        $sinAsignar = $this->crearOrden($admin, $this->proformaAceptada($admin))
            ->assertCreated()
            ->json('id');

        $this->actingAs($operarioA)->getJson('/api/v1/ordenes')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ordenA);

        $this->actingAs($admin)->getJson('/api/v1/ordenes')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($operarioA)->getJson("/api/v1/ordenes/{$ordenA}")->assertOk();
        $this->actingAs($operarioA)->getJson("/api/v1/ordenes/{$ordenB}")->assertForbidden();
        $this->actingAs($operarioA)->getJson("/api/v1/ordenes/{$sinAsignar}")->assertForbidden();

        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$ordenB}/iniciar")->assertForbidden();
        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$sinAsignar}/iniciar")->assertForbidden();
        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$ordenA}/cancelar", [
            'motivo' => 'No permitido al operario.',
        ])->assertForbidden();

        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$ordenA}/iniciar")
            ->assertOk()
            ->assertJsonPath('estado', 'EN_PROCESO');

        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$ordenA}/finalizar")
            ->assertOk()
            ->assertJsonPath('estado', 'FINALIZADA');

        $this->actingAs($operarioA)->postJson("/api/v1/ordenes/{$ordenA}/cerrar")
            ->assertForbidden();
    }

    public function test_cliente_no_accede_al_modulo_de_ordenes(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente]);

        $this->actingAs($cliente)->getJson('/api/v1/ordenes')->assertForbidden();
        $this->actingAs($cliente)->postJson('/api/v1/ordenes', [])->assertForbidden();
    }

    public function test_listado_filtra_por_estado_operario_cliente_y_pagina(): void
    {
        $admin = $this->admin();
        $operario = $this->operario();
        $clienteA = Cliente::factory()->create(['nombre_razon' => 'Taller Ñandú']);
        $clienteB = Cliente::factory()->create(['nombre_razon' => 'Oficinas del Sur']);

        $proformaA = $this->proformaAceptada($admin, $clienteA);
        $proformaB = $this->proformaAceptada($admin, $clienteB);

        $ordenA = $this->crearOrden($admin, $proformaA, ['operario_id' => $operario->id])
            ->assertCreated()
            ->json('numero');

        $ordenB = $this->crearOrden($admin, $proformaB)
            ->assertCreated()
            ->json('numero');

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?estado=ASIGNADA')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero', $ordenA);

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?operario_id='.$operario->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero', $ordenA);

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?cliente_id='.$clienteB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero', $ordenB);

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_listado_busca_por_numero_cliente_y_proforma(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->create(['nombre_razon' => 'Taller Ñandú']);
        $proforma = $this->proformaAceptada($admin, $cliente);
        $numero = $this->crearOrden($admin, $proforma)->assertCreated()->json('numero');

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?search='.urlencode($numero))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero', $numero);

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?search=nandu')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero', $numero);

        $this->actingAs($admin)
            ->getJson('/api/v1/ordenes?search='.urlencode($proforma->numero))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero', $numero);
    }

    public function test_proforma_expone_orden_id_y_accion_crear_orden_segun_corresponda(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);

        $this->actingAs($admin)->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('orden_id', null)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => in_array('crear_orden', $acciones, true));

        $ordenId = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        $this->actingAs($admin)->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('orden_id', $ordenId)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => ! in_array('crear_orden', $acciones, true));

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$ordenId}/cancelar", [
            'motivo' => 'Se emitirá una nueva orden.',
        ])->assertOk();

        $this->actingAs($admin)->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('orden_id', $ordenId)
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => in_array('crear_orden', $acciones, true));

        $enviada = Proforma::factory()->create(['estado' => EstadoProforma::Enviada]);

        $this->actingAs($admin)->getJson("/api/v1/proformas/{$enviada->id}")
            ->assertOk()
            ->assertJsonPath('acciones_disponibles', fn ($acciones) => ! in_array('crear_orden', $acciones, true));
    }

    public function test_factories_generan_orden_y_items_coherentes(): void
    {
        $orden = OrdenServicio::factory()->create();
        OrdenItem::factory()->create(['orden_id' => $orden->id]);

        $this->assertSame($orden->proforma->cliente_id, $orden->cliente_id);
        $this->assertSame('PENDIENTE', $orden->estado->value);
        $this->assertDatabaseCount('orden_items', 1);
    }

    public function test_detalle_de_orden_incluye_eventos_con_usuario_e_items(): void
    {
        $admin = $this->admin();
        $proforma = $this->proformaAceptada($admin);
        $ordenId = $this->crearOrden($admin, $proforma)->assertCreated()->json('id');

        $this->actingAs($admin)->getJson("/api/v1/ordenes/{$ordenId}")
            ->assertOk()
            ->assertJsonPath('eventos.0.usuario.id', $admin->id)
            ->assertJsonPath('items.0.orden_id', $ordenId)
            ->assertJsonStructure([
                'cliente' => ['id', 'nombre_razon'],
                'proforma' => ['id', 'numero', 'estado'],
                'items' => [['id', 'tipo', 'descripcion', 'cantidad', 'precio_unitario', 'total_linea']],
                'eventos' => [['id', 'tipo', 'descripcion', 'usuario' => ['id', 'name']]],
            ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => Rol::Admin]);
    }

    private function operario(): User
    {
        return User::factory()->create(['rol' => Rol::Operario, 'activo' => true]);
    }

    private function proformaAceptada(User $admin, ?Cliente $cliente = null): Proforma
    {
        $cliente ??= Cliente::factory()->create();
        $servicio = Servicio::factory()->create(['precio_base' => 100]);
        $producto = Producto::factory()->create(['precio_unitario' => 50]);

        $proforma = Proforma::factory()->aceptada()->create([
            'cliente_id' => $cliente->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $proforma->items()->createMany([
            [
                'tipo' => 'SERVICIO',
                'servicio_id' => $servicio->id,
                'producto_id' => null,
                'nombre' => $servicio->nombre,
                'descripcion' => 'Servicio de prueba',
                'unidad_medida' => 'servicio',
                'cantidad' => 2,
                'precio_unitario' => 100,
                'total_linea' => 200,
            ],
            [
                'tipo' => 'MATERIAL',
                'servicio_id' => null,
                'producto_id' => $producto->id,
                'nombre' => $producto->nombre,
                'descripcion' => 'Material de prueba',
                'unidad_medida' => 'unidad',
                'cantidad' => 3,
                'precio_unitario' => 50,
                'total_linea' => 150,
            ],
        ]);

        return $proforma->load('items');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearOrden(User $usuario, Proforma $proforma, array $datos = []): TestResponse
    {
        return $this->actingAs($usuario)->postJson('/api/v1/ordenes', [
            'proforma_id' => $proforma->id,
            ...$datos,
        ]);
    }
}
