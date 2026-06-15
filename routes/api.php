<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\DeviceModelController;
use App\Http\Controllers\Api\DeviceController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/suppliers', [SupplierController::class, 'index']);
Route::post('/suppliers', [SupplierController::class, 'store']);
Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

Route::get('/models', [DeviceModelController::class, 'index']);
Route::post('/models', [DeviceModelController::class, 'store']);
Route::delete('/models/{deviceModel}', [DeviceModelController::class, 'destroy']);
