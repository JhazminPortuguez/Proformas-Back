<?php

namespace Tests\Feature;

use App\Actions\Proformas\EnviarCorreoProforma;
use App\Enums\Rol;
use App\Mail\OrdenFinalizadaMail;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Ronda A, parte 2: la proforma se manda por correo al enviarla y con
 * "Reenviar por correo". Usa el mailer de prueba (array): ningún correo sale.
 */
class ProformaCorreoTest extends TestCase
{
    use RefreshDatabase;

    private User $operario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'app.frontend_url' => 'https://proformas.test']);
        Empresa::query()->whereKey(EmpresaActual::id())->update(['nombre' => 'Electro Andes']);
        $this->operario = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Oscar Operario']);
    }

    private function borrador(?string $email = 'cliente@correo.bo'): Proforma
    {
        $cliente = Cliente::factory()->create(['nombre_razon' => 'Ana Pérez', 'email' => $email]);
        $proforma = Proforma::factory()->create([
            'cliente_id' => $cliente->id,
            'subtotal' => 1250.75,
            'total' => 1250.75,
            'fecha_validez' => '2099-12-31',
        ]);
        ProformaItem::factory()->servicio()->create(['proforma_id' => $proforma->id]);

        return $proforma;
    }

    /** @return list<Email> */
    private function enviados(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn (SentMessage $mensaje) => $mensaje->getOriginalMessage())
            ->all();
    }

    public function test_al_enviar_manda_el_correo_con_el_pdf_adjunto_y_los_datos(): void
    {
        $proforma = $this->borrador();

        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$proforma->id}/enviar")
            ->assertOk()
            ->assertJsonPath('estado', 'ENVIADA')
            ->assertJsonPath('correo.enviado', true)
            ->assertJsonPath('correo.mensaje', 'Correo enviado a cliente@correo.bo.')
            ->assertJsonPath('eventos.0.tipo', 'CORREO_ENVIADO')
            ->assertJsonPath('eventos.0.destinatario', 'cliente@correo.bo');

        $correos = $this->enviados();
        $this->assertCount(1, $correos);
        $correo = $correos[0];

        $this->assertSame('cliente@correo.bo', $correo->getTo()[0]->getAddress());
        $this->assertSame("Proforma {$proforma->numero} de Electro Andes", $correo->getSubject());

        $html = $correo->getHtmlBody();
        $this->assertStringContainsString($proforma->numero, $html);
        $this->assertStringContainsString('Bs 1.250,75', $html);
        $this->assertStringContainsString('31/12/2099', $html);
        $this->assertStringContainsString("https://proformas.test/portal/proformas/{$proforma->id}", $html);

        $adjuntos = $correo->getAttachments();
        $this->assertCount(1, $adjuntos);
        $this->assertSame("{$proforma->numero}.pdf", $adjuntos[0]->getFilename());
        $this->assertSame('application/pdf', $adjuntos[0]->getMediaType().'/'.$adjuntos[0]->getMediaSubtype());
        $this->assertStringStartsWith('%PDF', $adjuntos[0]->getBody());
    }

    public function test_sin_email_la_proforma_se_envia_igual_y_avisa(): void
    {
        $proforma = $this->borrador(null);

        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$proforma->id}/enviar")
            ->assertOk()
            ->assertJsonPath('estado', 'ENVIADA')
            ->assertJsonPath('correo.enviado', false)
            ->assertJsonPath('correo.mensaje', 'El cliente no tiene correo registrado; no se envió el correo.')
            ->assertJsonPath('eventos.0.tipo', 'CORREO_SIN_EMAIL');

        $this->assertSame([], $this->enviados());
    }

    public function test_si_falla_el_envio_queda_enviada_con_aviso_y_en_el_log(): void
    {
        Mail::extend('caido', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection refused');
            }

            public function __toString(): string
            {
                return 'caido://';
            }
        });
        config(['mail.mailers.caido' => ['transport' => 'caido'], 'mail.default' => 'caido']);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $mensaje, array $contexto) => $mensaje === 'No se pudo enviar la proforma por correo.'
                && $contexto['destinatario'] === 'cliente@correo.bo'
                && str_contains($contexto['error'], 'Connection refused'));

        $proforma = $this->borrador();

        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$proforma->id}/enviar")
            ->assertOk()
            ->assertJsonPath('estado', 'ENVIADA')
            ->assertJsonPath('correo.enviado', false)
            ->assertJsonPath('correo.mensaje', 'No se pudo enviar el correo a cliente@correo.bo. La proforma quedó Enviada igual; revisa la configuración del correo y usa "Reenviar por correo".')
            ->assertJsonPath('eventos.0.tipo', 'CORREO_FALLIDO');

        $this->assertSame('ENVIADA', $proforma->fresh()->estado->value);
    }

    public function test_reenviar_por_correo_agrega_otro_evento_al_historial(): void
    {
        $proforma = $this->borrador();
        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$proforma->id}/enviar")->assertOk();

        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$proforma->id}/reenviar-correo")
            ->assertOk()
            ->assertJsonPath('correo.enviado', true)
            ->assertJsonCount(2, 'eventos')
            ->assertJsonPath('eventos.0.usuario', 'Oscar Operario');

        $this->assertCount(2, $this->enviados());

        $this->actingAs($this->operario)->getJson("/api/v1/proformas/{$proforma->id}")
            ->assertJsonCount(2, 'eventos')
            ->assertJsonPath('acciones_disponibles', fn (array $acciones) => in_array('reenviar_correo', $acciones, true));
    }

    public function test_reenviar_permisos_y_borrador(): void
    {
        $borrador = $this->borrador();

        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$borrador->id}/reenviar-correo")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');
        $this->actingAs($this->operario)->getJson("/api/v1/proformas/{$borrador->id}")
            ->assertJsonPath('acciones_disponibles', fn (array $acciones) => ! in_array('reenviar_correo', $acciones, true));

        $this->actingAs($this->operario)->postJson("/api/v1/proformas/{$borrador->id}/enviar")->assertOk();

        $usuarioCliente = User::factory()->cliente()->create(['cliente_id' => $borrador->cliente_id]);
        $this->actingAs($usuarioCliente)->postJson("/api/v1/proformas/{$borrador->id}/reenviar-correo")->assertForbidden();

        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $this->actingAs($admin)->postJson("/api/v1/proformas/{$borrador->id}/reenviar-correo")->assertOk();

        // Ya aceptada, no se reenvía.
        $this->actingAs($admin)->postJson("/api/v1/proformas/{$borrador->id}/aceptar")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/proformas/{$borrador->id}/reenviar-correo")
            ->assertUnprocessable()
            ->assertJsonPath('errors.estado.0', 'Solo se reenvía por correo una proforma Enviada.');
    }

    public function test_el_aviso_sin_email_es_el_mismo_texto_de_la_constante(): void
    {
        $this->assertSame(
            'El cliente no tiene correo registrado; no se envió el correo.',
            EnviarCorreoProforma::MENSAJE_SIN_EMAIL,
        );
    }

    public function test_al_finalizar_la_orden_avisa_al_cliente_por_correo(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['rol' => Rol::Admin]);
        $conEmail = Cliente::factory()->create(['email' => 'avisame@correo.bo']);
        $sinEmail = Cliente::factory()->create(['email' => null]);

        $orden = OrdenServicio::factory()->enProceso()
            ->paraProforma(Proforma::factory()->aceptada()->create(['cliente_id' => $conEmail->id]))
            ->create();
        $otra = OrdenServicio::factory()->enProceso()
            ->paraProforma(Proforma::factory()->aceptada()->create(['cliente_id' => $sinEmail->id]))
            ->create();

        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$orden->id}/finalizar")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/ordenes/{$otra->id}/finalizar")->assertOk();

        Mail::assertSent(OrdenFinalizadaMail::class, 1);
        Mail::assertSent(OrdenFinalizadaMail::class, fn (OrdenFinalizadaMail $correo) => $correo->hasTo('avisame@correo.bo')
            && $correo->orden->is($orden));
    }
}
