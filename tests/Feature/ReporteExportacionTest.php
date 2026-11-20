<?php

namespace Tests\Feature;

use App\Actions\Reportes\ReporteCobros;
use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\PagoCuenta;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use App\Support\PeriodoReporte;
use App\Support\Reportes\ExportarPdfReporte;
use App\Support\Reportes\TablaReporte;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Mejora 2: los 4 reportes en PDF y Excel. Se comprueba que el archivo se
 * genera con el tipo correcto, que respeta filtros y permisos, y que los
 * totales del archivo son los mismos que muestra la pantalla (el JSON).
 */
class ReporteExportacionTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTES = ['ordenes', 'servicios', 'inventario', 'cobros'];

    private User $admin;

    private User $operario;

    private Cliente $andina;

    private Cliente $illimani;

    protected function setUp(): void
    {
        parent::setUp();

        // Hoy: 15/11/2026 en Bolivia.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'America/La_Paz'));

        $this->admin = User::factory()->create(['rol' => Rol::Admin]);
        $this->operario = User::factory()->create(['rol' => Rol::Operario, 'name' => 'Operario Uno']);
        $this->andina = Cliente::factory()->create(['nombre_razon' => 'Constructora Andina', 'nit_ci' => '111']);
        // Un nombre que parece fórmula: en Excel debe quedar como texto.
        $this->illimani = Cliente::factory()->create(['nombre_razon' => '=HYPERLINK("http://x","Hotel")', 'nit_ci' => '222']);

        $servicio = Servicio::factory()->create(['nombre' => 'Instalación eléctrica', 'categoria' => 'Instalación']);
        $producto = Producto::factory()->create(['nombre' => 'Cable 12 AWG', 'unidad_medida' => 'metro']);

        foreach ([[$this->andina, '1250.50', 2], [$this->illimani, '300.25', 1]] as [$cliente, $monto, $cantidad]) {
            $proforma = Proforma::factory()->aceptada()->create([
                'cliente_id' => $cliente->id,
                'total' => $monto,
                'subtotal' => $monto,
                'fecha_emision' => '2026-11-03',
                'aceptada_at' => CarbonImmutable::parse('2026-11-04 10:00', 'America/La_Paz'),
            ]);

            $orden = OrdenServicio::factory()->paraProforma($proforma)->finalizada()->create([
                'operario_id' => $this->operario->id,
                'fecha_programada' => '2026-11-05',
                'fecha_inicio' => CarbonImmutable::parse('2026-11-05 09:00', 'America/La_Paz'),
                'fecha_fin' => CarbonImmutable::parse('2026-11-07 17:00', 'America/La_Paz'),
            ]);

            OrdenItem::factory()->servicio()->create([
                'orden_id' => $orden->id,
                'servicio_id' => $servicio->id,
                'cantidad' => $cantidad,
                'precio_unitario' => $monto,
                'total_linea' => bcmul($monto, (string) $cantidad, 2),
            ]);

            MovimientoInventario::factory()->create([
                'producto_id' => $producto->id,
                'orden_id' => $orden->id,
                'tipo' => TipoMovimientoInventario::Consumo,
                'cantidad' => -3.5,
                'costo_unitario' => 8.4,
                'stock_anterior' => 10,
                'stock_nuevo' => 6.5,
                'created_at' => CarbonImmutable::parse('2026-11-06 10:00', 'America/La_Paz'),
            ]);

            $cuenta = CuentaPorCobrar::factory()->create([
                'orden_id' => $orden->id,
                'cliente_id' => $cliente->id,
                'monto_total' => $monto,
                'saldo' => $monto,
                'fecha_emision' => '2026-10-01',
                'fecha_vencimiento' => '2026-10-31',
            ]);

            PagoCuenta::factory()->create(['cuenta_id' => $cuenta->id, 'monto' => '50.10', 'fecha_pago' => '2026-11-10']);
            $cuenta->update(['saldo' => bcsub($monto, '50.10', 2)]);
        }
    }

    private function descargar(string $reporte, string $formato, array $filtros = [], ?User $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin)->get(
            "/api/v1/reportes/{$reporte}?".http_build_query(['formato' => $formato, ...$filtros]),
            ['Accept' => 'application/json'],
        );
    }

    private function libro(TestResponse $respuesta): Spreadsheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        try {
            return IOFactory::load($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    /** Valor de "Resumen" por su etiqueta (columna A → B). */
    private function resumen(Spreadsheet $libro, string $etiqueta): mixed
    {
        $hoja = $libro->getSheetByName('Resumen');

        foreach ($hoja->getRowIterator() as $fila) {
            $n = $fila->getRowIndex();

            if ($hoja->getCell("A{$n}")->getValue() === $etiqueta) {
                return $hoja->getCell("B{$n}")->getValue();
            }
        }

        $this->fail("No está «{$etiqueta}» en la hoja Resumen.");
    }

    /** Última fila (la de totales) de una hoja de tabla. */
    private function filaTotales(Worksheet $hoja): int
    {
        return $hoja->getHighestDataRow();
    }

    public function test_los_cuatro_reportes_se_generan_en_pdf_carta_con_su_nombre(): void
    {
        foreach (self::REPORTES as $reporte) {
            $respuesta = $this->descargar($reporte, 'pdf')->assertOk();

            $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'), $reporte);
            $this->assertStringContainsString(
                "attachment; filename=\"reporte-{$reporte}_2026-11-01_2026-11-30.pdf\"",
                (string) $respuesta->headers->get('Content-Disposition'),
            );

            $pdf = (string) $respuesta->getContent();

            $this->assertStringStartsWith('%PDF', $pdf, $reporte);
            // Carta: 612 × 792 puntos (horizontal: 792 × 612).
            $this->assertMatchesRegularExpression('#/MediaBox \[0\.0+ 0\.0+ (612|792)\.0+ (792|612)\.0+\]#', $pdf, $reporte);
        }
    }

    public function test_los_cuatro_reportes_se_generan_en_xlsx_real(): void
    {
        foreach (self::REPORTES as $reporte) {
            $respuesta = $this->descargar($reporte, 'xlsx')->assertOk();

            $this->assertSame(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $respuesta->headers->get('Content-Type'),
            );
            $this->assertStringContainsString(
                "filename=\"reporte-{$reporte}_2026-11-01_2026-11-30.xlsx\"",
                (string) $respuesta->headers->get('Content-Disposition'),
            );

            $contenido = $respuesta->streamedContent();

            // Un .xlsx es un ZIP (PK…), no un CSV renombrado.
            $this->assertStringStartsWith("PK\x03\x04", $contenido, $reporte);
            $this->assertSame('Resumen', $this->libro($respuesta)->getSheet(0)->getTitle());
        }
    }

    public function test_cobros_en_excel_tiene_los_totales_de_la_pantalla_y_el_formato_pedido(): void
    {
        $pantalla = $this->actingAs($this->admin)->getJson('/api/v1/reportes/cobros')->assertOk()->json();
        $libro = $this->libro($this->descargar('cobros', 'xlsx'));

        $this->assertEqualsWithDelta($pantalla['cobrado'], $this->resumen($libro, 'Cobrado en el periodo'), 0.001);
        $this->assertEqualsWithDelta($pantalla['saldo_pendiente'], $this->resumen($libro, 'Saldo pendiente (hoy)'), 0.001);
        $this->assertSame($pantalla['cuentas_con_saldo'], $this->resumen($libro, 'Cuentas con saldo'));

        $hoja = $libro->getSheetByName('Cuentas con saldo');
        $totales = $this->filaTotales($hoja);

        // Encabezados en negrita, filtro en la fila de encabezados (sin la de totales).
        $this->assertSame('Número', $hoja->getCell('A1')->getValue());
        $this->assertTrue($hoja->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A1:H'.($totales - 1), $hoja->getAutoFilter()->getRange());

        // Fila de totales: el saldo coincide con la pantalla.
        $this->assertSame('Total: 2 cuentas', $hoja->getCell("A{$totales}")->getValue());
        $this->assertTrue($hoja->getStyle("A{$totales}")->getFont()->getBold());
        $this->assertEqualsWithDelta($pantalla['saldo_pendiente'], $hoja->getCell("G{$totales}")->getValue(), 0.001);

        // Montos con 2 decimales y fechas como fecha.
        $this->assertSame('"Bs" #,##0.00', $hoja->getStyle('G2')->getNumberFormat()->getFormatCode());
        $this->assertSame(DataType::TYPE_NUMERIC, $hoja->getCell('G2')->getDataType());
        $this->assertSame('dd/mm/yyyy', $hoja->getStyle('E2')->getNumberFormat()->getFormatCode());
        $this->assertSame('2026-10-31', FechaExcel::excelToDateTimeObject($hoja->getCell('E2')->getValue())->format('Y-m-d'));

        // Ancho de columnas ajustado (no el ancho por defecto).
        $this->assertGreaterThan(12, $hoja->getColumnDimension('C')->getWidth());
    }

    public function test_los_totales_de_ordenes_servicios_e_inventario_coinciden_con_la_pantalla(): void
    {
        $ordenes = $this->actingAs($this->admin)->getJson('/api/v1/reportes/ordenes')->json();
        $libro = $this->libro($this->descargar('ordenes', 'xlsx'));
        $this->assertSame($ordenes['total'], $this->resumen($libro, 'Órdenes del periodo'));
        $this->assertSame($ordenes['cumplimiento']['con_atraso'], $this->resumen($libro, 'Finalizadas con atraso'));
        $hoja = $libro->getSheetByName('Órdenes por operario');
        $this->assertSame($ordenes['total'], $hoja->getCell('G'.$this->filaTotales($hoja))->getValue());
        $this->assertEqualsWithDelta($ordenes['cumplimiento']['porcentaje_a_tiempo'] / 100, $this->resumen($libro, 'Cumplimiento a tiempo'), 0.0001);

        $servicios = $this->actingAs($this->admin)->getJson('/api/v1/reportes/servicios')->json();
        $libro = $this->libro($this->descargar('servicios', 'xlsx'));
        $this->assertEqualsWithDelta($servicios['proformas']['aceptado'], $this->resumen($libro, 'Aceptado'), 0.001);
        $hoja = $libro->getSheetByName('Servicios realizados');
        $total = $this->filaTotales($hoja);
        $this->assertEqualsWithDelta(array_sum(array_column($servicios['servicios'], 'monto')), $hoja->getCell("D{$total}")->getValue(), 0.001);
        $this->assertEqualsWithDelta(array_sum(array_column($servicios['servicios'], 'cantidad')), $hoja->getCell("C{$total}")->getValue(), 0.001);

        $inventario = $this->actingAs($this->admin)->getJson('/api/v1/reportes/inventario')->json();
        $libro = $this->libro($this->descargar('inventario', 'xlsx'));
        $this->assertEqualsWithDelta($inventario['consumo']['costo_total'], $this->resumen($libro, 'Costo de lo consumido'), 0.001);
        $hoja = $libro->getSheetByName('Consumo por producto');
        $this->assertEqualsWithDelta($inventario['consumo']['costo_total'], $hoja->getCell('D'.$this->filaTotales($hoja))->getValue(), 0.001);
        $this->assertEqualsWithDelta(7, $hoja->getCell('C2')->getValue(), 0.001); // 3,5 + 3,5 metros
    }

    public function test_el_pdf_muestra_los_mismos_totales_con_el_formato_de_pantalla(): void
    {
        $documento = app(ReporteCobros::class)
            ->documento(PeriodoReporte::crear(null, null))
            ->conFiltros([['etiqueta' => 'Periodo', 'valor' => '01/11/2026 al 30/11/2026']]);

        $html = view('reportes.pdf', [
            'documento' => $documento,
            'empresa' => new Empresa(['nombre' => 'Servicios Eléctricos Demo', 'nit' => '123456']),
            'generado' => CarbonImmutable::now('America/La_Paz'),
        ])->render();

        $this->assertStringContainsString('Reporte de cobros', $html);
        $this->assertStringContainsString('Servicios Eléctricos Demo', $html);
        $this->assertStringContainsString('Generado el 15/11/2026 a las 12:00 (hora de Bolivia)', $html);
        $this->assertStringContainsString('01/11/2026 al 30/11/2026', $html);
        // 1250,50 + 300,25 − 2 × 50,10 = 1450,55
        $this->assertStringContainsString('Bs 1.450,55', $html);
        $this->assertStringContainsString('Total: 2 cuentas', $html);
        $this->assertStringContainsString('31/10/2026', $html);
        $this->assertSame('Bs 1.234,50', ExportarPdfReporte::formatear('1234.5', TablaReporte::DINERO));
        $this->assertSame('3,5', ExportarPdfReporte::formatear(3.5, TablaReporte::CANTIDAD));
        $this->assertSame('—', ExportarPdfReporte::formatear(null, TablaReporte::PORCENTAJE));
    }

    public function test_respeta_el_filtro_de_cliente_y_lo_escribe(): void
    {
        $libro = $this->libro($this->descargar('cobros', 'xlsx', ['cliente_id' => $this->andina->id]));
        $hoja = $libro->getSheetByName('Cuentas con saldo');

        $this->assertSame('Constructora Andina', $hoja->getCell('C2')->getValue());
        $this->assertSame('Total: 1 cuentas', $hoja->getCell('A3')->getValue());
        $this->assertEqualsWithDelta(1200.40, $hoja->getCell('G3')->getValue(), 0.001);
        $this->assertSame('Constructora Andina', $this->resumen($libro, 'Cliente'));
    }

    public function test_un_texto_que_parece_formula_queda_como_texto(): void
    {
        $libro = $this->libro($this->descargar('cobros', 'xlsx', ['cliente_id' => $this->illimani->id]));
        $celda = $libro->getSheetByName('Cuentas con saldo')->getCell('C2');

        $this->assertSame(DataType::TYPE_STRING, $celda->getDataType());
        $this->assertSame('=HYPERLINK("http://x","Hotel")', $celda->getValue());
    }

    public function test_los_seis_prefijos_de_formula_quedan_como_texto_aunque_se_edite_la_celda(): void
    {
        // Misma regla que el CSV: =, +, -, @, tabulación y retorno.
        $nombres = ['=1+1', '+SUM(1,2)', '-2+3', '@SUM(1)', "\tTab", "\rRetorno"];

        foreach ($nombres as $i => $nombre) {
            $cliente = Cliente::factory()->create(['nombre_razon' => $nombre]);
            CuentaPorCobrar::factory()->create(['cliente_id' => $cliente->id, 'saldo' => 10 + $i, 'fecha_vencimiento' => '2026-12-01']);
        }

        $hoja = $this->libro($this->descargar('cobros', 'xlsx'))->getSheetByName('Cuentas con saldo');
        $protegidos = [];

        foreach ($hoja->getRowIterator(2) as $fila) {
            $celda = $hoja->getCell('C'.$fila->getRowIndex());
            $valor = (string) $celda->getValue();

            if ($valor === '' || ! in_array($valor[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
                continue;
            }

            $this->assertSame(DataType::TYPE_STRING, $celda->getDataType(), $valor);
            $this->assertTrue($celda->getStyle()->getQuotePrefix(), "Sin quotePrefix: {$valor}");
            $protegidos[] = $valor[0];
        }

        // 6 nuevos más el =HYPERLINK del setUp. El retorno lo normaliza
        // PhpSpreadsheet a salto de línea.
        $this->assertCount(7, $protegidos);
        // Un texto normal no lleva la marca.
        $this->assertFalse($hoja->getStyle('A1')->getQuotePrefix());
    }

    public function test_el_pdf_recorta_el_detalle_pero_conserva_los_totales_y_avisa(): void
    {
        // Con ~1000 filas dompdf pedía ~580 MB y respondía 500: el PDF muestra
        // un máximo de filas; el Excel y el CSV, todas.
        $original = app(ReporteCobros::class)->documento(PeriodoReporte::crear(null, null));
        $documento = $original->conTablasLimitadas(1);
        $tabla = $documento->tablas[0];

        $this->assertCount(1, $tabla->filas);
        $this->assertSame(1, $tabla->omitidas);
        $this->assertSame('1450.55', $tabla->totales[6]); // el total de las 2 cuentas

        $html = view('reportes.pdf', [
            'documento' => $documento,
            'empresa' => new Empresa(['nombre' => 'Empresa']),
            'generado' => CarbonImmutable::now('America/La_Paz'),
        ])->render();

        $this->assertStringContainsString('Se muestran 1', $html);
        $this->assertStringContainsString('de 2 filas.', $html);
        $this->assertStringContainsString('Bs 1.450,55', $html);
        $this->assertSame(200, ExportarPdfReporte::MAXIMO_FILAS);

        // Con menos filas que el tope no se toca nada.
        $completa = $original->tablas[0]->limitada(5);
        $this->assertSame($original->tablas[0], $completa);
        $this->assertSame(0, $completa->omitidas);

        // El Excel trae todas las filas.
        $hoja = $this->libro($this->descargar('cobros', 'xlsx'))->getSheetByName('Cuentas con saldo');
        $this->assertSame('Total: 2 cuentas', $hoja->getCell('A4')->getValue());
    }

    public function test_con_mas_de_200_filas_el_pdf_lo_dice_y_el_excel_y_el_csv_traen_todas(): void
    {
        // 2 cuentas del setUp + 205 nuevas = 207 cuentas con saldo.
        CuentaPorCobrar::factory()->count(205)->create([
            'fecha_emision' => '2026-11-01',
            'fecha_vencimiento' => '2026-12-01',
            'monto_total' => 10,
            'saldo' => 10,
        ]);

        // PDF: el aviso va arriba de la tabla, con el total real de filas.
        $documento = app(ReporteCobros::class)->documento(PeriodoReporte::crear(null, null))
            ->conTablasLimitadas(ExportarPdfReporte::MAXIMO_FILAS);
        $html = view('reportes.pdf', [
            'documento' => $documento,
            'empresa' => new Empresa(['nombre' => 'Empresa']),
            'generado' => CarbonImmutable::now('America/La_Paz'),
        ])->render();
        $texto = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertStringContainsString('Se muestran 200 de 207 filas. Descarga el Excel para ver el detalle completo.', $texto);
        $this->assertLessThan(strpos($html, '<table class="datos">'), strpos($html, 'Se muestran 200'));
        $this->descargar('cobros', 'pdf')->assertOk();

        // Excel: las 207 filas y la fila de totales.
        $hoja = $this->libro($this->descargar('cobros', 'xlsx'))->getSheetByName('Cuentas con saldo');
        $this->assertSame('Total: 207 cuentas', $hoja->getCell('A209')->getValue());
        $this->assertNotEmpty($hoja->getCell('A208')->getValue());

        // CSV: encabezado + 207 filas.
        $csv = $this->descargar('cobros', 'csv')->assertOk()->streamedContent();
        $lineas = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(208, $lineas);
    }

    public function test_solo_el_admin_descarga_y_los_formatos_desconocidos_se_rechazan(): void
    {
        $cliente = User::factory()->create(['rol' => Rol::Cliente, 'cliente_id' => $this->andina->id]);

        foreach (self::REPORTES as $reporte) {
            foreach (['pdf', 'xlsx'] as $formato) {
                $this->descargar($reporte, $formato, [], $this->operario)->assertForbidden();
                $this->descargar($reporte, $formato, [], $cliente)->assertForbidden();
            }
        }

        $this->descargar('cobros', 'docx')->assertStatus(422)->assertJsonValidationErrors('formato');
        $this->descargar('cobros', 'pdf', ['desde' => '2024-01-01', 'hasta' => '2026-11-30'])->assertStatus(422);
    }
}
