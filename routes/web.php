<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name').' API',
        'status' => 'ok',
        'docs' => 'Ver README.md',
    ]);
});
