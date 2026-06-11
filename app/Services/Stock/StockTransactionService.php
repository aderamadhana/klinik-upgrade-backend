<?php

namespace App\Services\Stock;

use App\Models\Master\MasterProdukToko;
use App\Models\Stock\StockMutasiProduk;
use App\Models\Stock\StockProdukToko;
use App\Models\Stock\StockReservasiProduk;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransactionService
{
    /**
     * Menyiapkan item penjualan registrasi sebelum detail dibuat.
     *
     * Catatan penting:
     * - tempat_produk_id dari request sengaja DIABAIKAN.
     * - stock_produk_toko_id dari request juga tidak dijadikan patokan utama.
     * - Backend memilih row stock_produk_toko yang stok tersedia-nya paling cukup
     *   berdasarkan produk_toko_id + produk_id + toko_id.
     *
     * Kolom tempat_produk_id tetap diisi secara internal dari row stok yang dipilih,
     * karena tabel stock_produk_toko, stock_mutasi_produk, dan stock_reservasi_produk
     * masih memiliki kolom tempat_produk_id NOT NULL.
     */
    public function prepareRegistrasiPenjualanItems(
        array $items,
        int $tokoId,
        string $user = 'system'
    ): array {
        if ($tokoId <= 0) {
            throw ValidationException::withMessages([
                'toko_id' => 'Toko wajib dipilih untuk memproses stok produk.',
            ]);
        }

        return DB::transaction(function () use ($items, $tokoId, $user) {
            $preparedItems = [];

            foreach ($items as $index => $item) {
                $qty = (float) ($item['jumlah'] ?? $item['qty'] ?? 0);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "penjualan.items.{$index}.jumlah" => 'Jumlah produk harus lebih dari 0.',
                    ]);
                }

                $master = $this->resolveMasterProdukToko($item, $tokoId, $index);
                $masterProduk = $this->resolveMasterProduk((int) $master->produk_id, $index);

                $stock = $this->selectAvailableStockIgnoringTempat(
                    $master,
                    $masterProduk,
                    $qty,
                    $user,
                    "penjualan.items.{$index}.jumlah"
                );

                $stokTersedia = $this->availableQty($stock);

                if ($stokTersedia < $qty) {
                    throw ValidationException::withMessages([
                        "penjualan.items.{$index}.jumlah" => "Stok tidak cukup untuk {$masterProduk->nama}. Stok tersedia: {$stokTersedia}, diminta: {$qty}.",
                    ]);
                }

                $harga = array_key_exists('harga', $item)
                    ? (float) $item['harga']
                    : (float) ($master->harga_jual ?? 0);

                $subtotal = array_key_exists('subtotal', $item)
                    ? (float) $item['subtotal']
                    : ($harga * $qty);

                $preparedItems[] = [
                    ...$item,
                    'produk_toko_id' => (int) $master->id,
                    'produk_id' => (int) $master->produk_id,
                    'obat_id' => (int) $master->produk_id,
                    'tempat_produk_id' => (int) $stock->tempat_produk_id,
                    'stock_produk_toko_id' => (int) $stock->id,
                    'nama_produk' => $item['nama_produk']
                        ?? $item['produk_nama']
                        ?? $masterProduk->nama,
                    'produk_nama' => $item['produk_nama']
                        ?? $item['nama_produk']
                        ?? $masterProduk->nama,
                    'harga' => $harga,
                    'jumlah' => $qty,
                    'qty' => $qty,
                    'subtotal' => $subtotal,
                ];
            }

            return $preparedItems;
        });
    }

    /**
     * Dipakai saat produk dipilih di registrasi layanan.
     * Stok fisik belum berkurang, tapi stok_reserved bertambah.
     */
    public function reserveProduk(array $items, array $context)
    {
        return DB::transaction(function () use ($items, $context) {
            $results = [];
            $tokoId = (int) ($context['toko_id'] ?? 0);
            $user = $context['created_by'] ?? 'system';

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? $item['jumlah'] ?? 0);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'Qty reservasi harus lebih dari 0.',
                    ]);
                }

                $master = $this->resolveMasterProdukToko($item, $tokoId, null);
                $masterProduk = $this->resolveMasterProduk((int) $master->produk_id, null);

                $stock = $this->selectAvailableStockIgnoringTempat(
                    $master,
                    $masterProduk,
                    $qty,
                    $user,
                    'stok'
                );

                $stokAkhir = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;
                $stokTersedia = $stokAkhir - $reservedSebelum;

                if ($stokTersedia < $qty) {
                    throw ValidationException::withMessages([
                        'stok' => "Stok tidak cukup untuk produk ID {$master->produk_id}. Stok tersedia: {$stokTersedia}, diminta: {$qty}.",
                    ]);
                }

                $reservedSesudah = $reservedSebelum + $qty;

                $stock->stok_reserved = $reservedSesudah;
                $stock->last_mutation_at = now();
                $stock->updated_by = $user;
                $stock->updated_at = now();
                $stock->save();

                $reservasi = StockReservasiProduk::create([
                    'kode_reservasi' => $context['kode_reservasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),
                    'expired_at' => $context['expired_at'] ?? null,
                    'toko_id' => $tokoId,
                    'tempat_produk_id' => (int) $stock->tempat_produk_id,
                    'produk_toko_id' => (int) $master->id,
                    'produk_id' => (int) $master->produk_id,
                    'qty_reserved' => $qty,
                    'source_type' => $context['source_type'],
                    'source_id' => $context['source_id'] ?? null,
                    'source_detail_id' => $item['source_detail_id'] ?? null,
                    'status' => 'ACTIVE',
                    'keterangan' => $context['keterangan'] ?? 'Reservasi stok dari registrasi layanan',
                    'created_by' => $user,
                    'created_at' => now(),
                ]);

                $this->insertMutasi([
                    'kode_mutasi' => $context['kode_reservasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),
                    'toko_id' => $tokoId,
                    'tempat_produk_id' => (int) $stock->tempat_produk_id,
                    'produk_toko_id' => (int) $master->id,
                    'produk_id' => (int) $master->produk_id,
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
                    'created_by' => $user,
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
            $tokoId = (int) ($context['toko_id'] ?? 0);
            $user = $context['created_by'] ?? 'system';

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? $item['jumlah'] ?? 0);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'Qty penjualan harus lebih dari 0.',
                    ]);
                }

                $master = $this->resolveMasterProdukToko($item, $tokoId, null);
                $masterProduk = $this->resolveMasterProduk((int) $master->produk_id, null);

                $stock = $this->selectAvailableStockIgnoringTempat(
                    $master,
                    $masterProduk,
                    $qty,
                    $user,
                    'stok'
                );

                $stokSebelum = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;
                $stokTersedia = $stokSebelum - $reservedSebelum;

                if ($stokTersedia < $qty) {
                    throw ValidationException::withMessages([
                        'stok' => "Stok tidak cukup untuk produk ID {$master->produk_id}. Stok tersedia: {$stokTersedia}, diminta: {$qty}.",
                    ]);
                }

                $stokSesudah = $stokSebelum - $qty;

                $stock->stok_keluar = (float) $stock->stok_keluar + $qty;
                $stock->stok_akhir = $stokSesudah;
                $stock->last_mutation_at = now();
                $stock->updated_by = $user;
                $stock->updated_at = now();
                $stock->save();

                $mutasi = $this->insertMutasi([
                    'kode_mutasi' => $context['kode_mutasi'] ?? null,
                    'tanggal' => $context['tanggal'] ?? now(),
                    'toko_id' => $tokoId,
                    'tempat_produk_id' => (int) $stock->tempat_produk_id,
                    'produk_toko_id' => (int) $master->id,
                    'produk_id' => (int) $master->produk_id,
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
                    'created_by' => $user,
                ]);

                $mutasiList[] = $mutasi;
            }

            return $mutasiList;
        });
    }

    private function resolveMasterProdukToko(array $item, int $tokoId, ?int $index): MasterProdukToko
    {
        $produkTokoId = (int) (
            $item['produk_toko_id']
            ?? $item['master_produk_toko_id']
            ?? $item['obat_toko_id']
            ?? $item['toko_produk_id']
            ?? 0
        );

        $produkId = (int) (
            $item['produk_id']
            ?? $item['obat_id']
            ?? $item['master_produk_id']
            ?? 0
        );

        $candidateId = (int) ($item['candidate_id'] ?? $item['id'] ?? 0);
        $fieldPrefix = $index === null ? 'produk_id' : "penjualan.items.{$index}.produk_id";

        $masterQuery = MasterProdukToko::query()
            ->where('toko_id', $tokoId)
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            });

        $master = null;

        if ($produkTokoId > 0) {
            $master = (clone $masterQuery)
                ->whereKey($produkTokoId)
                ->lockForUpdate()
                ->first();
        }

        if (!$master && $produkId > 0) {
            $master = (clone $masterQuery)
                ->where('produk_id', $produkId)
                ->lockForUpdate()
                ->first();
        }

        if (!$master && $candidateId > 0) {
            $master = (clone $masterQuery)
                ->where(function ($query) use ($candidateId) {
                    $query->whereKey($candidateId)
                        ->orWhere('produk_id', $candidateId);
                })
                ->lockForUpdate()
                ->first();
        }

        if (!$master) {
            $requestedId = $produkTokoId ?: ($produkId ?: $candidateId);

            throw ValidationException::withMessages([
                $fieldPrefix => "Produk toko tidak ditemukan atau tidak aktif untuk produk ID {$requestedId} di toko {$tokoId}.",
            ]);
        }

        return $master;
    }

    private function resolveMasterProduk(int $produkId, ?int $index): object
    {
        $masterProduk = DB::table('master_produk')
            ->where('id', $produkId)
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first(['id', 'nama', 'tempat_produk_id']);

        if (!$masterProduk) {
            $fieldPrefix = $index === null ? 'produk_id' : "penjualan.items.{$index}.produk_id";

            throw ValidationException::withMessages([
                $fieldPrefix => "Master produk ID {$produkId} tidak ditemukan atau sudah tidak aktif.",
            ]);
        }

        return $masterProduk;
    }

    private function selectAvailableStockIgnoringTempat(
        MasterProdukToko $master,
        object $masterProduk,
        float $qty,
        string $user,
        string $errorKey
    ): StockProdukToko {
        $produkTokoId = (int) $master->id;
        $produkId = (int) $master->produk_id;
        $tokoId = (int) $master->toko_id;
        $fallbackTempatProdukId = (int) ($masterProduk->tempat_produk_id ?? 0);

        if ($fallbackTempatProdukId <= 0) {
            $fallbackTempatProdukId = 1;
        }

        $this->ensureStockRowExists($master, $fallbackTempatProdukId, $user);

        $firstStock = $this->getLockedStockRows($produkTokoId, $produkId, $tokoId)->first();

        if ($firstStock) {
            $this->bootstrapStokAwalDariMasterJikaPerlu($firstStock, $master, $user);
        }

        $stockRows = $this->getLockedStockRows($produkTokoId, $produkId, $tokoId);

        $selectedStock = $stockRows->first(function (StockProdukToko $row) use ($qty) {
            return $this->availableQty($row) >= $qty;
        });

        if ($selectedStock) {
            return $selectedStock;
        }

        $totalTersedia = $stockRows->sum(function (StockProdukToko $row) {
            return $this->availableQty($row);
        });

        if ($totalTersedia < $qty) {
            throw ValidationException::withMessages([
                $errorKey => "Stok tidak cukup untuk {$masterProduk->nama}. Stok tersedia: {$totalTersedia}, diminta: {$qty}.",
            ]);
        }

        throw ValidationException::withMessages([
            $errorKey => "Stok {$masterProduk->nama} tersedia {$totalTersedia}, tetapi tersebar pada lebih dari satu row stok. Gabungkan stok dulu atau aktifkan split stok otomatis sebelum transaksi.",
        ]);
    }

    private function ensureStockRowExists(MasterProdukToko $master, int $tempatProdukId, string $user): void
    {
        $exists = StockProdukToko::query()
            ->where('produk_toko_id', (int) $master->id)
            ->where('produk_id', (int) $master->produk_id)
            ->where('toko_id', (int) $master->toko_id)
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            return;
        }

        try {
            StockProdukToko::create([
                'produk_toko_id' => (int) $master->id,
                'produk_id' => (int) $master->produk_id,
                'toko_id' => (int) $master->toko_id,
                'tempat_produk_id' => $tempatProdukId,
                'stok_awal' => 0,
                'stok_masuk' => 0,
                'stok_keluar' => 0,
                'stok_penyesuaian' => 0,
                'stok_akhir' => 0,
                'stok_reserved' => 0,
                'stok_minimum' => (float) ($master->stok_minimum ?? 0),
                'harga_beli_terakhir' => (float) ($master->harga_beli ?? 0),
                'harga_jual_terakhir' => (float) ($master->harga_jual ?? 0),
                'is_delete' => 0,
                'created_by' => $user,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Race condition insert bersamaan: request lain sudah membuat row stok.
        }
    }

    private function getLockedStockRows(int $produkTokoId, int $produkId, int $tokoId)
    {
        return StockProdukToko::query()
            ->where('produk_toko_id', $produkTokoId)
            ->where('produk_id', $produkId)
            ->where('toko_id', $tokoId)
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderByRaw('(COALESCE(stok_akhir, 0) - COALESCE(stok_reserved, 0)) DESC')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function availableQty(StockProdukToko $stock): float
    {
        return max((float) $stock->stok_akhir - (float) $stock->stok_reserved, 0);
    }

    private function getLockedStockRow(array $payload): StockProdukToko
    {
        $produkTokoId = (int) $payload['produk_toko_id'];
        $produkId = (int) $payload['produk_id'];
        $tokoId = (int) $payload['toko_id'];
        $tempatProdukId = (int) $payload['tempat_produk_id'];
        $user = $payload['user'] ?? 'system';

        $master = MasterProdukToko::query()
            ->whereKey($produkTokoId)
            ->where('produk_id', $produkId)
            ->where('toko_id', $tokoId)
            ->lockForUpdate()
            ->first();

        $stock = StockProdukToko::query()
            ->where('produk_toko_id', $produkTokoId)
            ->where('produk_id', $produkId)
            ->where('toko_id', $tokoId)
            ->where('tempat_produk_id', $tempatProdukId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            try {
                StockProdukToko::create([
                    'produk_toko_id' => $produkTokoId,
                    'produk_id' => $produkId,
                    'toko_id' => $tokoId,
                    'tempat_produk_id' => $tempatProdukId,
                    'stok_awal' => 0,
                    'stok_masuk' => 0,
                    'stok_keluar' => 0,
                    'stok_penyesuaian' => 0,
                    'stok_akhir' => 0,
                    'stok_reserved' => 0,
                    'stok_minimum' => (float) ($master->stok_minimum ?? 0),
                    'harga_beli_terakhir' => (float) ($master->harga_beli ?? 0),
                    'harga_jual_terakhir' => (float) ($master->harga_jual ?? 0),
                    'is_delete' => 0,
                    'created_by' => $user,
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Race condition insert bersamaan: ambil ulang row yang sudah dibuat.
            }

            $stock = StockProdukToko::query()
                ->where('produk_toko_id', $produkTokoId)
                ->where('produk_id', $produkId)
                ->where('toko_id', $tokoId)
                ->where('tempat_produk_id', $tempatProdukId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $this->bootstrapStokAwalDariMasterJikaPerlu(
            $stock,
            $master,
            $user
        );
    }

    private function bootstrapStokAwalDariMasterJikaPerlu(
        StockProdukToko $targetStock,
        ?MasterProdukToko $master,
        string $user
    ): StockProdukToko {
        if (!$master || (float) ($master->stok_awal ?? 0) <= 0) {
            return $targetStock;
        }

        $produkTokoId = (int) $targetStock->produk_toko_id;
        $tokoId = (int) $targetStock->toko_id;

        $stockRows = StockProdukToko::query()
            ->where('produk_toko_id', $produkTokoId)
            ->where('toko_id', $tokoId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $hasMutation = StockMutasiProduk::query()
            ->where('produk_toko_id', $produkTokoId)
            ->where('toko_id', $tokoId)
            ->where(function ($query) {
                $query->whereNull('is_void')
                    ->orWhere('is_void', 0);
            })
            ->exists();

        $hasOperationalStock = $stockRows->contains(function (StockProdukToko $row) {
            return abs((float) $row->stok_awal) > 0
                || abs((float) $row->stok_masuk) > 0
                || abs((float) $row->stok_keluar) > 0
                || abs((float) $row->stok_penyesuaian) > 0
                || abs((float) $row->stok_akhir) > 0
                || abs((float) $row->stok_reserved) > 0
                || !empty($row->last_mutation_at);
        });

        if ($hasMutation || $hasOperationalStock) {
            return $stockRows->firstWhere('id', $targetStock->id) ?? $targetStock;
        }

        $stokAwal = (float) $master->stok_awal;
        $target = $stockRows->firstWhere('id', $targetStock->id) ?? $targetStock;

        $target->stok_awal = $stokAwal;
        $target->stok_akhir = $stokAwal;
        $target->stok_minimum = (float) ($master->stok_minimum ?? 0);
        $target->harga_beli_terakhir = (float) ($master->harga_beli ?? 0);
        $target->harga_jual_terakhir = (float) ($master->harga_jual ?? 0);
        $target->last_mutation_at = now();
        $target->updated_by = $user;
        $target->updated_at = now();
        $target->save();

        $this->insertMutasi([
            'kode_mutasi' => "INIT-MASTER-{$tokoId}-{$produkTokoId}",
            'tanggal' => now(),
            'toko_id' => $tokoId,
            'tempat_produk_id' => (int) $target->tempat_produk_id,
            'produk_toko_id' => $produkTokoId,
            'produk_id' => (int) $target->produk_id,
            'tipe_mutasi' => 'STOK_AWAL',
            'arah_mutasi' => 'IN',
            'qty_masuk' => $stokAwal,
            'qty_keluar' => 0,
            'qty_adjustment' => 0,
            'qty_reserved_delta' => 0,
            'stok_sebelum' => 0,
            'stok_sesudah' => $stokAwal,
            'reserved_sebelum' => 0,
            'reserved_sesudah' => 0,
            'harga_beli' => (float) ($master->harga_beli ?? 0),
            'harga_jual' => (float) ($master->harga_jual ?? 0),
            'ref_type' => 'MASTER_STOCK_FALLBACK',
            'ref_table' => 'master_produk_toko',
            'ref_id' => $produkTokoId,
            'keterangan' => 'Bootstrap stok awal dari master_produk_toko karena ledger stok belum pernah digunakan.',
            'created_by' => $user,
        ]);

        return $target->fresh();
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
