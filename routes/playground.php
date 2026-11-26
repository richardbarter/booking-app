<?php

use App\Http\Controllers\Playground\DiaryController;
use App\Http\Controllers\Playground\SandboxController;
use App\Http\Middleware\UseSandbox;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Http\Middleware\Sample;

/*
 * The scheduling playground (08). Nightwatch records one request in ten,
 * and every error, so visitors can't use up its monthly events. Routes
 * with UseSandbox work on the visitor's sandbox.
 */
Route::middleware(Sample::rate(0.1))->prefix('playground')->name('playground.')->group(function () {
    Route::get('start', [SandboxController::class, 'create'])->name('start');
    Route::post('start', [SandboxController::class, 'store'])->middleware('throttle:sandboxes')->name('start.store');

    Route::middleware(UseSandbox::class)->group(function () {
        Route::get('/', [DiaryController::class, 'show'])->name('show');
    });
});
