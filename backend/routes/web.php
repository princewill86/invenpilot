<?php

use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SessionController;
use App\Services\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$frontend = function (Request $request, Platform $platform) {
    $index = public_path('index.html');
    if (is_file($index)) {
        $settings = $platform->publicSettings();
        $isPublic = $request->path() === '/' || $request->is('privacy', 'terms');
        $html = str_replace(
            ['<title>InvenPilot</title>', 'content="InvenPilot helps businesses manage products, stock, purchases, and sales."', 'href="/favicon.svg"'],
            [
                '<title>'.e($settings['seo_title']).'</title>',
                'content="'.e($settings['seo_description']).'"',
                'href="'.e($settings['favicon_url'] ?? '/favicon.svg').'"',
            ],
            file_get_contents($index),
        );

        return response($html)->header('Cache-Control', 'no-cache')
            ->header('X-Robots-Tag', $isPublic && $settings['search_indexing'] ? 'index, follow' : 'noindex, nofollow');
    }

    return response('Start the frontend development server to open InvenPilot.', 200, ['Content-Type' => 'text/plain']);
};
Route::get('/reset-password/{token}', $frontend)->name('password.reset');
Route::get('/{path?}', $frontend)->where('path', 'login|register|join|privacy|terms|forgot-password|reset-password|admin(?:/.*)?|workspace(?:/.*)?');

Route::prefix('api/v1')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::post('/register', [SessionController::class, 'register'])->middleware('throttle:10,1,registration');
        Route::post('/forgot-password', [PasswordController::class, 'send'])->middleware('throttle:5,1,password-recovery');
        Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1,password-recovery');
        Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:10,1,login');
    });

    Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth:web');
});
