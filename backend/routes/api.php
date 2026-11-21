<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PlatformDirectoryController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\BusinessAccess;
use App\Http\Middleware\CommerceAccess;
use App\Http\Middleware\PlatformAccess;
use App\Services\Reporting;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']))->name('api.health');

Route::get('/v1/account', [SessionController::class, 'show'])->middleware(['auth:sanctum', ActiveAccount::class]);

Route::prefix('/v1/businesses/{business}')
    ->whereNumber('business')
    ->middleware(['auth:sanctum', ActiveAccount::class, BusinessAccess::class])
    ->group(function (): void {
        Route::get('/suppliers/{record}/products', [CatalogController::class, 'supplierProducts'])->whereNumber('record');
        Route::get('/products/{record}/image', [ProductImageController::class, 'show'])->whereNumber('record');
        Route::get('/movements', [InventoryController::class, 'index']);
        Route::get('/{resource}', [CatalogController::class, 'index'])->whereIn('resource', ['products', 'categories', 'suppliers']);
        Route::get('/{resource}/{record}', [CatalogController::class, 'show'])
            ->whereIn('resource', ['products', 'categories', 'suppliers'])->whereNumber('record');

        Route::middleware(BusinessAccess::class.':owner,administrator')->group(function (): void {
            Route::post('/products/{record}/image', [ProductImageController::class, 'store'])->whereNumber('record')->middleware('throttle:30,1');
            Route::post('/movements', [InventoryController::class, 'store']);
            Route::post('/{resource}', [CatalogController::class, 'store'])->whereIn('resource', ['products', 'categories', 'suppliers']);
            Route::put('/{resource}/{record}', [CatalogController::class, 'update'])
                ->whereIn('resource', ['products', 'categories', 'suppliers'])->whereNumber('record');
            Route::delete('/{resource}/{record}', [CatalogController::class, 'destroy'])
                ->whereIn('resource', ['products', 'categories', 'suppliers'])->whereNumber('record');
        });
    });

Route::prefix('/v1/businesses/{business}/{type}')
    ->where(['business' => '[0-9]+', 'type' => 'purchases|sales'])
    ->middleware(['auth:sanctum', ActiveAccount::class, BusinessAccess::class])
    ->group(function (): void {
        Route::get('/', [CommerceController::class, 'index'])->middleware(CommerceAccess::class);
        Route::get('/{record}', [CommerceController::class, 'show'])->whereNumber('record')->middleware(CommerceAccess::class);
        Route::post('/', [CommerceController::class, 'store'])->middleware(CommerceAccess::class);
        Route::post('/{record}/{action}', [CommerceController::class, 'apply'])->whereNumber('record')
            ->whereIn('action', ['submit', 'receive', 'return', 'supplier_return', 'cancel'])->middleware(BusinessAccess::class.':owner,administrator');
    });

Route::middleware(['auth:sanctum', ActiveAccount::class])->prefix('/v1')->group(function (): void {
    Route::patch('/profile', [SettingsController::class, 'updateProfile']);
    Route::put('/profile/password', [SettingsController::class, 'password'])->middleware('throttle:5,1');
    Route::post('/invitations/accept', [SettingsController::class, 'accept'])->middleware('throttle:10,1');

    Route::prefix('/businesses/{business}')->whereNumber('business')
        ->middleware(BusinessAccess::class.':owner')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'show']);
            Route::put('/settings', [SettingsController::class, 'update']);
            Route::get('/members', [SettingsController::class, 'members']);
            Route::match(['patch', 'delete'], '/members/{member}', [SettingsController::class, 'manageMember'])->whereNumber('member');
            Route::post('/invitations', [SettingsController::class, 'invite'])->middleware('throttle:10,1');
            Route::get('/activity', [SettingsController::class, 'activity']);
        });
});

Route::prefix('/v1/businesses/{business}')->whereNumber('business')
    ->middleware(['auth:sanctum', ActiveAccount::class, BusinessAccess::class.':owner,administrator'])
    ->group(function (): void {
        Route::get('/dashboard', [ReportController::class, 'dashboard']);
        Route::get('/reports/{report}', [ReportController::class, 'show'])->whereIn('report', Reporting::TYPES);
    });

Route::get('/v1/platform', [PlatformController::class, 'show']);
Route::get('/v1/platform/assets/{kind}', [PlatformController::class, 'asset'])->whereIn('kind', ['logo', 'favicon']);
Route::prefix('/v1/platform-admin')->middleware(['auth:sanctum', ActiveAccount::class, PlatformAccess::class])->group(function (): void {
    Route::get('/users', [PlatformDirectoryController::class, 'users']);
    Route::get('/users/{user}', [PlatformDirectoryController::class, 'user'])->whereNumber('user');
    Route::put('/users/{user}/status', [PlatformDirectoryController::class, 'userStatus'])->whereNumber('user');
    Route::get('/businesses', [PlatformDirectoryController::class, 'businesses']);
    Route::get('/businesses/{business}', [PlatformDirectoryController::class, 'business'])->whereNumber('business');
    Route::put('/businesses/{business}/status', [PlatformDirectoryController::class, 'businessStatus'])->whereNumber('business');
    Route::match(['put', 'delete'], '/businesses/{business}/members/{user}', [PlatformDirectoryController::class, 'membership'])->whereNumber(['business', 'user']);
    Route::get('/activity', [PlatformDirectoryController::class, 'activity']);
    Route::get('/overview', [PlatformController::class, 'overview']);
    Route::get('/settings', [PlatformController::class, 'adminSettings']);
    Route::post('/assets/{kind}', [PlatformController::class, 'upload'])->whereIn('kind', ['logo', 'favicon'])->middleware('throttle:12,1');
    Route::delete('/assets/{kind}', [PlatformController::class, 'removeAsset'])->whereIn('kind', ['logo', 'favicon']);
    Route::put('/settings', [PlatformController::class, 'update']);
});
