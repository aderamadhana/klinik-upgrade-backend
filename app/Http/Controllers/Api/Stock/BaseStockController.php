<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Controller;
use App\Models\Stock\StockProdukToko;
use App\Models\Stock\StockMutasiProduk;

class BaseStockController extends Controller
{
    protected function successResponse($data = null, $message = 'Berhasil', $code = 200)
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function errorResponse($message = 'Terjadi kesalahan', $error = null, $code = 500)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $error,
        ], $code);
    }

    protected function userName($request)
    {
        $user = $request->user();

        return $user->username
            ?? $user->name
            ?? $user->email
            ?? 'system';
    }

    protected function getOrCreateStockRow($payload)
    {
        return StockProdukToko::where('produk_toko_id', $payload['produk_toko_id'])
            ->where('toko_id', $payload['toko_id'])
            ->where('tempat_produk_id', $payload['tempat_produk_id'])
            ->lockForUpdate()
            ->first()
            ?? StockProdukToko::create([
                'produk_toko_id' => $payload['produk_toko_id'],
                'produk_id' => $payload['produk_id'],
                'toko_id' => $payload['toko_id'],
                'tempat_produk_id' => $payload['tempat_produk_id'],

                'stok_awal' => 0,
                'stok_masuk' => 0,
                'stok_keluar' => 0,
                'stok_penyesuaian' => 0,
                'stok_akhir' => 0,
                'stok_reserved' => 0,
                'stok_minimum' => 0,

                'harga_beli_terakhir' => 0,
                'harga_jual_terakhir' => 0,

                'is_delete' => 0,
                'created_by' => $payload['user'] ?? 'system',
                'created_at' => now(),
            ]);
    }

    protected function insertMutasi($payload)
    {
        return StockMutasiProduk::create([
            'kode_mutasi' => $payload['kode_mutasi'] ?? null,
            'tanggal' => $payload['tanggal'] ?? now(),

            'toko_id' => $payload['toko_id'],
            'tempat_produk_id' => $payload['tempat_produk_id'],
            'produk_toko_id' => $payload['produk_toko_id'],
            'produk_id' => $payload['produk_id'],

            'tipe_mutasi' => $payload['tipe_mutasi'],
            'arah_mutasi' => $payload['arah_mutasi'],

            'qty_masuk' => $payload['qty_masuk'] ?? 0,
            'qty_keluar' => $payload['qty_keluar'] ?? 0,
            'qty_adjustment' => $payload['qty_adjustment'] ?? 0,
            'qty_reserved_delta' => $payload['qty_reserved_delta'] ?? 0,

            'stok_sebelum' => $payload['stok_sebelum'] ?? 0,
            'stok_sesudah' => $payload['stok_sesudah'] ?? 0,

            'reserved_sebelum' => $payload['reserved_sebelum'] ?? 0,
            'reserved_sesudah' => $payload['reserved_sesudah'] ?? 0,

            'harga_beli' => $payload['harga_beli'] ?? 0,
            'harga_jual' => $payload['harga_jual'] ?? 0,

            'ref_type' => $payload['ref_type'] ?? null,
            'ref_table' => $payload['ref_table'] ?? null,
            'ref_id' => $payload['ref_id'] ?? null,
            'ref_detail_id' => $payload['ref_detail_id'] ?? null,

            'keterangan' => $payload['keterangan'] ?? null,

            'is_void' => 0,
            'created_by' => $payload['created_by'] ?? 'system',
            'created_at' => now(),
        ]);
    }
}