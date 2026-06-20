<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ControllerProducts;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('products', ControllerProducts::class);
Route::put('/products/{id}/decrease-stock', [ControllerProducts::class, 'decreaseStock']);
Route::put('/products/{id}/increase-stock', [ControllerProducts::class, 'increaseStock']);
