<?php

use Illuminate\Support\Facades\Route;
use Tey\Docket\Http\RunController;
use Tey\Docket\Http\RunRequest;

Route::prefix(config()->string('docket.routes.prefix'))
    ->middleware([RunRequest::class, ...config()->array('docket.routes.middleware')])
    ->name('docket.')
    ->group(function (): void {
        Route::get('runs/{token}', [RunController::class, 'show'])->name('show');
        Route::get('runs/{token}/items', [RunController::class, 'items'])->name('items');
        Route::get('runs/{token}/logs', [RunController::class, 'logs'])->name('logs');
        Route::get('runs/{token}/children', [RunController::class, 'children'])->name('children');
        Route::post('runs/{token}/cancel', [RunController::class, 'cancel'])->name('cancel');
        Route::post('runs/{token}/retry', [RunController::class, 'retry'])->name('retry');
    });
