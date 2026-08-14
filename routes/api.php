<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegisteredItemController;
use App\Http\Controllers\ScanLogController;

Route::get('/test', function () {
    return response()->json([
        'message' => 'QRPass API is working!'
    ]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {

    // Student
    Route::get('/items', [RegisteredItemController::class, 'index']);
    Route::post('/items', [RegisteredItemController::class, 'store']);

    // PCO
    Route::get('/items/pending', [RegisteredItemController::class, 'pending']);
    Route::get('/items/all', [RegisteredItemController::class, 'allItems']);
    Route::put('/items/{id}/approve', [RegisteredItemController::class, 'approve']);
    

    // Security - verify item
    Route::post('/items/verify', [RegisteredItemController::class, 'verify']);

    // Security - entry / exit scan logs
    Route::get('/scan-logs', [ScanLogController::class, 'index']);
    Route::post('/scan-logs', [ScanLogController::class, 'store']);
});