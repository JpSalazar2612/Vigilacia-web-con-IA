<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\CameraController;
use App\Http\Controllers\Api\V1\DetectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::apiResource('cameras', CameraController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('alerts', AlertController::class)->only(['index', 'show', 'destroy']);
    Route::post('alerts/{id}/acknowledge', [AlertController::class, 'acknowledge']);
    Route::get('detections', [DetectionController::class, 'index']);
    Route::post('detections', [DetectionController::class, 'store']);
});
