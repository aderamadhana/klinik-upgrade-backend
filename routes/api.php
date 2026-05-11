<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\Master\MasterKaryawanController;
use App\Http\Controllers\Api\Master\MasterUserController;
use App\Http\Controllers\Api\Master\MasterSupplierController;
use App\Http\Controllers\Api\Master\MasterBrandAmbassadorController;
use App\Http\Controllers\Api\Master\MasterMerchandiseController;
use App\Http\Controllers\Api\Master\MasterTokoController;
use App\Http\Controllers\Api\Master\MasterProdukController;


Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);

Route::middleware('auth:api')->group(function () {
    Route::post('/change-password', [AuthController::class, 'changePassword']);
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('master')->group(function () {
        Route::apiResource('karyawan', MasterKaryawanController::class);
        Route::apiResource('user', MasterUserController::class);
        Route::apiResource('supplier', MasterSupplierController::class);
        Route::apiResource('brand-ambassador', MasterBrandAmbassadorController::class);
        Route::apiResource('merchandise', MasterMerchandiseController::class);
        Route::get('toko/options', [MasterTokoController::class, 'options']);
        Route::apiResource('toko', MasterTokoController::class);
        Route::apiResource('produk', MasterProdukController::class);
        Route::post('user/{id}/reset-password', [MasterUserController::class, 'resetPassword']);
    });
    
});

Route::prefix('reference')->group(function () {
    Route::get('/roles', [ReferenceController::class, 'roles']);
    Route::get('/jabatan', [ReferenceController::class, 'jabatan']);
    Route::get('/toko', [ReferenceController::class, 'toko']);
    Route::get('/karyawan-code', [ReferenceController::class, 'karyawanCode'])->name('reference.karyawan-code');
    Route::get('/golongan-produk', [ReferenceController::class, 'golonganProduk']);
    Route::get('/kategori-produk', [ReferenceController::class, 'kategoriProduk']);
    Route::get('/tempat-produk', [ReferenceController::class, 'tempatProduk']);
    Route::get('/satuan', [ReferenceController::class, 'satuan']);
});