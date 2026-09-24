<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CameraController;
use App\Http\Controllers\Api\V1\DetectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Público: lectura + emisión de tokens
    Route::post('login', [AuthController::class, 'login']);
    Route::get('cameras', [CameraController::class, 'index']);
    Route::get('cameras/{camera}', [CameraController::class, 'show']);
    Route::get('alerts', [AlertController::class, 'index']);
    Route::get('alerts/{alert}', [AlertController::class, 'show']);
    Route::get('detections', [DetectionController::class, 'index']);

    // Protegido (cámaras / operadores con token Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('cameras', [CameraController::class, 'store']);
        Route::delete('cameras/{camera}', [CameraController::class, 'destroy']);
        Route::post('detections', [DetectionController::class, 'store']);
        Route::post('alerts/{id}/acknowledge', [AlertController::class, 'acknowledge']);
        Route::delete('alerts/{alert}', [AlertController::class, 'destroy']);
    });
});
