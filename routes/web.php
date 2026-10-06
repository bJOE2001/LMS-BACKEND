<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ZKTeco MB360 ADMS Push Endpoints (root /iclock)
// Hardened with rate limiting and strict biometric device validation
Route::prefix('iclock')->middleware(['throttle:120,1', 'bio.security'])->group(function (): void {
    Route::match(['GET', 'POST'], '/cdata', [\App\Http\Controllers\Attendance\ZkAdmsController::class, 'cdata']);
    Route::get('/getrequest', [\App\Http\Controllers\Attendance\ZkAdmsController::class, 'getrequest']);
    Route::post('/devicecmd', [\App\Http\Controllers\Attendance\ZkAdmsController::class, 'devicecmd']);
});
