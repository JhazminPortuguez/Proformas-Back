<?php

namespace Tests\Unit;

use App\Support\Dinero;
use PHPUnit\Framework\TestCase;

class DineroTest extends TestCase
{
    public function test_redondea_a_dos_decimales_sin_floats(): void
    {
        $this->assertSame('1.01', Dinero::redondear('1.005'));
        $this->assertSame('2.68', Dinero::redondear('2.675'));
        $this->assertSame('0.00', Dinero::redondear('-0.004'));
        $this->assertSame('10.00', Dinero::redondear(10));
    }

    public function test_total_de_linea_con_cantidad_decimal(): void
    {
        $this->assertSame('15.50', Dinero::totalLinea('1.5', '10.33'));
        $this->assertSame('0.01', Dinero::totalLinea('0.5', '0.01'));
        $this->assertSame('200.00', Dinero::totalLinea('2', '100.00'));
    }

    public function test_suma_y_porcentaje_exactos(): void
    {
        $subtotal = Dinero::sumar('0.10', '0.20', '100.33');

        $this->assertSame('100.63', $subtotal);
        $this->assertSame('24.70', Dinero::porcentaje('190.00', '13'));
        $this->assertSame('0.00', Dinero::porcentaje('0.00', '13'));
    }

    public function test_comparaciones_no_usan_floats(): void
    {
        $this->assertTrue(Dinero::mayorQue('0.30', '0.29'));
        $this->assertFalse(Dinero::mayorQue('0.29', '0.30'));
        $this->assertSame(0, Dinero::comparar('10.00', '10'));
        $this->assertTrue(Dinero::esCero('0.00'));
    }

    public function test_conversion_a_centavos(): void
    {
        $this->assertSame('1999', Dinero::aCentavos('19.99'));
        $this->assertSame('19.99', Dinero::desdeCentavos('1999'));
    }

    public function test_normaliza_notacion_cientifica(): void
    {
        $this->assertSame('1000.00', Dinero::redondear('1e3'));
    }
}
