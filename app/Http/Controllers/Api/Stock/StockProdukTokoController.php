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
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $tokoId = (int) $request->toko_id;
        $limit = (int) ($request->limit ?: 500);

        $produkQuery = MasterProdukToko::with(['produk', 'toko'])
            ->active()
            ->where('toko_id', $tokoId);

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $produkQuery->where(function ($q) use ($keyword) {
                $q->whereHas('produk', function ($produkQuery) use ($keyword) {
                    $produkQuery->where('nama_produk', 'like', "%{$keyword}%")
                        ->orWhere('nama', 'like', "%{$keyword}%")
                        ->orWhere('kode_produk', 'like', "%{$keyword}%")
                        ->orWhere('kode_obat', 'like', "%{$keyword}%");
                });
            });
        }

        $produkToko = $produkQuery->orderByDesc('id')
            ->limit($limit)
            ->get();

        $produkTokoIds = $produkToko->pluck('id')->filter()->values();
        $produkIds = $produkToko->pluck('produk_id')->filter()->unique()->values();

        $stockQuery = StockProdukToko::query()
            ->active()
            ->where('toko_id', $tokoId);

        if ($produkTokoIds->isNotEmpty()) {
            $stockQuery->where(function ($query) use ($produkTokoIds, $produkIds) {
                $query->whereIn('produk_toko_id', $produkTokoIds);

                if ($produkIds->isNotEmpty()) {
                    $query->orWhereIn('produk_id', $produkIds);
                }
            });
        } elseif ($produkIds->isNotEmpty()) {
            $stockQuery->whereIn('produk_id', $produkIds);
        }

        $stockRows = $stockQuery->get()
            ->groupBy(function ($stock) {
                return $stock->produk_toko_id ?: 'produk-' . $stock->produk_id;
            });

        $items = $produkToko->map(function ($produk) use ($stockRows) {
            $stockByProdukToko = $stockRows->get($produk->id, collect());
            $stockByProdukId = $stockRows->get('produk-' . $produk->produk_id, collect());
            $stockCollection = $stockByProdukToko->merge($stockByProdukId);

            $latestStock = $stockCollection->sortByDesc('updated_at')->first();

            $stokAkhir = $stockCollection->sum('stok_akhir');
            $stokReserved = $stockCollection->sum('stok_reserved');
            $stokTersedia = $stockCollection->sum('stok_tersedia');

            if (!$stokTersedia && $stokAkhir > 0) {
                $stokTersedia = max($stokAkhir - $stokReserved, 0);
            }

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
                'kode_produk' => optional($produkMaster)->kode_produk ?: optional($produkMaster)->kode_obat ?: $produk->kode_produk ?: $produk->kode_obat,
                'nama_produk' => $namaProduk,
                'satuan' => optional($produkMaster)->satuan ?: optional($produkMaster)->unit ?: $produk->satuan,
                'stok_awal' => $stockCollection->sum('stok_awal'),
                'stok_masuk' => $stockCollection->sum('stok_masuk'),
                'stok_keluar' => $stockCollection->sum('stok_keluar'),
                'stok_penyesuaian' => $stockCollection->sum('stok_penyesuaian'),
                'stok_akhir' => $stokAkhir,
                'stok_reserved' => $stokReserved,
                'stok_tersedia' => $stokTersedia,
                'stok_minimal' => optional($latestStock)->stok_minimal ?: optional($latestStock)->stok_minimum ?: 0,
                'harga_beli' => $produk->harga_beli,
                'harga_jual' => $produk->harga_jual,
                'produk_toko' => $produk,
                'produk' => $produkMaster,
                'updated_at' => optional($latestStock)->updated_at,
            ];
        })->values();

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
