<?php

use App\Http\Controllers\Attendance\NativeAdmsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Native ZKTeco ADMS Push Protocol Routes
|--------------------------------------------------------------------------
| Handles incoming hardware pings and attendance pushes directly from
| physical biometric terminals (inside City Hall & outside offices)
| without requiring third-party ZKBio Time licenses.
*/
Route::prefix('iclock')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->group(function () {
        Route::match(['get', 'post'], '/cdata', [NativeAdmsController::class, 'cdata']);
        Route::match(['get', 'post'], '/getrequest', [NativeAdmsController::class, 'getrequest']);
        Route::match(['get', 'post'], '/devicecmd', [NativeAdmsController::class, 'devicecmd']);
        Route::match(['get', 'post'], '/registry', [NativeAdmsController::class, 'registry']);
        Route::match(['get', 'post'], '/fdata', [NativeAdmsController::class, 'fdata']);
        Route::match(['get', 'post'], '/querydata', [NativeAdmsController::class, 'querydata']);
    });
