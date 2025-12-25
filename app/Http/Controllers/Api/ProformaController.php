<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proforma;
use App\Models\ProformaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProformaController extends Controller
{
    /**
     * Listar proformas
     */
    public function index()
    {
        return response()->json(
            Proforma::with('cliente', 'items.producto')
                ->orderBy('id', 'desc')
                ->get()
        );
    }

    /**
     * Crear proforma con items
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => 'required|exists:clientes,id',
            'fecha_emision' => 'required|date',
            'observaciones' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.precio_unitario' => 'required|numeric|min:0',
        ]);

        $proforma = DB::transaction(function () use ($data) {

            // Crear proforma
            $proforma = Proforma::create([
                'numero' => 'PR-' . now()->format('YmdHis'),
                'cliente_id' => $data['cliente_id'],
                'fecha_emision' => $data['fecha_emision'],
                'estado' => 'BORRADOR',
                'subtotal' => 0,
                'descuento' => 0,
                'impuesto' => 0,
                'total' => 0,
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            $subtotal = 0;

            // Crear items
            foreach ($data['items'] as $item) {
                $totalLinea = $item['cantidad'] * $item['precio_unitario'];
                $subtotal += $totalLinea;

                ProformaItem::create([
                    'proforma_id' => $proforma->id,
                    'producto_id' => $item['producto_id'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'total_linea' => $totalLinea,
                ]);
            }

            // Actualizar totales
            $proforma->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            return $proforma->load('cliente', 'items.producto');
        });

        return response()->json($proforma, 201);
    }

    /**
     * Mostrar una proforma
     */
    public function show(Proforma $proforma)
    {
        return response()->json(
            $proforma->load('cliente', 'items.producto')
        );
    }

    /**
     * Eliminar proforma
     */
    public function destroy(Proforma $proforma)
    {
        $proforma->delete();
        return response()->noContent();
    }
}
