<?php

namespace Tests\Unit;

use App\Support\Monedas;
use PHPUnit\Framework\TestCase;

/**
 * El PDF de la proforma escribe la moneda igual que la pantalla (Intl es-BO):
 * "Bs 1.250,75", "USD 1.250,75", "EUR 1.250,75".
 */
class MonedasTest extends TestCase
{
    public function test_el_simbolo_coincide_con_la_pantalla(): void
    {
        $this->assertSame('Bs', Monedas::simbolo('BOB'));
        $this->assertSame('USD', Monedas::simbolo('usd'));
        $this->assertSame('EUR', Monedas::simbolo('EUR'));
        $this->assertSame('bolivianos', mb_strtolower(Monedas::etiqueta('BOB')));
    }
}
