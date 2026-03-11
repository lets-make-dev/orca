<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use MakeDev\Orca\Http\Controllers\AutoLoginController;
use MakeDev\Orca\Http\Controllers\PopOutController;
use MakeDev\Orca\Http\Controllers\ScreenshotController;
use MakeDev\Orca\Http\Controllers\TerminalScreenshotController;

Route::post('orca/screenshot', [ScreenshotController::class, 'store'])
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('orca.screenshot.store');

Route::post('orca/popout/return', [PopOutController::class, 'returned'])
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('orca.popout.return');

Route::get('orca/terminal-screenshot/{session}', [TerminalScreenshotController::class, 'show'])
    ->name('orca.terminal-screenshot');

Route::get('orca/auto-login', AutoLoginController::class)
    ->middleware(ValidateSignature::class)
    ->name('orca.auto-login');
