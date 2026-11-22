<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Install\InstallerController;
use App\Support\PluginPageRoutes;
use App\Http\Controllers\Webhooks\EasyPostWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Peso\PesoShipmentController;
use App\Http\Controllers\Receiving\ReceivingController;
use App\Http\Controllers\Loading\LoadingController;


/*
 * Installer routes
 */
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::get('/requirements', [InstallerController::class, 'requirements'])->name('requirements');
    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database/test', [InstallerController::class, 'testDatabase'])->name('database.test');
    Route::post('/database/install', [InstallerController::class, 'installDatabase'])->name('database.install');
    Route::get('/admin', [InstallerController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallerController::class, 'createAdmin'])->name('admin.create');
    Route::get('/complete', [InstallerController::class, 'complete'])->name('complete');
});
Route::middleware('auth')->group(function () {

    /*
     * PESO / RE-12
     */
    Route::get('/peso', [PesoShipmentController::class, 'index'])
        ->name('peso.index');

    Route::get('/peso/pending-receiving', [PesoShipmentController::class, 'pendingReceiving'])
        ->name('peso.pending-receiving');

    Route::get('/peso/upload', [PesoShipmentController::class, 'create'])
        ->name('peso.upload');

    Route::post('/peso/upload', [PesoShipmentController::class, 'store'])
        ->name('peso.upload.store');

    Route::get('/peso/shipments/{id}', [PesoShipmentController::class, 'show'])
        ->name('peso.shipments.show');

    Route::post('/peso/shipments/{id}/map-product', [PesoShipmentController::class, 'mapProduct'])
        ->name('peso.shipments.map-product');

    // ...
});
/*
 * Authenticated application routes
 */
Route::middleware('auth')->group(function () {

    /*
     * Receiving
     */
    Route::get('/receiving', [ReceivingController::class, 'index'])
        ->name('receiving.index');

    Route::post('/receiving/scan', [ReceivingController::class, 'scan'])
        ->name('receiving.scan');


    /*
     * Loading
     */
    Route::post('/loading/scan', [LoadingController::class, 'scan'])
        ->name('loading.scan');


    /*
     * Core application modules
     */
    require __DIR__.'/web/account.php';
    require __DIR__.'/web/inventory.php';
    require __DIR__.'/web/sales.php';
    require __DIR__.'/web/admin.php';
    require __DIR__.'/web/reports.php';
    require __DIR__.'/web/approvals.php';
    require __DIR__.'/web/cycle-counts.php';
});


/*
 * Dashboard
 */
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');


/*
 * EasyPost tracking webhook
 */
Route::post('/webhooks/easypost/{token}', EasyPostWebhookController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:120,1')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.easypost');


/*
 * Authentication
 */
require __DIR__.'/auth.php';


/*
 * Plugin pages
 */
PluginPageRoutes::register();


/*
 * Legacy PESO receiving routes
 *
 * Keep temporarily until the new Receiving UI has been tested.
 */
Route::post(
    '/peso/shipments/{id}/receive-all',
    [PesoShipmentController::class, 'receiveAll']
)->name('peso.shipments.receive-all');

Route::post(
    '/peso/shipments/{id}/verify-barcode',
    [PesoShipmentController::class, 'verifyBarcode']
)->name('peso.shipments.verify-barcode');

Route::get(
    '/peso/shipments/{id}/receive',
    [PesoShipmentController::class, 'receiveAll']
);
