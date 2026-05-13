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
use App\Http\Controllers\Api\Master\MasterTreatmentController;
use App\Http\Controllers\Api\Master\MasterVoucherDiskonController;


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
        Route::apiResource('treatment', MasterTreatmentController::class);
        Route::apiResource('voucher-diskon', MasterVoucherDiskonController::class);
        Route::post('user/{id}/reset-password', [MasterUserController::class, 'resetPassword']);
    });
    
});

Route::prefix('reference')->group(function () {
    Route::get('roles', [ReferenceController::class, 'roles']);
    Route::get('jabatan', [ReferenceController::class, 'jabatan']);
    Route::get('toko', [ReferenceController::class, 'toko']);
    Route::get('karyawan-code', [ReferenceController::class, 'karyawanCode'])->name('reference.karyawan-code');
    Route::get('golongan-produk', [ReferenceController::class, 'golonganProduk']);
    Route::get('kategori-produk', [ReferenceController::class, 'kategoriProduk']);
    Route::get('tempat-produk', [ReferenceController::class, 'tempatProduk']);
    Route::get('satuan', [ReferenceController::class, 'satuan']);
    Route::get('unit-treatment', [ReferenceController::class, 'unitTreatment']);
    Route::get('tipe-treatment', [ReferenceController::class, 'tipeTreatment']);
    Route::get('produk-by-toko', [ReferenceController::class, 'produkByToko']);
    Route::get('treatment-by-toko', [ReferenceController::class, 'treatmentByToko']);
    Route::get('voucher-diskon-jenis', [ReferenceController::class, 'voucherDiskonJenis']);
    Route::get('voucher-diskon-kategori', [ReferenceController::class, 'voucherDiskonKategori']);
    Route::get('voucher-diskon-template', [ReferenceController::class, 'voucherDiskonTemplate']);
});