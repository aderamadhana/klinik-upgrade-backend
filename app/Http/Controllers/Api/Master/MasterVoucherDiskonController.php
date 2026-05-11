<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterVoucherDiskon;
use App\Models\Master\MasterVoucherDiskonItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterVoucherDiskonController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $tokoId = $request->get('toko_id');
        $modeVoucher = $request->get('mode_voucher');
        $tipeDiskon = $request->get('tipe_diskon');
        $statusVoucher = $request->get('status_voucher');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterVoucherDiskon::query()
            ->active()
            ->with(['toko', 'items'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('nama_voucher', 'like', "%{$search}%")
                        ->orWhere('deskripsi', 'like', "%{$search}%")
                        ->orWhere('mode_voucher', 'like', "%{$search}%")
                        ->orWhere('tipe_diskon', 'like', "%{$search}%")
                        ->orWhereHas('toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        });
                });
            })
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->where(function ($qq) use ($tokoId) {
                    $qq->where('is_all_toko', 1)
                        ->orWhere('toko_id', $tokoId);
                });
            })
            ->when($modeVoucher, function ($q) use ($modeVoucher) {
                $q->where('mode_voucher', $modeVoucher);
            })
            ->when($tipeDiskon, function ($q) use ($tipeDiskon) {
                $q->where('tipe_diskon', $tipeDiskon);
            })
            ->when($statusVoucher !== null && $statusVoucher !== '', function ($q) use ($statusVoucher) {
                $q->where('status_voucher', (int) $statusVoucher);
            })
            ->orderBy('sort_order')
            ->orderBy('nama_voucher');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data voucher diskon berhasil diambil',
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'legacy_id' => 'nullable|integer',

            'nama_voucher' => 'required|string|max:150',
            'deskripsi' => 'nullable|string',

            'mode_voucher' => [
                'required',
                Rule::in(['direct', 'generate']),
            ],

            'toko_id' => 'nullable|exists:master_toko,id',
            'is_all_toko' => 'nullable|boolean',

            'kategori_voucher_id' => 'nullable|integer',
            'jenis_voucher_id' => 'nullable|integer',
            'template_voucher_id' => 'nullable|integer',

            'tipe_diskon' => [
                'required',
                Rule::in(['percent', 'nominal']),
            ],

            'total_diskon' => 'required|numeric|min:0',
            'qty_generate' => 'nullable|integer|min:1',

            'is_bisa_digabung_promo' => 'nullable|boolean',
            'is_unlimited_date' => 'nullable|boolean',

            'tanggal_mulai' => 'nullable|date',
            'tanggal_akhir' => 'nullable|date|after_or_equal:tanggal_mulai',

            'status_voucher' => 'nullable|integer|in:0,1,2',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'items' => 'nullable|array',
            'items.*.item_type' => [
                'required_with:items',
                Rule::in(['treatment', 'produk']),
            ],
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.harga_snapshot' => 'nullable|numeric|min:0',
            'items.*.tipe_diskon_item' => [
                'nullable',
                Rule::in(['percent', 'nominal']),
            ],
            'items.*.nilai_diskon_item' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $businessError = $this->validateBusinessRule($request);

        if ($businessError) {
            return response()->json([
                'status' => false,
                'message' => $businessError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $voucher = MasterVoucherDiskon::create([
                'legacy_id' => $request->legacy_id,

                'nama_voucher' => $request->nama_voucher,
                'deskripsi' => $request->deskripsi,

                'mode_voucher' => $request->mode_voucher,

                'toko_id' => $request->is_all_toko ? null : $request->toko_id,
                'is_all_toko' => $request->is_all_toko ?? 0,

                'kategori_voucher_id' => $request->kategori_voucher_id,
                'jenis_voucher_id' => $request->jenis_voucher_id,
                'template_voucher_id' => $request->template_voucher_id,

                'tipe_diskon' => $request->tipe_diskon,
                'total_diskon' => $request->total_diskon,

                'qty_generate' => $request->mode_voucher === 'generate'
                    ? ($request->qty_generate ?? 1)
                    : 1,

                'is_bisa_digabung_promo' => $request->is_bisa_digabung_promo ?? 0,
                'is_unlimited_date' => $request->is_unlimited_date ?? 0,

                'tanggal_mulai' => $request->is_unlimited_date ? null : $request->tanggal_mulai,
                'tanggal_akhir' => $request->is_unlimited_date ? null : $request->tanggal_akhir,

                'status_voucher' => $request->status_voucher ?? 0,
                'is_delete' => 0,
                'sort_order' => $request->sort_order ?? 0,

                'created_by' => $actor,
                'created_at' => now(),
            ]);

            $this->syncItems($voucher->id, $request->items ?? [], $actor);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data voucher diskon berhasil disimpan',
                'data' => $voucher->fresh()->load(['toko', 'items']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data voucher diskon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterVoucherDiskon::query()
            ->active()
            ->with(['toko', 'items'])
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data voucher diskon tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail voucher diskon berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $voucher = MasterVoucherDiskon::active()->find($id);

        if (!$voucher) {
            return response()->json([
                'status' => false,
                'message' => 'Data voucher diskon tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'legacy_id' => 'nullable|integer',

            'nama_voucher' => 'required|string|max:150',
            'deskripsi' => 'nullable|string',

            'mode_voucher' => [
                'required',
                Rule::in(['direct', 'generate']),
            ],

            'toko_id' => 'nullable|exists:master_toko,id',
            'is_all_toko' => 'nullable|boolean',

            'kategori_voucher_id' => 'nullable|integer',
            'jenis_voucher_id' => 'nullable|integer',
            'template_voucher_id' => 'nullable|integer',

            'tipe_diskon' => [
                'required',
                Rule::in(['percent', 'nominal']),
            ],

            'total_diskon' => 'required|numeric|min:0',
            'qty_generate' => 'nullable|integer|min:1',

            'is_bisa_digabung_promo' => 'nullable|boolean',
            'is_unlimited_date' => 'nullable|boolean',

            'tanggal_mulai' => 'nullable|date',
            'tanggal_akhir' => 'nullable|date|after_or_equal:tanggal_mulai',

            'status_voucher' => 'nullable|integer|in:0,1,2',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'items' => 'nullable|array',
            'items.*.item_type' => [
                'required_with:items',
                Rule::in(['treatment', 'produk']),
            ],
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.harga_snapshot' => 'nullable|numeric|min:0',
            'items.*.tipe_diskon_item' => [
                'nullable',
                Rule::in(['percent', 'nominal']),
            ],
            'items.*.nilai_diskon_item' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $businessError = $this->validateBusinessRule($request);

        if ($businessError) {
            return response()->json([
                'status' => false,
                'message' => $businessError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $voucher->update([
                'legacy_id' => $request->legacy_id,

                'nama_voucher' => $request->nama_voucher,
                'deskripsi' => $request->deskripsi,

                'mode_voucher' => $request->mode_voucher,

                'toko_id' => $request->is_all_toko ? null : $request->toko_id,
                'is_all_toko' => $request->is_all_toko ?? 0,

                'kategori_voucher_id' => $request->kategori_voucher_id,
                'jenis_voucher_id' => $request->jenis_voucher_id,
                'template_voucher_id' => $request->template_voucher_id,

                'tipe_diskon' => $request->tipe_diskon,
                'total_diskon' => $request->total_diskon,

                'qty_generate' => $request->mode_voucher === 'generate'
                    ? ($request->qty_generate ?? 1)
                    : 1,

                'is_bisa_digabung_promo' => $request->is_bisa_digabung_promo ?? 0,
                'is_unlimited_date' => $request->is_unlimited_date ?? 0,

                'tanggal_mulai' => $request->is_unlimited_date ? null : $request->tanggal_mulai,
                'tanggal_akhir' => $request->is_unlimited_date ? null : $request->tanggal_akhir,

                'status_voucher' => $request->status_voucher ?? 0,
                'sort_order' => $request->sort_order ?? 0,

                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            if ($request->has('items')) {
                $this->syncItems($voucher->id, $request->items ?? [], $actor);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data voucher diskon berhasil diperbarui',
                'data' => $voucher->fresh()->load(['toko', 'items']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data voucher diskon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $voucher = MasterVoucherDiskon::active()->find($id);

        if (!$voucher) {
            return response()->json([
                'status' => false,
                'message' => 'Data voucher diskon tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $voucher->update([
                'is_delete' => 1,
                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            MasterVoucherDiskonItem::where('voucher_diskon_id', $id)->update([
                'is_delete' => 1,
                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data voucher diskon berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data voucher diskon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateBusinessRule(Request $request): ?string
    {
        if ((int) ($request->is_all_toko ?? 0) === 0 && !$request->toko_id) {
            return 'Toko wajib dipilih jika voucher tidak berlaku untuk semua toko';
        }

        if ($request->mode_voucher === 'generate') {
            if (!$request->qty_generate || (int) $request->qty_generate < 1) {
                return 'Qty generate wajib diisi minimal 1 untuk mode generate';
            }
        }

        if ($request->tipe_diskon === 'percent') {
            $totalDiskon = (float) $request->total_diskon;

            if ($totalDiskon < 0 || $totalDiskon > 100) {
                return 'Total diskon persen harus antara 0 sampai 100';
            }
        }

        if ((int) ($request->is_unlimited_date ?? 0) === 0) {
            if (!$request->tanggal_mulai || !$request->tanggal_akhir) {
                return 'Tanggal mulai dan tanggal akhir wajib diisi jika voucher tidak unlimited date';
            }
        }

        $items = $request->items ?? [];

        if (is_array($items) && count($items)) {
            $keys = collect($items)
                ->map(fn ($item) => ($item['item_type'] ?? '') . '-' . ($item['item_id'] ?? ''))
                ->filter()
                ->values();

            if ($keys->unique()->count() !== $keys->count()) {
                return 'Item voucher tidak boleh duplikat';
            }

            foreach ($items as $item) {
                $tipeDiskonItem = $item['tipe_diskon_item'] ?? null;
                $nilaiDiskonItem = $item['nilai_diskon_item'] ?? null;

                if ($tipeDiskonItem === 'percent') {
                    $nilai = (float) ($nilaiDiskonItem ?? 0);

                    if ($nilai < 0 || $nilai > 100) {
                        return 'Nilai diskon item persen harus antara 0 sampai 100';
                    }
                }
            }
        }

        return null;
    }

    private function syncItems($voucherId, array $items, string $actor): void
    {
        MasterVoucherDiskonItem::where('voucher_diskon_id', $voucherId)->update([
            'is_delete' => 1,
            'updated_by' => $actor,
            'updated_at' => now(),
        ]);

        foreach ($items as $item) {
            MasterVoucherDiskonItem::create([
                'voucher_diskon_id' => $voucherId,
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'],
                'harga_snapshot' => $item['harga_snapshot'] ?? null,
                'tipe_diskon_item' => $item['tipe_diskon_item'] ?? null,
                'nilai_diskon_item' => $item['nilai_diskon_item'] ?? null,
                'is_delete' => 0,
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        }
    }
}