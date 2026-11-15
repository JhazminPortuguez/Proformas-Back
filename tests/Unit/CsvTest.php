<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\TestCase;

/** R-05: CSV injection. */
class CsvTest extends TestCase
{
    public function test_antepone_apostrofo_a_texto_que_empieza_como_formula(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", Csv::celda('=HYPERLINK("http://x")'));
        $this->assertSame("'+591 70000000", Csv::celda('+591 70000000'));
        $this->assertSame("'-Cliente", Csv::celda('-Cliente'));
        $this->assertSame("'@SUM(A1:A2)", Csv::celda('@SUM(A1:A2)'));
        $this->assertSame("'\tTabulado", Csv::celda("\tTabulado"));
        $this->assertSame("'\rRetorno", Csv::celda("\rRetorno"));
    }

    public function test_no_toca_numeros_ni_texto_normal(): void
    {
        $this->assertSame('-12,50', Csv::celda('-12,50'));
        $this->assertSame('-3', Csv::celda('-3'));
        $this->assertSame('1234,50', Csv::celda(Csv::numero('1234.50')));
        $this->assertSame('-0.5', Csv::celda('-0.5'));
        $this->assertSame('42', Csv::celda(42));
        $this->assertSame('-7', Csv::celda(-7));
        $this->assertSame('2026-11-10', Csv::celda('2026-11-10'));
        $this->assertSame('Constructora Andina', Csv::celda('Constructora Andina'));
        $this->assertSame('Más de 90 días', Csv::celda('Más de 90 días'));
        $this->assertSame('', Csv::celda(null));
        $this->assertSame('', Csv::celda(''));
    }
}
