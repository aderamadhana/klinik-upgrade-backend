<?php

namespace App\Http\Controllers\Api\Stock;

use App\Models\Stock\StockPenyesuaian;
use App\Models\Stock\StockPenyesuaianDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockPenyesuaianController extends BaseStockController
{
    public function index(Request $request)
    {
        try {
            $query = StockPenyesuaian::with([
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

            if ($request->filled('jenis_penyesuaian')) {
                $query->where('jenis_penyesuaian', $request->jenis_penyesuaian);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('tanggal_awal') && $request->filled('tanggal_akhir')) {
                $query->whereBetween('tanggal', [
                    $request->tanggal_awal,
                    $request->tanggal_akhir,
                ]);
            }

            if ($request->filled('search')) {
                $query->where('kode_penyesuaian', 'like', '%' . $request->search . '%');
            }

            $data = $query
                ->orderByDesc('tanggal')
                ->orderByDesc('id')
                ->paginate($request->get('per_page', 15));

            return $this->successResponse($data, 'Data penyesuaian berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil data penyesuaian', $e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            $data = StockPenyesuaian::with([
                    'toko',
                    'tempatProduk',
                    'details.produk',
                    'details.produkToko',
                ])
                ->active()
                ->findOrFail($id);

            return $this->successResponse($data, 'Detail penyesuaian berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil detail penyesuaian', $e->getMessage(), 404);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'toko_id' => 'required|integer',
            'tempat_produk_id' => 'required|integer',
            'jenis_penyesuaian' => 'required|in:STOK_AWAL,KOREKSI,OPNAME',
            'catatan' => 'nullable|string',

            'details' => 'required|array|min:1',
            'details.*.produk_toko_id' => 'required|integer',
            'details.*.produk_id' => 'required|integer',
            'details.*.stok_fisik' => 'required|numeric|min:0',
            'details.*.keterangan' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $kode = $request->kode_penyesuaian
                ?? $this->generateKodePenyesuaian($request->toko_id);

            $penyesuaian = StockPenyesuaian::create([
                'kode_penyesuaian' => $kode,
                'tanggal' => $request->tanggal,
                'toko_id' => $request->toko_id,
                'tempat_produk_id' => $request->tempat_produk_id,
                'jenis_penyesuaian' => $request->jenis_penyesuaian,
                'status' => 'DRAFT',
                'catatan' => $request->catatan,
                'is_delete' => 0,
                'created_by' => $user,
                'created_at' => now(),
            ]);

            foreach ($request->details as $detail) {
                StockPenyesuaianDetail::create([
                    'penyesuaian_id' => $penyesuaian->id,
                    'toko_id' => $request->toko_id,
                    'tempat_produk_id' => $request->tempat_produk_id,
                    'produk_toko_id' => $detail['produk_toko_id'],
                    'produk_id' => $detail['produk_id'],
                    'stok_sistem' => 0,
                    'stok_fisik' => $detail['stok_fisik'],
                    'selisih' => 0,
                    'keterangan' => $detail['keterangan'] ?? null,
                    'is_delete' => 0,
                    'created_by' => $user,
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return $this->successResponse(
                $penyesuaian->load(['details.produk', 'details.produkToko']),
                'Penyesuaian berhasil dibuat',
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal membuat penyesuaian', $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'toko_id' => 'required|integer',
            'tempat_produk_id' => 'required|integer',
            'jenis_penyesuaian' => 'required|in:STOK_AWAL,KOREKSI,OPNAME',
            'catatan' => 'nullable|string',

            'details' => 'required|array|min:1',
            'details.*.produk_toko_id' => 'required|integer',
            'details.*.produk_id' => 'required|integer',
            'details.*.stok_fisik' => 'required|numeric|min:0',
            'details.*.keterangan' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $penyesuaian = StockPenyesuaian::active()->findOrFail($id);

            if ($penyesuaian->status !== 'DRAFT') {
                return $this->errorResponse('Penyesuaian yang sudah diposting/cancel tidak boleh diedit', null, 422);
            }

            $penyesuaian->update([
                'tanggal' => $request->tanggal,
                'toko_id' => $request->toko_id,
                'tempat_produk_id' => $request->tempat_produk_id,
                'jenis_penyesuaian' => $request->jenis_penyesuaian,
                'catatan' => $request->catatan,
                'updated_by' => $user,
                'updated_at' => now(),
            ]);

            StockPenyesuaianDetail::where('penyesuaian_id', $penyesuaian->id)
                ->update([
                    'is_delete' => 1,
                    'updated_by' => $user,
                    'updated_at' => now(),
                ]);

            foreach ($request->details as $detail) {
                StockPenyesuaianDetail::create([
                    'penyesuaian_id' => $penyesuaian->id,
                    'toko_id' => $request->toko_id,
                    'tempat_produk_id' => $request->tempat_produk_id,
                    'produk_toko_id' => $detail['produk_toko_id'],
                    'produk_id' => $detail['produk_id'],
                    'stok_sistem' => 0,
                    'stok_fisik' => $detail['stok_fisik'],
                    'selisih' => 0,
                    'keterangan' => $detail['keterangan'] ?? null,
                    'is_delete' => 0,
                    'created_by' => $user,
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return $this->successResponse(
                $penyesuaian->load(['details.produk', 'details.produkToko']),
                'Penyesuaian berhasil diperbarui'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal memperbarui penyesuaian', $e->getMessage());
        }
    }

    public function post(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $penyesuaian = StockPenyesuaian::with(['details'])
                ->active()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($penyesuaian->status !== 'DRAFT') {
                return $this->errorResponse('Penyesuaian sudah diposting atau dibatalkan', null, 422);
            }

            if ($penyesuaian->details->count() === 0) {
                return $this->errorResponse('Detail penyesuaian kosong', null, 422);
            }

            foreach ($penyesuaian->details as $detail) {
                $stock = $this->getOrCreateStockRow([
                    'produk_toko_id' => $detail->produk_toko_id,
                    'produk_id' => $detail->produk_id,
                    'toko_id' => $detail->toko_id,
                    'tempat_produk_id' => $detail->tempat_produk_id,
                    'user' => $user,
                ]);

                $stokSebelum = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;

                $stokFisik = (float) $detail->stok_fisik;
                $selisih = $stokFisik - $stokSebelum;

                $detail->stok_sistem = $stokSebelum;
                $detail->selisih = $selisih;
                $detail->updated_by = $user;
                $detail->updated_at = now();
                $detail->save();

                if ($penyesuaian->jenis_penyesuaian === 'STOK_AWAL') {
                    $stock->stok_awal = $stokFisik;
                }

                $stock->stok_penyesuaian = (float) $stock->stok_penyesuaian + $selisih;
                $stock->stok_akhir = $stokFisik;
                $stock->last_mutation_at = now();
                $stock->updated_by = $user;
                $stock->updated_at = now();
                $stock->save();

                $tipeMutasi = $penyesuaian->jenis_penyesuaian === 'OPNAME'
                    ? 'OPNAME'
                    : ($penyesuaian->jenis_penyesuaian === 'STOK_AWAL' ? 'STOK_AWAL' : 'PENYESUAIAN');

                $this->insertMutasi([
                    'kode_mutasi' => $penyesuaian->kode_penyesuaian,
                    'tanggal' => $penyesuaian->tanggal . ' ' . now()->format('H:i:s'),

                    'toko_id' => $detail->toko_id,
                    'tempat_produk_id' => $detail->tempat_produk_id,
                    'produk_toko_id' => $detail->produk_toko_id,
                    'produk_id' => $detail->produk_id,

                    'tipe_mutasi' => $tipeMutasi,
                    'arah_mutasi' => 'ADJUST',

                    'qty_masuk' => $selisih > 0 ? $selisih : 0,
                    'qty_keluar' => $selisih < 0 ? abs($selisih) : 0,
                    'qty_adjustment' => $selisih,
                    'qty_reserved_delta' => 0,

                    'stok_sebelum' => $stokSebelum,
                    'stok_sesudah' => $stokFisik,

                    'reserved_sebelum' => $reservedSebelum,
                    'reserved_sesudah' => (float) $stock->stok_reserved,

                    'harga_beli' => (float) $stock->harga_beli_terakhir,
                    'harga_jual' => (float) $stock->harga_jual_terakhir,

                    'ref_type' => 'PENYESUAIAN',
                    'ref_table' => 'stock_penyesuaian',
                    'ref_id' => $penyesuaian->id,
                    'ref_detail_id' => $detail->id,

                    'keterangan' => $detail->keterangan ?? $penyesuaian->jenis_penyesuaian,
                    'created_by' => $user,
                ]);
            }

            $penyesuaian->status = 'POSTED';
            $penyesuaian->posted_by = $user;
            $penyesuaian->posted_at = now();
            $penyesuaian->updated_by = $user;
            $penyesuaian->updated_at = now();
            $penyesuaian->save();

            DB::commit();

            return $this->successResponse(
                $penyesuaian->load(['details.produk', 'details.produkToko']),
                'Penyesuaian berhasil diposting'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal posting penyesuaian', $e->getMessage());
        }
    }

    public function cancel(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $penyesuaian = StockPenyesuaian::active()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($penyesuaian->status === 'POSTED') {
                return $this->errorResponse('Penyesuaian yang sudah POSTED tidak boleh dibatalkan langsung. Buat penyesuaian koreksi baru.', null, 422);
            }

            if ($penyesuaian->status === 'CANCELLED') {
                return $this->errorResponse('Penyesuaian sudah dibatalkan', null, 422);
            }

            $penyesuaian->status = 'CANCELLED';
            $penyesuaian->cancelled_by = $user;
            $penyesuaian->cancelled_at = now();
            $penyesuaian->updated_by = $user;
            $penyesuaian->updated_at = now();
            $penyesuaian->save();

            DB::commit();

            return $this->successResponse($penyesuaian, 'Penyesuaian berhasil dibatalkan');
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal membatalkan penyesuaian', $e->getMessage());
        }
    }

    protected function generateKodePenyesuaian($tokoId)
    {
        $prefix = 'PYS' . date('ymd') . str_pad($tokoId, 3, '0', STR_PAD_LEFT);

        $last = StockPenyesuaian::where('kode_penyesuaian', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return $prefix . '001';
        }

        $lastNumber = (int) substr($last->kode_penyesuaian, -3);

        return $prefix . str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
    }
}