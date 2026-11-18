<?php

use Illuminate\Support\Facades\Route;
use CoderManjeet\SocialAutomation\Http\Controllers\SocialAccountController;

Route::prefix('admin/social/accounts')
    ->name('admin.social.accounts.')
    ->group(function () {
        Route::get('/', [SocialAccountController::class, 'index'])->name('index');
        Route::get('/{connector}/redirect', [SocialAccountController::class, 'redirect'])->name('redirect');
        Route::get('/{connector}/callback', [SocialAccountController::class, 'callback'])->name('callback');
        Route::post('/{connector}/choose', [SocialAccountController::class, 'choose'])->name('choose');
        Route::delete('/{platform}', [SocialAccountController::class, 'destroy'])->name('destroy');
    });
