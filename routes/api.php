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

use App\Http\Controllers\Api\Administrasi\PasienController;

use App\Http\Controllers\Api\Registrasi\RegistrasiLayananController;

use App\Http\Controllers\Api\PelayananMedis\AntrianDokterController;
use App\Http\Controllers\Api\PelayananMedis\AntrianPerawatController;
use App\Http\Controllers\Api\PelayananMedis\RiwayatPelayananController;

use App\Http\Controllers\Api\Kasir\PembayaranController;


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
    
    Route::prefix('administrasi')->group(function () {
        Route::apiResource('pasien', PasienController::class);
    });

    Route::prefix('registrasi-layanan')->group(function () {
        Route::get('/', [RegistrasiLayananController::class, 'index']);
        Route::post('/', [RegistrasiLayananController::class, 'store']);
        Route::get('/antrian-dokter', [RegistrasiLayananController::class, 'antrianDokter']);
        Route::delete('/antrian-dokter/{id}', [RegistrasiLayananController::class, 'destroyAntrianDokter'])
            ->whereNumber('id');
        Route::post('/task/{taskId}/start', [RegistrasiLayananController::class, 'startTask'])
            ->whereNumber('taskId');
        Route::post('/task/{taskId}/finish', [RegistrasiLayananController::class, 'finishTask'])
            ->whereNumber('taskId');
        Route::post('/{id}/start-current-task', [RegistrasiLayananController::class, 'startCurrentTask'])
            ->whereNumber('id');

        Route::post('/{id}/finish-current-task', [RegistrasiLayananController::class, 'finishCurrentTask'])
            ->whereNumber('id');
        Route::get('/{id}', [RegistrasiLayananController::class, 'show'])
            ->whereNumber('id');

        Route::post('/{id}/cancel', [RegistrasiLayananController::class, 'cancel'])
            ->whereNumber('id');
    });

    Route::prefix('pelayanan-medis')->group(function () {
        Route::prefix('antrian-dokter')->group(function () {
            Route::get('/', [AntrianDokterController::class, 'index']);
            Route::get('/{id}', [AntrianDokterController::class, 'show'])->whereNumber('id');
            Route::post('/{id}/start', [AntrianDokterController::class, 'start'])->whereNumber('id');
            Route::post('/{id}/finish', [AntrianDokterController::class, 'finish'])->whereNumber('id');
            Route::delete('/{id}', [AntrianDokterController::class, 'destroy'])->whereNumber('id');
        });
        Route::prefix('antrian-perawat')->group(function () {
            Route::get('/', [AntrianPerawatController::class, 'index']);
            Route::get('/{id}', [AntrianPerawatController::class, 'show'])->whereNumber('id');
            Route::post('/{id}/start', [AntrianPerawatController::class, 'start'])->whereNumber('id');
            Route::post('/{id}/finish', [AntrianPerawatController::class, 'finish'])->whereNumber('id');
            Route::delete('/{id}', [AntrianPerawatController::class, 'destroy'])->whereNumber('id');
        });
        Route::prefix('riwayat-pelayanan')->group(function () {
            Route::get('/', [RiwayatPelayananController::class, 'index']);
            Route::get('/{id}', [RiwayatPelayananController::class, 'show'])
                ->whereNumber('id');
        });
    });

    Route::prefix('kasir')->group(function () {
        Route::prefix('pembayaran')->group(function () {
            Route::get('/', [PembayaranController::class, 'index']);
            Route::get('/{id}', [PembayaranController::class, 'show'])->whereNumber('id');
            Route::post('/generate/{registrasiId}', [PembayaranController::class, 'generate'])
                ->whereNumber('registrasiId');
            Route::post('/{id}/start', [PembayaranController::class, 'start'])->whereNumber('id');
            Route::post('/{id}/finish', [PembayaranController::class, 'finish'])->whereNumber('id');
            Route::post('/{id}/recalculate', [PembayaranController::class, 'recalculate'])->whereNumber('id');
            Route::post('/{id}/cancel', [PembayaranController::class, 'cancel'])->whereNumber('id');
        });
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
    Route::get('voucher-diskon-eligible', [ReferenceController::class, 'voucherDiskonEligible']);
    Route::get('provinces', [ReferenceController::class, 'provinces']);
    Route::get('regencies/{provinceCode}', [ReferenceController::class, 'regencies'])->where('provinceCode', '[0-9]+');
    Route::get('districts/{regencyCode}', [ReferenceController::class, 'districts'])->where('regencyCode', '[0-9.]+');
    Route::get('villages/{districtCode}', [ReferenceController::class, 'villages'])->where('districtCode', '[0-9.]+');
    Route::get('agama', [ReferenceController::class, 'agama']);
    Route::get('pekerjaan', [ReferenceController::class, 'pekerjaan']);
    Route::get('pasien', [ReferenceController::class, 'pasien']);
    Route::get('metode-bayar', [ReferenceController::class, 'metodeBayar']);
});