<?php

namespace App\Services\Stock;

use App\Models\Stock\StockProdukToko;
use App\Models\Stock\StockMutasiProduk;
use App\Models\Stock\StockReservasiProduk;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class StockTransactionService
{
    /**
     * Dipakai saat produk dipilih di registrasi layanan.
     * Stok fisik belum berkurang, tapi stok_reserved bertambah.
     */
    public function reserveProduk(array $items, array $context)
    {
        return DB::transaction(function () use ($items, $context) {
            $results = [];

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'Qty reservasi harus lebih dari 0.',
                    ]);
                }

                $stock = $this->getLockedStockRow([
                    'produk_toko_id' => $item['produk_toko_id'],
                    'produk_id' => $item['produk_id'],
                    'toko_id' => $context['toko_id'],
                    'tempat_produk_id' => $item['tempat_produk_id'],
                    'user' => $context['created_by'] ?? 'system',
                ]);

                $stokAkhir = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;
                $stokTersedia = $stokAkhir - $reservedSebelum;

                if ($stokTersedia < $qty) {
                    throw ValidationException::withMessages([
                        'stok' => "Stok tidak cukup untuk produk ID {$item['produk_id']}. Stok tersedia: {$stokTersedia}, diminta: {$qty}.",
                    ]);
                }

                $reservedSesudah = $reservedSebelum + $qty;

                $stock->stok_reserved = $reservedSesudah;
                $stock->last_mutation_at = now();
                $stock->updated_by = $context['created_by'] ?? 'system';
                $stock->updated_at = now();
                $stock->save();

                $reservasi = StockReservasiProduk::create([
                    'kode_reservasi' => $context['kode_reservasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),
                    'expired_at' => $context['expired_at'] ?? null,

                    'toko_id' => $context['toko_id'],
                    'tempat_produk_id' => $item['tempat_produk_id'],
                    'produk_toko_id' => $item['produk_toko_id'],
                    'produk_id' => $item['produk_id'],

                    'qty_reserved' => $qty,

                    'source_type' => $context['source_type'],
                    'source_id' => $context['source_id'] ?? null,
                    'source_detail_id' => $item['source_detail_id'] ?? null,

                    'status' => 'ACTIVE',
                    'keterangan' => $context['keterangan'] ?? 'Reservasi stok dari registrasi layanan',

                    'created_by' => $context['created_by'] ?? 'system',
                    'created_at' => now(),
                ]);

                $this->insertMutasi([
                    'kode_mutasi' => $context['kode_reservasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),

                    'toko_id' => $context['toko_id'],
                    'tempat_produk_id' => $item['tempat_produk_id'],
                    'produk_toko_id' => $item['produk_toko_id'],
                    'produk_id' => $item['produk_id'],

                    'tipe_mutasi' => 'RESERVE',
                    'arah_mutasi' => 'RESERVE',

                    'qty_masuk' => 0,
                    'qty_keluar' => 0,
                    'qty_adjustment' => 0,
                    'qty_reserved_delta' => $qty,

                    'stok_sebelum' => $stokAkhir,
                    'stok_sesudah' => $stokAkhir,

                    'reserved_sebelum' => $reservedSebelum,
                    'reserved_sesudah' => $reservedSesudah,

                    'harga_beli' => (float) $stock->harga_beli_terakhir,
                    'harga_jual' => (float) ($item['harga_jual'] ?? $stock->harga_jual_terakhir),

                    'ref_type' => $context['source_type'],
                    'ref_table' => $context['source_table'] ?? null,
                    'ref_id' => $context['source_id'] ?? null,
                    'ref_detail_id' => $item['source_detail_id'] ?? null,

                    'keterangan' => $context['keterangan'] ?? 'Reserve stok',
                    'created_by' => $context['created_by'] ?? 'system',
                ]);

                $results[] = $reservasi;
            }

            return $results;
        });
    }

    /**
     * Dipakai saat registrasi layanan dibatalkan.
     * Stok fisik tetap, stok_reserved dikurangi.
     */
    public function releaseReservasiBySource(string $sourceType, int $sourceId, array $context = [])
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $context) {
            $reservasiList = StockReservasiProduk::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();

            foreach ($reservasiList as $reservasi) {
                $qty = (float) $reservasi->qty_reserved;

                $stock = $this->getLockedStockRow([
                    'produk_toko_id' => $reservasi->produk_toko_id,
                    'produk_id' => $reservasi->produk_id,
                    'toko_id' => $reservasi->toko_id,
                    'tempat_produk_id' => $reservasi->tempat_produk_id,
                    'user' => $context['created_by'] ?? 'system',
                ]);

                $stokSebelum = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;
                $reservedSesudah = max(0, $reservedSebelum - $qty);

                $stock->stok_reserved = $reservedSesudah;
                $stock->last_mutation_at = now();
                $stock->updated_by = $context['created_by'] ?? 'system';
                $stock->updated_at = now();
                $stock->save();

                $reservasi->status = $context['status'] ?? 'RELEASED';
                $reservasi->released_at = now();
                $reservasi->updated_by = $context['created_by'] ?? 'system';
                $reservasi->updated_at = now();
                $reservasi->save();

                $this->insertMutasi([
                    'kode_mutasi' => $context['kode_mutasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),

                    'toko_id' => $reservasi->toko_id,
                    'tempat_produk_id' => $reservasi->tempat_produk_id,
                    'produk_toko_id' => $reservasi->produk_toko_id,
                    'produk_id' => $reservasi->produk_id,

                    'tipe_mutasi' => 'RELEASE_RESERVE',
                    'arah_mutasi' => 'RELEASE',

                    'qty_masuk' => 0,
                    'qty_keluar' => 0,
                    'qty_adjustment' => 0,
                    'qty_reserved_delta' => -$qty,

                    'stok_sebelum' => $stokSebelum,
                    'stok_sesudah' => $stokSebelum,

                    'reserved_sebelum' => $reservedSebelum,
                    'reserved_sesudah' => $reservedSesudah,

                    'harga_beli' => (float) $stock->harga_beli_terakhir,
                    'harga_jual' => (float) $stock->harga_jual_terakhir,

                    'ref_type' => $sourceType,
                    'ref_table' => $context['source_table'] ?? null,
                    'ref_id' => $sourceId,
                    'ref_detail_id' => $reservasi->source_detail_id,

                    'keterangan' => $context['keterangan'] ?? 'Release reservasi stok',
                    'created_by' => $context['created_by'] ?? 'system',
                ]);
            }

            return $reservasiList;
        });
    }

    /**
     * Dipakai saat pembayaran berhasil dari registrasi yang sebelumnya reserve stok.
     * Stok fisik berkurang dan stok_reserved juga berkurang.
     */
    public function consumeReservasiUntukPenjualan(string $sourceType, int $sourceId, array $context)
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $context) {
            $reservasiList = StockReservasiProduk::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();

            if ($reservasiList->isEmpty()) {
                throw ValidationException::withMessages([
                    'reservasi' => 'Tidak ada reservasi stok aktif untuk diproses.',
                ]);
            }

            foreach ($reservasiList as $reservasi) {
                $qty = (float) $reservasi->qty_reserved;

                $stock = $this->getLockedStockRow([
                    'produk_toko_id' => $reservasi->produk_toko_id,
                    'produk_id' => $reservasi->produk_id,
                    'toko_id' => $reservasi->toko_id,
                    'tempat_produk_id' => $reservasi->tempat_produk_id,
                    'user' => $context['created_by'] ?? 'system',
                ]);

                $stokSebelum = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;

                if ($stokSebelum < $qty) {
                    throw ValidationException::withMessages([
                        'stok' => "Stok fisik tidak cukup untuk produk ID {$reservasi->produk_id}. Stok: {$stokSebelum}, diminta: {$qty}.",
                    ]);
                }

                if ($reservedSebelum < $qty) {
                    throw ValidationException::withMessages([
                        'stok_reserved' => "Stok reserved tidak valid untuk produk ID {$reservasi->produk_id}. Reserved: {$reservedSebelum}, diminta: {$qty}.",
                    ]);
                }

                $stokSesudah = $stokSebelum - $qty;
                $reservedSesudah = $reservedSebelum - $qty;

                $stock->stok_keluar = (float) $stock->stok_keluar + $qty;
                $stock->stok_akhir = $stokSesudah;
                $stock->stok_reserved = $reservedSesudah;
                $stock->last_mutation_at = now();
                $stock->updated_by = $context['created_by'] ?? 'system';
                $stock->updated_at = now();
                $stock->save();

                $reservasi->status = 'CONSUMED';
                $reservasi->consumed_at = now();
                $reservasi->updated_by = $context['created_by'] ?? 'system';
                $reservasi->updated_at = now();
                $reservasi->save();

                $this->insertMutasi([
                    'kode_mutasi' => $context['kode_mutasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),

                    'toko_id' => $reservasi->toko_id,
                    'tempat_produk_id' => $reservasi->tempat_produk_id,
                    'produk_toko_id' => $reservasi->produk_toko_id,
                    'produk_id' => $reservasi->produk_id,

                    'tipe_mutasi' => 'PENJUALAN',
                    'arah_mutasi' => 'OUT',

                    'qty_masuk' => 0,
                    'qty_keluar' => $qty,
                    'qty_adjustment' => 0,
                    'qty_reserved_delta' => -$qty,

                    'stok_sebelum' => $stokSebelum,
                    'stok_sesudah' => $stokSesudah,

                    'reserved_sebelum' => $reservedSebelum,
                    'reserved_sesudah' => $reservedSesudah,

                    'harga_beli' => (float) $stock->harga_beli_terakhir,
                    'harga_jual' => (float) ($context['harga_jual'] ?? $stock->harga_jual_terakhir),

                    'ref_type' => $context['ref_type'] ?? 'PEMBAYARAN',
                    'ref_table' => $context['ref_table'] ?? 'pembayaran',
                    'ref_id' => $context['ref_id'] ?? null,
                    'ref_detail_id' => $reservasi->source_detail_id,

                    'keterangan' => $context['keterangan'] ?? 'Penjualan dari reservasi stok',
                    'created_by' => $context['created_by'] ?? 'system',
                ]);
            }

            return $reservasiList;
        });
    }

    /**
     * Dipakai untuk penjualan langsung tanpa reservasi.
     * Contoh: pasien langsung beli produk dan langsung bayar.
     */
    public function keluarPenjualanTanpaReservasi(array $items, array $context)
    {
        return DB::transaction(function () use ($items, $context) {
            $mutasiList = [];

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'Qty penjualan harus lebih dari 0.',
                    ]);
                }

                $stock = $this->getLockedStockRow([
                    'produk_toko_id' => $item['produk_toko_id'],
                    'produk_id' => $item['produk_id'],
                    'toko_id' => $context['toko_id'],
                    'tempat_produk_id' => $item['tempat_produk_id'],
                    'user' => $context['created_by'] ?? 'system',
                ]);

                $stokSebelum = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;
                $stokTersedia = $stokSebelum - $reservedSebelum;

                if ($stokTersedia < $qty) {
                    throw ValidationException::withMessages([
                        'stok' => "Stok tidak cukup untuk produk ID {$item['produk_id']}. Stok tersedia: {$stokTersedia}, diminta: {$qty}.",
                    ]);
                }

                $stokSesudah = $stokSebelum - $qty;

                $stock->stok_keluar = (float) $stock->stok_keluar + $qty;
                $stock->stok_akhir = $stokSesudah;
                $stock->last_mutation_at = now();
                $stock->updated_by = $context['created_by'] ?? 'system';
                $stock->updated_at = now();
                $stock->save();

                $mutasi = $this->insertMutasi([
                    'kode_mutasi' => $context['kode_mutasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),

                    'toko_id' => $context['toko_id'],
                    'tempat_produk_id' => $item['tempat_produk_id'],
                    'produk_toko_id' => $item['produk_toko_id'],
                    'produk_id' => $item['produk_id'],

                    'tipe_mutasi' => 'PENJUALAN',
                    'arah_mutasi' => 'OUT',

                    'qty_masuk' => 0,
                    'qty_keluar' => $qty,
                    'qty_adjustment' => 0,
                    'qty_reserved_delta' => 0,

                    'stok_sebelum' => $stokSebelum,
                    'stok_sesudah' => $stokSesudah,

                    'reserved_sebelum' => $reservedSebelum,
                    'reserved_sesudah' => $reservedSebelum,

                    'harga_beli' => (float) $stock->harga_beli_terakhir,
                    'harga_jual' => (float) ($item['harga_jual'] ?? $stock->harga_jual_terakhir),

                    'ref_type' => $context['ref_type'] ?? 'PEMBAYARAN',
                    'ref_table' => $context['ref_table'] ?? 'pembayaran',
                    'ref_id' => $context['ref_id'] ?? null,
                    'ref_detail_id' => $item['ref_detail_id'] ?? null,

                    'keterangan' => $context['keterangan'] ?? 'Penjualan produk',
                    'created_by' => $context['created_by'] ?? 'system',
                ]);

                $mutasiList[] = $mutasi;
            }

            return $mutasiList;
        });
    }

    private function getLockedStockRow(array $payload)
    {
        $stock = StockProdukToko::where('produk_toko_id', $payload['produk_toko_id'])
            ->where('toko_id', $payload['toko_id'])
            ->where('tempat_produk_id', $payload['tempat_produk_id'])
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        try {
            StockProdukToko::create([
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
        } catch (QueryException $e) {
            // Jika race condition insert bersamaan, abaikan dan ambil ulang row-nya.
        }

        return StockProdukToko::where('produk_toko_id', $payload['produk_toko_id'])
            ->where('toko_id', $payload['toko_id'])
            ->where('tempat_produk_id', $payload['tempat_produk_id'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function insertMutasi(array $payload)
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