<?php

namespace App\Http\Controllers\Api\Stock;

use App\Models\Stock\StockPenerimaan;
use App\Models\Stock\StockPenerimaanDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockPenerimaanController extends BaseStockController
{
    public function index(Request $request)
    {
        try {
            $query = StockPenerimaan::with([
                    'toko',
                    'tempatProduk',
                    'supplier',
                ])
                ->active();

            if ($request->filled('toko_id')) {
                $query->where('toko_id', $request->toko_id);
            }

            if ($request->filled('tempat_produk_id')) {
                $query->where('tempat_produk_id', $request->tempat_produk_id);
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
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('kode_penerimaan', 'like', "%{$search}%")
                        ->orWhere('no_faktur_supplier', 'like', "%{$search}%");
                });
            }

            $data = $query
                ->orderByDesc('tanggal')
                ->orderByDesc('id')
                ->paginate($request->get('per_page', 15));

            return $this->successResponse($data, 'Data penerimaan berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil data penerimaan', $e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            $data = StockPenerimaan::with([
                    'toko',
                    'tempatProduk',
                    'supplier',
                    'details.produk',
                    'details.produkToko',
                ])
                ->active()
                ->findOrFail($id);

            return $this->successResponse($data, 'Detail penerimaan berhasil diambil');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengambil detail penerimaan', $e->getMessage(), 404);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'toko_id' => 'required|integer',
            'tempat_produk_id' => 'required|integer',
            'supplier_id' => 'nullable|integer',
            'no_faktur_supplier' => 'nullable|string|max:100',
            'tanggal_faktur' => 'nullable|date',
            'catatan' => 'nullable|string',

            'details' => 'required|array|min:1',
            'details.*.produk_toko_id' => 'required|integer',
            'details.*.produk_id' => 'required|integer',
            'details.*.qty' => 'required|numeric|min:0.0001',
            'details.*.harga_beli' => 'nullable|numeric|min:0',
            'details.*.harga_jual' => 'nullable|numeric|min:0',
            'details.*.expired_date' => 'nullable|date',
            'details.*.batch_no' => 'nullable|string|max:100',
            'details.*.keterangan' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $kode = $request->kode_penerimaan
                ?? $this->generateKodePenerimaan($request->toko_id);

            $totalQty = 0;
            $totalNominal = 0;

            foreach ($request->details as $detail) {
                $qty = (float) $detail['qty'];
                $hargaBeli = (float) ($detail['harga_beli'] ?? 0);

                $totalQty += $qty;
                $totalNominal += $qty * $hargaBeli;
            }

            $penerimaan = StockPenerimaan::create([
                'kode_penerimaan' => $kode,
                'tanggal' => $request->tanggal,
                'toko_id' => $request->toko_id,
                'tempat_produk_id' => $request->tempat_produk_id,
                'supplier_id' => $request->supplier_id,
                'no_faktur_supplier' => $request->no_faktur_supplier,
                'tanggal_faktur' => $request->tanggal_faktur,
                'status' => 'DRAFT',
                'total_qty' => $totalQty,
                'total_nominal' => $totalNominal,
                'catatan' => $request->catatan,
                'is_delete' => 0,
                'created_by' => $user,
                'created_at' => now(),
            ]);

            foreach ($request->details as $detail) {
                $qty = (float) $detail['qty'];
                $hargaBeli = (float) ($detail['harga_beli'] ?? 0);
                $hargaJual = (float) ($detail['harga_jual'] ?? 0);

                StockPenerimaanDetail::create([
                    'penerimaan_id' => $penerimaan->id,
                    'toko_id' => $request->toko_id,
                    'tempat_produk_id' => $request->tempat_produk_id,
                    'produk_toko_id' => $detail['produk_toko_id'],
                    'produk_id' => $detail['produk_id'],
                    'qty' => $qty,
                    'harga_beli' => $hargaBeli,
                    'harga_jual' => $hargaJual,
                    'subtotal' => $qty * $hargaBeli,
                    'expired_date' => $detail['expired_date'] ?? null,
                    'batch_no' => $detail['batch_no'] ?? null,
                    'keterangan' => $detail['keterangan'] ?? null,
                    'is_delete' => 0,
                    'created_by' => $user,
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return $this->successResponse(
                $penerimaan->load(['details.produk', 'details.produkToko']),
                'Penerimaan berhasil dibuat',
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal membuat penerimaan', $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'toko_id' => 'required|integer',
            'tempat_produk_id' => 'required|integer',
            'supplier_id' => 'nullable|integer',
            'no_faktur_supplier' => 'nullable|string|max:100',
            'tanggal_faktur' => 'nullable|date',
            'catatan' => 'nullable|string',

            'details' => 'required|array|min:1',
            'details.*.produk_toko_id' => 'required|integer',
            'details.*.produk_id' => 'required|integer',
            'details.*.qty' => 'required|numeric|min:0.0001',
            'details.*.harga_beli' => 'nullable|numeric|min:0',
            'details.*.harga_jual' => 'nullable|numeric|min:0',
            'details.*.expired_date' => 'nullable|date',
            'details.*.batch_no' => 'nullable|string|max:100',
            'details.*.keterangan' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $penerimaan = StockPenerimaan::active()->findOrFail($id);

            if ($penerimaan->status !== 'DRAFT') {
                return $this->errorResponse('Penerimaan yang sudah diposting/cancel tidak boleh diedit', null, 422);
            }

            $totalQty = 0;
            $totalNominal = 0;

            foreach ($request->details as $detail) {
                $qty = (float) $detail['qty'];
                $hargaBeli = (float) ($detail['harga_beli'] ?? 0);

                $totalQty += $qty;
                $totalNominal += $qty * $hargaBeli;
            }

            $penerimaan->update([
                'tanggal' => $request->tanggal,
                'toko_id' => $request->toko_id,
                'tempat_produk_id' => $request->tempat_produk_id,
                'supplier_id' => $request->supplier_id,
                'no_faktur_supplier' => $request->no_faktur_supplier,
                'tanggal_faktur' => $request->tanggal_faktur,
                'total_qty' => $totalQty,
                'total_nominal' => $totalNominal,
                'catatan' => $request->catatan,
                'updated_by' => $user,
                'updated_at' => now(),
            ]);

            StockPenerimaanDetail::where('penerimaan_id', $penerimaan->id)
                ->update([
                    'is_delete' => 1,
                    'updated_by' => $user,
                    'updated_at' => now(),
                ]);

            foreach ($request->details as $detail) {
                $qty = (float) $detail['qty'];
                $hargaBeli = (float) ($detail['harga_beli'] ?? 0);
                $hargaJual = (float) ($detail['harga_jual'] ?? 0);

                StockPenerimaanDetail::create([
                    'penerimaan_id' => $penerimaan->id,
                    'toko_id' => $request->toko_id,
                    'tempat_produk_id' => $request->tempat_produk_id,
                    'produk_toko_id' => $detail['produk_toko_id'],
                    'produk_id' => $detail['produk_id'],
                    'qty' => $qty,
                    'harga_beli' => $hargaBeli,
                    'harga_jual' => $hargaJual,
                    'subtotal' => $qty * $hargaBeli,
                    'expired_date' => $detail['expired_date'] ?? null,
                    'batch_no' => $detail['batch_no'] ?? null,
                    'keterangan' => $detail['keterangan'] ?? null,
                    'is_delete' => 0,
                    'created_by' => $user,
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return $this->successResponse(
                $penerimaan->load(['details.produk', 'details.produkToko']),
                'Penerimaan berhasil diperbarui'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal memperbarui penerimaan', $e->getMessage());
        }
    }

    public function post(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $penerimaan = StockPenerimaan::with(['details'])
                ->active()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($penerimaan->status !== 'DRAFT') {
                return $this->errorResponse('Penerimaan sudah diposting atau dibatalkan', null, 422);
            }

            if ($penerimaan->details->count() === 0) {
                return $this->errorResponse('Detail penerimaan kosong', null, 422);
            }

            foreach ($penerimaan->details as $detail) {
                $stock = $this->getOrCreateStockRow([
                    'produk_toko_id' => $detail->produk_toko_id,
                    'produk_id' => $detail->produk_id,
                    'toko_id' => $detail->toko_id,
                    'tempat_produk_id' => $detail->tempat_produk_id,
                    'user' => $user,
                ]);

                $stokSebelum = (float) $stock->stok_akhir;
                $reservedSebelum = (float) $stock->stok_reserved;

                $qty = (float) $detail->qty;

                $stock->stok_masuk = (float) $stock->stok_masuk + $qty;
                $stock->stok_akhir = $stokSebelum + $qty;
                $stock->harga_beli_terakhir = (float) $detail->harga_beli;
                $stock->harga_jual_terakhir = (float) $detail->harga_jual;
                $stock->last_mutation_at = now();
                $stock->updated_by = $user;
                $stock->updated_at = now();
                $stock->save();

                $this->insertMutasi([
                    'kode_mutasi' => $penerimaan->kode_penerimaan,
                    'tanggal' => $penerimaan->tanggal . ' ' . now()->format('H:i:s'),

                    'toko_id' => $detail->toko_id,
                    'tempat_produk_id' => $detail->tempat_produk_id,
                    'produk_toko_id' => $detail->produk_toko_id,
                    'produk_id' => $detail->produk_id,

                    'tipe_mutasi' => 'PENERIMAAN',
                    'arah_mutasi' => 'IN',

                    'qty_masuk' => $qty,
                    'qty_keluar' => 0,
                    'qty_adjustment' => 0,
                    'qty_reserved_delta' => 0,

                    'stok_sebelum' => $stokSebelum,
                    'stok_sesudah' => (float) $stock->stok_akhir,

                    'reserved_sebelum' => $reservedSebelum,
                    'reserved_sesudah' => (float) $stock->stok_reserved,

                    'harga_beli' => (float) $detail->harga_beli,
                    'harga_jual' => (float) $detail->harga_jual,

                    'ref_type' => 'PENERIMAAN',
                    'ref_table' => 'stock_penerimaan',
                    'ref_id' => $penerimaan->id,
                    'ref_detail_id' => $detail->id,

                    'keterangan' => 'Penerimaan stok',
                    'created_by' => $user,
                ]);
            }

            $penerimaan->status = 'POSTED';
            $penerimaan->posted_by = $user;
            $penerimaan->posted_at = now();
            $penerimaan->updated_by = $user;
            $penerimaan->updated_at = now();
            $penerimaan->save();

            DB::commit();

            return $this->successResponse(
                $penerimaan->load(['details.produk', 'details.produkToko']),
                'Penerimaan berhasil diposting'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal posting penerimaan', $e->getMessage());
        }
    }

    public function cancel(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $this->userName($request);

            $penerimaan = StockPenerimaan::active()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($penerimaan->status === 'POSTED') {
                return $this->errorResponse('Penerimaan yang sudah POSTED tidak boleh dibatalkan langsung. Buat koreksi/retur stok.', null, 422);
            }

            if ($penerimaan->status === 'CANCELLED') {
                return $this->errorResponse('Penerimaan sudah dibatalkan', null, 422);
            }

            $penerimaan->status = 'CANCELLED';
            $penerimaan->cancelled_by = $user;
            $penerimaan->cancelled_at = now();
            $penerimaan->updated_by = $user;
            $penerimaan->updated_at = now();
            $penerimaan->save();

            DB::commit();

            return $this->successResponse($penerimaan, 'Penerimaan berhasil dibatalkan');
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Gagal membatalkan penerimaan', $e->getMessage());
        }
    }

    protected function generateKodePenerimaan($tokoId)
    {
        $prefix = 'PNR' . date('ymd') . str_pad($tokoId, 3, '0', STR_PAD_LEFT);

        $last = StockPenerimaan::where('kode_penerimaan', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return $prefix . '001';
        }

        $lastNumber = (int) substr($last->kode_penerimaan, -3);

        return $prefix . str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
    }
}