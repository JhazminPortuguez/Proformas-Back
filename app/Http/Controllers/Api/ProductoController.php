<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    public function index()
    {
        return response()->json(
            Producto::orderBy('id', 'desc')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:200',
            'descripcion' => 'nullable|string',
            'precio_unitario' => 'required|numeric|min:0',
            'unidad_medida' => 'nullable|string|max:30',
            'activo' => 'boolean',
        ]);

        $producto = Producto::create($data);

        return response()->json($producto, 201);
    }

    public function show(Producto $producto)
    {
        return response()->json($producto);
    }

    public function update(Request $request, Producto $producto)
    {
        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:200',
            'descripcion' => 'nullable|string',
            'precio_unitario' => 'sometimes|required|numeric|min:0',
            'unidad_medida' => 'nullable|string|max:30',
            'activo' => 'boolean',
        ]);

        $producto->update($data);

        return response()->json($producto);
    }

    public function destroy(Producto $producto)
    {
        $producto->delete();
        return response()->noContent();
    }
}
