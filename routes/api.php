<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\Master\MasterKaryawanController;
use App\Http\Controllers\Api\Master\MasterUserController;


Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/change-password', [AuthController::class, 'changePassword']);
});

Route::middleware('auth:api')->prefix('master')->group(function () {
    Route::apiResource('karyawan', MasterKaryawanController::class);
    Route::apiResource('user', MasterUserController::class);
    Route::post('user/{id}/reset-password', [MasterUserController::class, 'resetPassword']);
});

Route::prefix('reference')->group(function () {
    Route::get('/roles', [ReferenceController::class, 'roles']);
    Route::get('/jabatan', [ReferenceController::class, 'jabatan']);
    Route::get('/toko', [ReferenceController::class, 'toko']);
    Route::get('/karyawan-code', [ReferenceController::class, 'karyawanCode'])->name('reference.karyawan-code');
});