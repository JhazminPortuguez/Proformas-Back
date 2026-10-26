<?php

namespace Tests\Unit;

use App\Enums\EstadoOrden;
use PHPUnit\Framework\TestCase;

class EstadoOrdenTest extends TestCase
{
    public function test_estados_finales_y_activos(): void
    {
        $this->assertTrue(EstadoOrden::Pendiente->esActiva());
        $this->assertTrue(EstadoOrden::Asignada->esActiva());
        $this->assertTrue(EstadoOrden::EnProceso->esActiva());

        $this->assertTrue(EstadoOrden::Finalizada->esFinal());
        $this->assertTrue(EstadoOrden::Cancelada->esFinal());
        $this->assertFalse(EstadoOrden::EnProceso->esFinal());
    }

    public function test_transiciones_permitidas_del_flujo(): void
    {
        $this->assertTrue(EstadoOrden::Pendiente->puedeTransicionarA(EstadoOrden::Asignada));
        $this->assertTrue(EstadoOrden::Pendiente->puedeTransicionarA(EstadoOrden::Cancelada));
        $this->assertTrue(EstadoOrden::Asignada->puedeTransicionarA(EstadoOrden::EnProceso));
        $this->assertTrue(EstadoOrden::Asignada->puedeTransicionarA(EstadoOrden::Cancelada));
        $this->assertTrue(EstadoOrden::EnProceso->puedeTransicionarA(EstadoOrden::Finalizada));
        $this->assertTrue(EstadoOrden::EnProceso->puedeTransicionarA(EstadoOrden::Cancelada));
    }

    public function test_no_permite_saltos_ni_reactivacion(): void
    {
        $this->assertFalse(EstadoOrden::Pendiente->puedeTransicionarA(EstadoOrden::EnProceso));
        $this->assertFalse(EstadoOrden::Pendiente->puedeTransicionarA(EstadoOrden::Finalizada));
        $this->assertFalse(EstadoOrden::Asignada->puedeTransicionarA(EstadoOrden::Finalizada));
        $this->assertFalse(EstadoOrden::Finalizada->puedeTransicionarA(EstadoOrden::EnProceso));
        $this->assertFalse(EstadoOrden::Finalizada->puedeTransicionarA(EstadoOrden::Asignada));
        $this->assertFalse(EstadoOrden::Cancelada->puedeTransicionarA(EstadoOrden::Pendiente));
        $this->assertFalse(EstadoOrden::Cancelada->puedeTransicionarA(EstadoOrden::Asignada));
    }
}
