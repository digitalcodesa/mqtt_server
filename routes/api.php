<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\dahua\MqttController;

Route::prefix('api/v1/mqtt')->group(function () {
    Route::post('/start-multiple-listeners', [MqttController::class, 'startMultipleListeners']);
    Route::get('/listeners-status', [MqttController::class, 'getListenersStatus']);
    Route::post('/stop-all-listeners', [MqttController::class, 'stopAllListeners']);
    Route::post('/start-listener', [MqttController::class, 'startListener']);
    Route::post('/start-listener-job', [MqttController::class, 'startListenerJob']);
    Route::get('/servers-data', [MqttController::class, 'getServersData']);
});
