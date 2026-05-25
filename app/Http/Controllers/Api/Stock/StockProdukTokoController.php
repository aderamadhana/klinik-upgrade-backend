<?php

namespace App\Http\Controllers\Api\Stock;

use App\Models\Stock\StockProdukToko;
use App\Models\Stock\StockMutasiProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTempatProduk;
use Illuminate\Http\Request;

class StockProdukTokoController extends BaseStockController
{
    public function index(Request $request)
    {
        try {
            $query = StockProdukToko::with([
                    'produk',
                    'produkToko',
                    'toko',
                    'tempatProduk',
                ])
                ->active();

            if ($request->filled('toko_id')) {
                $query->where('toko_id', $request->toko_id);
            }

            if ($request->filled('tempat_produk_id')) {
                $query->where('tempat_produk_id', $request->tempat_produk_id);
            }

            if ($request->filled('produk_id')) {
                $query->where('produk_id', $request->produk_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;

                $query->whereHas('produk', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode_accurate', 'like', "%{$search}%");
                });
            }

            $data = $query
                ->orderBy('toko_id')
                ->orderBy('tempat_produk_id')
                ->orderBy('produk_id')
                ->paginate($request->get('per_page', 15));

            return $this->successResponse($data, 'Data stok berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil data stok', $e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            $data = StockProdukToko::with([
                    'produk',
                    'produkToko',
                    'toko',
                    'tempatProduk',
                ])
                ->active()
                ->findOrFail($id);

            return $this->successResponse($data, 'Detail stok berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil detail stok', $e->getMessage(), 404);
        }
    }

    public function kartuStok(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'produk_toko_id' => 'required|integer',
            'tempat_produk_id' => 'required|integer',
            'tanggal_awal' => 'nullable|date',
            'tanggal_akhir' => 'nullable|date',
        ]);

        try {
            $query = StockMutasiProduk::with([
                    'produk',
                    'produkToko',
                    'toko',
                    'tempatProduk',
                ])
                ->where('toko_id', $request->toko_id)
                ->where('produk_toko_id', $request->produk_toko_id)
                ->where('tempat_produk_id', $request->tempat_produk_id)
                ->notVoid();

            if ($request->filled('tanggal_awal') && $request->filled('tanggal_akhir')) {
                $query->whereBetween('tanggal', [
                    $request->tanggal_awal . ' 00:00:00',
                    $request->tanggal_akhir . ' 23:59:59',
                ]);
            }

            $data = $query
                ->orderBy('tanggal')
                ->orderBy('id')
                ->get();

            return $this->successResponse($data, 'Kartu stok berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil kartu stok', $e->getMessage());
        }
    }

    public function stokTersedia(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'produk_toko_id' => 'required|integer',
            'tempat_produk_id' => 'required|integer',
        ]);

        try {
            /*
            * Prioritas 1:
            * Ambil stok resmi dari stock_produk_toko.
            */
            $stock = StockProdukToko::with([
                    'produk',
                    'produkToko',
                    'tempatProduk',
                ])
                ->active()
                ->where('toko_id', $request->toko_id)
                ->where('produk_toko_id', $request->produk_toko_id)
                ->where('tempat_produk_id', $request->tempat_produk_id)
                ->first();

            if ($stock) {
                $stokAwal = (float) ($stock->stok_awal ?? 0);
                $stokMasuk = (float) ($stock->stok_masuk ?? 0);
                $stokKeluar = (float) ($stock->stok_keluar ?? 0);
                $stokPenyesuaian = (float) ($stock->stok_penyesuaian ?? 0);
                $stokAkhir = (float) ($stock->stok_akhir ?? 0);
                $stokReserved = (float) ($stock->stok_reserved ?? 0);
                $stokTersedia = max($stokAkhir - $stokReserved, 0);

                return $this->successResponse([
                    'id' => $stock->id,
                    'produk_toko_id' => $stock->produk_toko_id,
                    'produk_id' => $stock->produk_id,
                    'toko_id' => $stock->toko_id,
                    'tempat_produk_id' => $stock->tempat_produk_id,

                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $stokMasuk,
                    'stok_keluar' => $stokKeluar,
                    'stok_penyesuaian' => $stokPenyesuaian,
                    'stok_akhir' => $stokAkhir,
                    'stok_reserved' => $stokReserved,
                    'stok_tersedia' => $stokTersedia,
                    'stok_minimum' => (float) ($stock->stok_minimum ?? 0),

                    'harga_beli_terakhir' => (float) ($stock->harga_beli_terakhir ?? 0),
                    'harga_jual_terakhir' => (float) ($stock->harga_jual_terakhir ?? 0),

                    'sumber_stok' => 'stock_produk_toko',
                    'belum_ada_saldo_stok' => 0,

                    'produk' => $stock->produk,
                    'produk_toko' => $stock->produkToko,
                    'tempat_produk' => $stock->tempatProduk,
                ], 'Stok tersedia berhasil diambil');
            }

            /*
            * Prioritas 2:
            * Jika belum ada record di stock_produk_toko,
            * fallback ambil stok awal dari master_produk_toko.
            *
            * Ini mengikuti permintaan Tuan:
            * "jika tidak ada di stock maka ambil stock dari master_produk".
            *
            * Secara struktur database, stok awalnya ada di master_produk_toko.
            */
            $produkToko = MasterProdukToko::with([
                    'produk',
                    'produk.tempatProduk',
                    'toko',
                    'supplier',
                ])
                ->active()
                ->where('id', $request->produk_toko_id)
                ->where('toko_id', $request->toko_id)
                ->first();

            if (!$produkToko) {
                return $this->successResponse([
                    'id' => null,
                    'produk_toko_id' => $request->produk_toko_id,
                    'produk_id' => null,
                    'toko_id' => $request->toko_id,
                    'tempat_produk_id' => $request->tempat_produk_id,

                    'stok_awal' => 0,
                    'stok_masuk' => 0,
                    'stok_keluar' => 0,
                    'stok_penyesuaian' => 0,
                    'stok_akhir' => 0,
                    'stok_reserved' => 0,
                    'stok_tersedia' => 0,
                    'stok_minimum' => 0,

                    'harga_beli_terakhir' => 0,
                    'harga_jual_terakhir' => 0,

                    'sumber_stok' => 'produk_toko_tidak_ditemukan',
                    'belum_ada_saldo_stok' => 1,

                    'produk' => null,
                    'produk_toko' => null,
                    'tempat_produk' => null,
                ], 'Produk toko tidak ditemukan');
            }

            $produk = $produkToko->produk;

            $stokAwalMaster = (float) ($produkToko->stok_awal ?? 0);
            $stokReserved = 0;
            $stokTersedia = max($stokAwalMaster - $stokReserved, 0);

            /*
            * Karena belum ada stock_produk_toko,
            * stok_akhir sementara disamakan dengan stok_awal master.
            */
            return $this->successResponse([
                'id' => null,
                'produk_toko_id' => $produkToko->id,
                'produk_id' => $produkToko->produk_id,
                'toko_id' => $produkToko->toko_id,
                'tempat_produk_id' => $request->tempat_produk_id,

                'stok_awal' => $stokAwalMaster,
                'stok_masuk' => 0,
                'stok_keluar' => 0,
                'stok_penyesuaian' => 0,
                'stok_akhir' => $stokAwalMaster,
                'stok_reserved' => $stokReserved,
                'stok_tersedia' => $stokTersedia,
                'stok_minimum' => (float) ($produkToko->stok_minimum ?? 0),

                'harga_beli_terakhir' => (float) ($produkToko->harga_beli ?? 0),
                'harga_jual_terakhir' => (float) ($produkToko->harga_jual ?? 0),

                'sumber_stok' => 'master_produk_toko',
                'belum_ada_saldo_stok' => 1,

                'produk' => $produk,
                'produk_toko' => $produkToko,
                'tempat_produk' => $produk?->tempatProduk ?? null,
            ], 'Stok diambil dari master produk toko');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil stok tersedia', $e->getMessage());
        }
    }

    public function stockHariIni(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'tempat_produk_id' => 'nullable|integer',
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $tokoId = (int) $request->toko_id;
            $tempatProdukId = $request->filled('tempat_produk_id')
                ? (int) $request->tempat_produk_id
                : null;

            $perPage = (int) $request->get('per_page', 10);

            $tempatMap = MasterTempatProduk::query()
                ->active()
                ->pluck('nama_tempat_produk', 'id')
                ->toArray();

            $query = MasterProdukToko::query()
                ->with([
                    'produk' => function ($q) {
                        $q->select([
                            'id',
                            'kode_accurate',
                            'nama',
                            'tempat_produk_id',
                            'kategori_produk_id',
                            'golongan_produk_id',
                            'is_delete',
                        ]);
                    },
                    'produk.tempatProduk',
                    'stockProdukToko' => function ($q) use ($tokoId, $tempatProdukId) {
                        $q->active()
                            ->where('toko_id', $tokoId);

                        if ($tempatProdukId) {
                            $q->where('tempat_produk_id', $tempatProdukId);
                        }
                    },
                    'stockProdukToko.tempatProduk',
                ])
                ->active()
                ->where('toko_id', $tokoId)
                ->whereHas('produk', function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                });

            if ($tempatProdukId) {
                $query->where(function ($q) use ($tokoId, $tempatProdukId) {
                    $q->whereHas('produk', function ($produkQuery) use ($tempatProdukId) {
                        $produkQuery->where(function ($sub) use ($tempatProdukId) {
                            $sub->where('tempat_produk_id', $tempatProdukId);

                            if ((int) $tempatProdukId === 1) {
                                $sub->orWhereNull('tempat_produk_id');
                            }
                        });
                    })
                    ->orWhereHas('stockProdukToko', function ($stockQuery) use ($tokoId, $tempatProdukId) {
                        $stockQuery->active()
                            ->where('toko_id', $tokoId)
                            ->where('tempat_produk_id', $tempatProdukId);
                    });
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;

                $query->whereHas('produk', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode_accurate', 'like', "%{$search}%");
                });
            }

            $data = $query
                ->orderBy(
                    MasterProdukToko::query()
                        ->getModel()
                        ->getTable() . '.id',
                    'asc'
                )
                ->paginate($perPage);

            $data->getCollection()->transform(function ($produkToko) use ($tempatProdukId, $tempatMap) {
                $produk = $produkToko->produk;

                $stock = $produkToko->stockProdukToko
                    ->sortByDesc('id')
                    ->first();

                $resolvedTempatId = $stock
                    ? $stock->tempat_produk_id
                    : ($produk->tempat_produk_id ?? 1);

                if ($tempatProdukId) {
                    $resolvedTempatId = $tempatProdukId;
                }

                /*
                * Aturan:
                * - Jika stock_produk_toko ada, stok berjalan ambil dari stock_produk_toko.
                * - Jika stock_produk_toko belum ada, stok berjalan fallback dari master_produk_toko.stok_awal.
                * - stok_awal tampilan selalu ambil dari master_produk_toko.stok_awal.
                */
                $stokAwalMaster = (float) ($produkToko->stok_awal ?? 0);

                if ($stock) {
                    $stokAwal = $stokAwalMaster;
                    $stokMasuk = (float) ($stock->stok_masuk ?? 0);
                    $stokKeluar = (float) ($stock->stok_keluar ?? 0);
                    $stokPenyesuaian = (float) ($stock->stok_penyesuaian ?? 0);
                    $stokAkhir = (float) ($stock->stok_akhir ?? 0);
                    $stokReserved = (float) ($stock->stok_reserved ?? 0);

                    $stokMinimum = (float) ($stock->stok_minimum ?? $produkToko->stok_minimum ?? 0);
                    $hargaBeliTerakhir = (float) ($stock->harga_beli_terakhir ?? $produkToko->harga_beli ?? 0);
                    $hargaJualTerakhir = (float) ($stock->harga_jual_terakhir ?? $produkToko->harga_jual ?? 0);

                    $lastMutationAt = $stock->last_mutation_at;
                    $belumAdaSaldoStok = 0;
                    $sumberStok = 'stock_produk_toko';
                    $stockProdukTokoId = $stock->id;
                } else {
                    $stokAwal = $stokAwalMaster;
                    $stokMasuk = 0;
                    $stokKeluar = 0;
                    $stokPenyesuaian = 0;
                    $stokAkhir = $stokAwalMaster;
                    $stokReserved = 0;

                    $stokMinimum = (float) ($produkToko->stok_minimum ?? 0);
                    $hargaBeliTerakhir = (float) ($produkToko->harga_beli ?? 0);
                    $hargaJualTerakhir = (float) ($produkToko->harga_jual ?? 0);

                    $lastMutationAt = null;
                    $belumAdaSaldoStok = 1;
                    $sumberStok = 'master_produk_toko';
                    $stockProdukTokoId = null;
                }

                $stokTersedia = max($stokAkhir - $stokReserved, 0);

                $isStokHabis = $stokTersedia <= 0 ? 1 : 0;
                $isStokMinimum = $stokTersedia > 0 && $stokMinimum > 0 && $stokTersedia <= $stokMinimum ? 1 : 0;

                if ($isStokHabis) {
                    $statusStok = 'KOSONG';
                } elseif ($isStokMinimum) {
                    $statusStok = 'MINIMUM';
                } else {
                    $statusStok = 'AMAN';
                }

                return [
                    'id' => $stockProdukTokoId ?: 'master-' . $produkToko->id . '-' . $resolvedTempatId,

                    'produk_toko_id' => $produkToko->id,
                    'produk_id' => $produkToko->produk_id,
                    'toko_id' => $produkToko->toko_id,

                    'tempat_produk_id' => $resolvedTempatId,
                    'nama_tempat_produk' => $stock && $stock->tempatProduk
                        ? $stock->tempatProduk->nama_tempat_produk
                        : ($tempatMap[$resolvedTempatId] ?? '-'),

                    'kode_produk' => $produk->kode_accurate ?? '-',
                    'nama_produk' => $produk->nama ?? '-',

                    'harga_jual' => (float) ($produkToko->harga_jual ?? 0),
                    'harga_beli' => (float) ($produkToko->harga_beli ?? 0),

                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $stokMasuk,
                    'stok_keluar' => $stokKeluar,
                    'stok_penyesuaian' => $stokPenyesuaian,
                    'stok_akhir' => $stokAkhir,
                    'stok_reserved' => $stokReserved,
                    'stok_tersedia' => $stokTersedia,
                    'stok_minimum' => $stokMinimum,

                    'harga_beli_terakhir' => $hargaBeliTerakhir,
                    'harga_jual_terakhir' => $hargaJualTerakhir,

                    'last_mutation_at' => $lastMutationAt,

                    'belum_ada_saldo_stok' => $belumAdaSaldoStok,
                    'sumber_stok' => $sumberStok,

                    'is_stok_habis' => $isStokHabis,
                    'is_stok_minimum' => $isStokMinimum,
                    'status_stok' => $statusStok,

                    'produk' => $produk,
                    'produk_toko' => $produkToko,
                    'stock_produk_toko' => $stock,
                ];
            });

            return $this->successResponse($data, 'Stock hari ini berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil stock hari ini', $e->getMessage());
        }
    }
}