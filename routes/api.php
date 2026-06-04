<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DashboardController;

use App\Http\Controllers\Api\Master\MasterKaryawanController;
use App\Http\Controllers\Api\Master\MasterUserController;
use App\Http\Controllers\Api\Master\MasterSupplierController;
use App\Http\Controllers\Api\Master\MasterBrandAmbassadorController;
use App\Http\Controllers\Api\Master\MasterMerchandiseController;
use App\Http\Controllers\Api\Master\MasterTokoController;
use App\Http\Controllers\Api\Master\MasterProdukController;
use App\Http\Controllers\Api\Master\MasterTreatmentController;
use App\Http\Controllers\Api\Master\MasterVoucherDiskonController;
use App\Http\Controllers\Api\Master\MasterAntrianKategoriController;
use App\Http\Controllers\Api\Master\MasterAntrianCounterController;
use App\Http\Controllers\Api\Master\MasterPoinRuleController;
use App\Http\Controllers\Api\Master\MasterMemberTierController;
use App\Http\Controllers\Api\Master\MasterTreatmentBahanController;
use App\Http\Controllers\Api\Master\MasterPerawatBahanController;
use App\Http\Controllers\Api\Master\MasterTreatmentPerawatBahanController;

use App\Http\Controllers\Api\Administrasi\PasienController;
use App\Http\Controllers\Api\Administrasi\PasienDepositController;

use App\Http\Controllers\Api\Registrasi\RegistrasiLayananController;

use App\Http\Controllers\Api\PelayananMedis\AntrianDokterController;
use App\Http\Controllers\Api\PelayananMedis\AntrianPerawatController;
use App\Http\Controllers\Api\PelayananMedis\RiwayatPelayananController;

use App\Http\Controllers\Api\Kasir\PembayaranController;

use App\Http\Controllers\Api\Stock\StockProdukTokoController;
use App\Http\Controllers\Api\Stock\StockPenerimaanController;
use App\Http\Controllers\Api\Stock\StockPenyesuaianController;

use App\Http\Controllers\Api\Antrian\AntrianController;
use App\Http\Controllers\Api\Antrian\BookingLayananController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);

Route::middleware('auth:api')->group(function () {
    Route::post('/change-password', [AuthController::class, 'changePassword']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

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
        Route::apiResource('antrian-kategori', MasterAntrianKategoriController::class);
        Route::post('antrian-kategori/sync-from-branch', [MasterAntrianKategoriController::class, 'syncFromBranch']);
        Route::apiResource('antrian-counter', MasterAntrianCounterController::class);
        Route::post('antrian-counter/sync-from-branch', [MasterAntrianCounterController::class, 'syncFromBranch']);
        Route::apiResource('poin-rule', MasterPoinRuleController::class);
        Route::apiResource('member-tier', MasterMemberTierController::class);
        Route::get('treatment-bahan/options', [MasterTreatmentBahanController::class, 'options']);
        Route::post('treatment-bahan/sync-by-treatment/{treatmentId}', [MasterTreatmentBahanController::class, 'syncByTreatment'])->whereNumber('treatmentId');
        Route::apiResource('treatment-bahan', MasterTreatmentBahanController::class);
        Route::get('perawat-bahan/options', [MasterPerawatBahanController::class, 'options']);
        Route::apiResource('perawat-bahan', MasterPerawatBahanController::class);
        Route::get('treatment-bahan/options', [MasterTreatmentPerawatBahanController::class, 'options']);
        Route::apiResource('treatment-bahan', MasterTreatmentPerawatBahanController::class);
    });
    
    Route::prefix('administrasi')->group(function () {
        Route::get('pasien/{id}/saldo-deposit', [PasienDepositController::class, 'show'])
            ->whereNumber('id');
        Route::post('pasien/{id}/saldo-deposit/{depositId}/claim', [PasienDepositController::class, 'claim'])
            ->whereNumber('id')
            ->whereNumber('depositId');
        Route::get('pasien/{id}/riwayat', [PasienController::class, 'riwayat'])->whereNumber('id');
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
        Route::post('/{id}/upload-bukti-chat-konsultasi-online', [RegistrasiLayananController::class, 'uploadBuktiChatKonsultasiOnline'])
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
            Route::get('/{id}/print-invoice', [PembayaranController::class, 'printInvoice'])->whereNumber('id');
            Route::get('/{id}', [PembayaranController::class, 'show'])->whereNumber('id');
            Route::post('/generate/{registrasiId}', [PembayaranController::class, 'generate'])->whereNumber('registrasiId');
            Route::post('/{id}/start', [PembayaranController::class, 'start'])->whereNumber('id');
            Route::post('/{id}/finish', [PembayaranController::class, 'finish'])->whereNumber('id');
            Route::post('/{id}/recalculate', [PembayaranController::class, 'recalculate'])->whereNumber('id');
            Route::post('/{id}/cancel', [PembayaranController::class, 'cancel'])->whereNumber('id');
        });
    });
    Route::prefix('stock')->group(function () {
        Route::get('produk-toko', [StockProdukTokoController::class, 'index']);
        Route::get('produk-toko/stock-hari-ini', [StockProdukTokoController::class, 'stockHariIni']);
        Route::get('produk-toko/stok-tersedia', [StockProdukTokoController::class, 'stokTersedia']);
        Route::get('produk-toko/kartu-stok', [StockProdukTokoController::class, 'kartuStok']);
        Route::get('produk-toko/{id}', [StockProdukTokoController::class, 'show']);

        Route::get('penerimaan', [StockPenerimaanController::class, 'index']);
        Route::post('penerimaan', [StockPenerimaanController::class, 'store']);
        Route::get('penerimaan/{id}', [StockPenerimaanController::class, 'show']);
        Route::put('penerimaan/{id}', [StockPenerimaanController::class, 'update']);
        Route::post('penerimaan/{id}/post', [StockPenerimaanController::class, 'post']);
        Route::post('penerimaan/{id}/cancel', [StockPenerimaanController::class, 'cancel']);

        Route::get('penyesuaian', [StockPenyesuaianController::class, 'index']);
        Route::post('penyesuaian', [StockPenyesuaianController::class, 'store']);
        Route::get('penyesuaian/{id}', [StockPenyesuaianController::class, 'show']);
        Route::put('penyesuaian/{id}', [StockPenyesuaianController::class, 'update']);
        Route::post('penyesuaian/{id}/post', [StockPenyesuaianController::class, 'post']);
        Route::post('penyesuaian/{id}/cancel', [StockPenyesuaianController::class, 'cancel']);
    });

    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/filters', [AuditLogController::class, 'filters']);
        Route::get('/summary', [AuditLogController::class, 'summary']);
        Route::get('/{id}', [AuditLogController::class, 'show']);
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
    Route::get('merchandise', [ReferenceController::class, 'merchandise']);
    Route::get('accurate-item-mapping', [ReferenceController::class, 'accurateItemMapping']);
    Route::get('merchandise', [ReferenceController::class, 'merchandise']);
    Route::get('subjective', [ReferenceController::class, 'subjective']);
    Route::get('assessment', [ReferenceController::class, 'assessment']);
    Route::get('jenis-transaksi', [ReferenceController::class, 'jenisTransaksi']);
    Route::get('sumber-informasi', [ReferenceController::class, 'sumberInformasi']);
    Route::get('bahan-perawat', [ReferenceController::class, 'bahanPerawat']);
});

Route::prefix('antrian')->group(function () {
    Route::get('/kategori', [AntrianController::class, 'kategori']);
    Route::get('/counter', [AntrianController::class, 'counter']);

    Route::post('/ambil-nomor', [AntrianController::class, 'ambilNomor']);
    Route::get('/display', [AntrianController::class, 'display']);

    Route::get('/operator', [AntrianController::class, 'operatorList']);
    Route::post('/operator/panggil-berikutnya', [AntrianController::class, 'panggilBerikutnya']);

    Route::post('/{id}/panggil', [AntrianController::class, 'panggil']);
    Route::post('/{id}/panggil-ulang', [AntrianController::class, 'panggilUlang']);
    Route::post('/{id}/mulai-layanan', [AntrianController::class, 'mulaiLayanan']);
    Route::post('/{id}/lewati', [AntrianController::class, 'lewati']);
    Route::post('/{id}/selesai', [AntrianController::class, 'selesai']);
    Route::post('/{id}/batal', [AntrianController::class, 'batal']);
    Route::post('/{id}/hubungkan-registrasi', [AntrianController::class, 'hubungkanRegistrasi']);

    Route::get('/booking/cari-hari-ini', [AntrianController::class, 'cariBookingHariIni']);
    Route::post('/booking/{bookingId}/check-in', [AntrianController::class, 'checkInBooking']);
});

Route::prefix('booking-layanan')->group(function () {
    Route::get('/', [BookingLayananController::class, 'index']);
    Route::post('/', [BookingLayananController::class, 'store']);
    Route::get('/{id}', [BookingLayananController::class, 'show']);
    Route::put('/{id}', [BookingLayananController::class, 'update']);
    Route::post('/{id}/cancel', [BookingLayananController::class, 'cancel']);
    Route::post('/{id}/no-show', [BookingLayananController::class, 'noShow']);
    Route::post('/{id}/late', [BookingLayananController::class, 'markLate']);
});