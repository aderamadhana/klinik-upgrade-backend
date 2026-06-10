<?php

namespace App\Http\Controllers\Api\Stock;

use App\Models\Master\MasterProdukToko;
use App\Models\Stock\StockMutasiProduk;
use App\Models\Stock\StockProdukToko;
use Illuminate\Http\Request;

class StockProdukTokoController extends BaseStockController
{
    public function index(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'produk_id' => 'nullable',
            'produk_toko_id' => 'nullable',
            'keyword' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);

        $query = StockProdukToko::with([
            'produkToko.produk',
            'produk',
            'toko',
        ])
            ->active()
            ->where('toko_id', $request->toko_id);

        if ($request->filled('produk_id')) {
            $produkIds = is_array($request->produk_id)
                ? $request->produk_id
                : explode(',', (string) $request->produk_id);

            $produkIds = array_values(array_filter($produkIds, function ($value) {
                return $value !== null && $value !== '';
            }));

            if (!empty($produkIds)) {
                $query->whereIn('produk_id', $produkIds);
            }
        }

        if ($request->filled('produk_toko_id')) {
            $produkTokoIds = is_array($request->produk_toko_id)
                ? $request->produk_toko_id
                : explode(',', (string) $request->produk_toko_id);

            $produkTokoIds = array_values(array_filter($produkTokoIds, function ($value) {
                return $value !== null && $value !== '';
            }));

            if (!empty($produkTokoIds)) {
                $query->whereIn('produk_toko_id', $produkTokoIds);
            }
        }

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;

            $query->where(function ($q) use ($keyword) {
                $q->whereHas('produkToko.produk', function ($produkQuery) use ($keyword) {
                    $produkQuery->where('nama_produk', 'like', "%{$keyword}%")
                        ->orWhere('nama', 'like', "%{$keyword}%")
                        ->orWhere('kode_produk', 'like', "%{$keyword}%")
                        ->orWhere('kode_obat', 'like', "%{$keyword}%");
                })->orWhereHas('produk', function ($produkQuery) use ($keyword) {
                    $produkQuery->where('nama_produk', 'like', "%{$keyword}%")
                        ->orWhere('nama', 'like', "%{$keyword}%")
                        ->orWhere('kode_produk', 'like', "%{$keyword}%")
                        ->orWhere('kode_obat', 'like', "%{$keyword}%");
                });
            });
        }

        $query->orderByDesc('stok_akhir')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($request->filled('per_page')) {
            $data = $query->paginate((int) $request->per_page);
        } else {
            $data = $query->limit((int) ($request->limit ?: 200))->get();
        }

        return $this->successResponse($data);
    }

    public function show($id)
    {
        $stock = StockProdukToko::with([
            'produkToko.produk',
            'produk',
            'toko',
        ])
            ->active()
            ->findOrFail($id);

        return $this->successResponse($stock);
    }

    public function kartuStok(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'produk_id' => 'nullable|integer',
            'produk_toko_id' => 'nullable|integer',
            'tanggal_awal' => 'nullable|date',
            'tanggal_akhir' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        if (!$request->filled('produk_id') && !$request->filled('produk_toko_id')) {
            return $this->errorResponse('Produk wajib dipilih.', [
                'produk_id' => ['produk_id atau produk_toko_id wajib diisi.'],
            ], 422);
        }

        $produkToko = $this->resolveProdukTokoFromRequest($request);
        $produkId = $request->filled('produk_id')
            ? (int) $request->produk_id
            : optional($produkToko)->produk_id;

        if (!$produkId) {
            return $this->errorResponse('Produk tidak ditemukan untuk toko yang dipilih.', [
                'produk_id' => ['Produk tidak ditemukan.'],
            ], 404);
        }

        $query = StockMutasiProduk::with([
            'produkToko.produk',
            'produk',
            'toko',
            'stockProdukToko',
        ])
            ->where('toko_id', $request->toko_id)
            ->where('produk_id', $produkId)
            ->notVoid();

        if ($request->filled('tanggal_awal')) {
            $query->whereDate('tanggal', '>=', $request->tanggal_awal);
        }

        if ($request->filled('tanggal_akhir')) {
            $query->whereDate('tanggal', '<=', $request->tanggal_akhir);
        }

        $mutasi = $query->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->limit((int) ($request->limit ?: 200))
            ->get();

        return $this->successResponse([
            'produk_toko_id' => optional($produkToko)->id,
            'produk_id' => $produkId,
            'toko_id' => (int) $request->toko_id,
            'items' => $mutasi,
        ]);
    }

    public function stokTersedia(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'produk_toko_id' => 'nullable|integer',
            'produk_id' => 'nullable|integer',
        ]);

        if (!$request->filled('produk_id') && !$request->filled('produk_toko_id')) {
            return $this->errorResponse('Produk wajib dipilih.', [
                'produk_id' => ['produk_id atau produk_toko_id wajib diisi.'],
            ], 422);
        }

        $produkToko = $this->resolveProdukTokoFromRequest($request);
        $produkId = $request->filled('produk_id')
            ? (int) $request->produk_id
            : optional($produkToko)->produk_id;

        if (!$produkId) {
            return $this->errorResponse('Produk tidak ditemukan untuk toko yang dipilih.', [
                'produk_id' => ['Produk tidak ditemukan.'],
            ], 404);
        }

        $stockRows = StockProdukToko::with([
            'produkToko.produk',
            'produk',
            'toko',
        ])
            ->active()
            ->where('toko_id', $request->toko_id)
            ->where('produk_id', $produkId)
            ->when($produkToko, function ($query) use ($produkToko) {
                $query->where(function ($q) use ($produkToko) {
                    $q->where('produk_toko_id', $produkToko->id)
                        ->orWhereNull('produk_toko_id');
                });
            })
            ->get();

        $stock = $stockRows->sortByDesc('updated_at')->first();

        return $this->successResponse([
            'produk_toko_id' => optional($produkToko)->id,
            'produk_id' => $produkId,
            'toko_id' => (int) $request->toko_id,
            'stok_awal' => $stockRows->sum('stok_awal'),
            'stok_masuk' => $stockRows->sum('stok_masuk'),
            'stok_keluar' => $stockRows->sum('stok_keluar'),
            'stok_penyesuaian' => $stockRows->sum('stok_penyesuaian'),
            'stok_akhir' => $stockRows->sum('stok_akhir'),
            'stok_reserved' => $stockRows->sum('stok_reserved'),
            'stok_tersedia' => $stockRows->sum('stok_tersedia') ?: max($stockRows->sum('stok_akhir') - $stockRows->sum('stok_reserved'), 0),
            'produk_toko' => $stock ? $stock->produkToko : $produkToko,
            'produk' => $stock ? $stock->produk : optional($produkToko)->produk,
            'stock' => $stock,
            'stock_rows' => $stockRows,
        ]);
    }

    public function stockHariIni(Request $request)
    {
        $request->validate([
            'toko_id' => 'required|integer',
            'keyword' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $tokoId = (int) $request->toko_id;
        $keyword = trim((string) ($request->input('search') ?: $request->input('keyword', '')));
        $usePagination = $request->filled('page') || $request->filled('per_page');
        $perPage = (int) ($request->input('per_page') ?: 10);
        $limit = (int) ($request->input('limit') ?: 500);

        $produkQuery = MasterProdukToko::with(['produk', 'toko'])
            ->active()
            ->where('toko_id', $tokoId);

        if ($keyword !== '') {
            $produkQuery->where(function ($q) use ($keyword) {
                $q->whereHas('produk', function ($produkQuery) use ($keyword) {
                    $produkQuery->where('nama_produk', 'like', "%{$keyword}%")
                        ->orWhere('nama', 'like', "%{$keyword}%")
                        ->orWhere('kode_produk', 'like', "%{$keyword}%")
                        ->orWhere('kode_obat', 'like', "%{$keyword}%");
                });
            });
        }

        $produkQuery->orderByDesc('id');

        if ($usePagination) {
            $result = $produkQuery->paginate($perPage);
            $produkToko = $result->getCollection();
        } else {
            $result = null;
            $produkToko = $produkQuery->limit($limit)->get();
        }

        $produkTokoIds = $produkToko->pluck('id')->filter()->values();
        $produkIds = $produkToko->pluck('produk_id')->filter()->unique()->values();

        $stockRows = collect();
        $mutasiProdukTokoIds = collect();
        $mutasiProdukIds = collect();

        if ($produkTokoIds->isNotEmpty() || $produkIds->isNotEmpty()) {
            $stockQuery = StockProdukToko::query()
                ->active()
                ->where('toko_id', $tokoId)
                ->where(function ($query) use ($produkTokoIds, $produkIds) {
                    if ($produkTokoIds->isNotEmpty()) {
                        $query->whereIn('produk_toko_id', $produkTokoIds);
                    }

                    if ($produkIds->isNotEmpty()) {
                        if ($produkTokoIds->isNotEmpty()) {
                            $query->orWhereIn('produk_id', $produkIds);
                        } else {
                            $query->whereIn('produk_id', $produkIds);
                        }
                    }
                });

            $stockRows = $stockQuery->get()
                ->groupBy(function ($stock) {
                    return $stock->produk_toko_id ?: 'produk-' . $stock->produk_id;
                });

            $mutasi = StockMutasiProduk::query()
                ->where('toko_id', $tokoId)
                ->notVoid()
                ->where(function ($query) use ($produkTokoIds, $produkIds) {
                    if ($produkTokoIds->isNotEmpty()) {
                        $query->whereIn('produk_toko_id', $produkTokoIds);
                    }

                    if ($produkIds->isNotEmpty()) {
                        if ($produkTokoIds->isNotEmpty()) {
                            $query->orWhereIn('produk_id', $produkIds);
                        } else {
                            $query->whereIn('produk_id', $produkIds);
                        }
                    }
                })
                ->get(['produk_toko_id', 'produk_id']);

            $mutasiProdukTokoIds = $mutasi->pluck('produk_toko_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $mutasiProdukIds = $mutasi->pluck('produk_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $items = $produkToko->map(function ($produk) use (
            $stockRows,
            $mutasiProdukTokoIds,
            $mutasiProdukIds
        ) {
            $stockByProdukToko = $stockRows->get($produk->id, collect());
            $stockByProdukId = $stockRows->get('produk-' . $produk->produk_id, collect());
            $stockCollection = $stockByProdukToko->merge($stockByProdukId)->unique('id')->values();
            $latestStock = $stockCollection->sortByDesc('updated_at')->first();

            $hasMutasi = $mutasiProdukTokoIds->contains((int) $produk->id)
                || $mutasiProdukIds->contains((int) $produk->produk_id);

            $hasOperationalStock = $stockCollection->contains(function ($stock) {
                return abs((float) $stock->stok_awal) > 0
                    || abs((float) $stock->stok_masuk) > 0
                    || abs((float) $stock->stok_keluar) > 0
                    || abs((float) $stock->stok_penyesuaian) > 0
                    || abs((float) $stock->stok_akhir) > 0
                    || abs((float) $stock->stok_reserved) > 0
                    || !empty($stock->last_mutation_at);
            });

            $useMasterFallback = $stockCollection->isEmpty()
                || (!$hasOperationalStock && !$hasMutasi);

            $masterStokAwal = (float) ($produk->stok_awal ?? 0);
            $stokAwal = $useMasterFallback
                ? $masterStokAwal
                : (float) $stockCollection->sum('stok_awal');
            $stokMasuk = $useMasterFallback
                ? 0
                : (float) $stockCollection->sum('stok_masuk');
            $stokKeluar = $useMasterFallback
                ? 0
                : (float) $stockCollection->sum('stok_keluar');
            $stokPenyesuaian = $useMasterFallback
                ? 0
                : (float) $stockCollection->sum('stok_penyesuaian');
            $stokAkhir = $useMasterFallback
                ? $masterStokAwal
                : (float) $stockCollection->sum('stok_akhir');
            $stokReserved = $useMasterFallback
                ? 0
                : (float) $stockCollection->sum('stok_reserved');
            $stokTersedia = max($stokAkhir - $stokReserved, 0);
            $stokMinimum = $useMasterFallback
                ? (float) ($produk->stok_minimum ?? 0)
                : (float) (optional($latestStock)->stok_minimum ?? $produk->stok_minimum ?? 0);

            $produkMaster = $produk->produk;
            $namaProduk = optional($produkMaster)->nama_produk
                ?: optional($produkMaster)->nama
                ?: $produk->nama_produk
                ?: $produk->nama
                ?: '-';

            return [
                'id' => optional($latestStock)->id,
                'produk_toko_id' => $produk->id,
                'produk_id' => $produk->produk_id,
                'toko_id' => $produk->toko_id,
                'kode_produk' => optional($produkMaster)->kode_produk
                    ?: optional($produkMaster)->kode_obat
                    ?: $produk->kode_produk
                    ?: $produk->kode_obat,
                'nama_produk' => $namaProduk,
                'satuan' => optional($produkMaster)->satuan
                    ?: optional($produkMaster)->unit
                    ?: $produk->satuan,
                'stok_awal' => $stokAwal,
                'stok_masuk' => $stokMasuk,
                'stok_keluar' => $stokKeluar,
                'stok_penyesuaian' => $stokPenyesuaian,
                'stok_akhir' => $stokAkhir,
                'stok_reserved' => $stokReserved,
                'stok_tersedia' => $stokTersedia,
                'stok_minimal' => $stokMinimum,
                'stok_minimum' => $stokMinimum,
                'harga_beli' => $produk->harga_beli,
                'harga_jual' => $produk->harga_jual,
                'sumber_stok' => $useMasterFallback
                    ? 'master_produk_toko'
                    : 'stock_produk_toko',
                'produk_toko' => $produk,
                'produk' => $produkMaster,
                'updated_at' => optional($latestStock)->updated_at,
            ];
        })->values();

        if ($usePagination) {
            $result->setCollection($items);

            return $this->successResponse($result);
        }

        return $this->successResponse($items);
    }

    private function resolveProdukTokoFromRequest(Request $request): ?MasterProdukToko
    {
        $query = MasterProdukToko::with(['produk'])
            ->active()
            ->where('toko_id', $request->toko_id);

        if ($request->filled('produk_toko_id')) {
            $produkToko = (clone $query)
                ->where('id', $request->produk_toko_id)
                ->first();

            if ($produkToko) {
                return $produkToko;
            }
        }

        if ($request->filled('produk_id')) {
            return $query
                ->where('produk_id', $request->produk_id)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }
}
