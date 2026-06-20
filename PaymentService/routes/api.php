<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;

Route::get('/payments', [PaymentController::class, 'index']);
Route::post('/payments', [PaymentController::class, 'store']);
Route::get('/payments/{id}', [PaymentController::class, 'show']);
Route::patch('/payments/{id}/status', [PaymentController::class, 'updateStatus']);
Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
Route::get('/payments/order/{order_id}', [PaymentController::class, 'getByOrder']);
