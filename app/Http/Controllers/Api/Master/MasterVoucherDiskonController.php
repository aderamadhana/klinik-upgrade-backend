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
        $statusVoucher = $request->get('status_voucher');
        $modeVoucher = $request->get('mode_voucher');
        $tipeDiskon = $request->get('tipe_diskon');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterVoucherDiskon::query()
            ->active()
            ->with($this->voucherRelations())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('nama_voucher', 'like', "%{$search}%")
                        ->orWhere('kode_voucher', 'like', "%{$search}%")
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
            ->when($statusVoucher !== null && $statusVoucher !== '', function ($q) use ($statusVoucher) {
                $q->where('status_voucher', (int) $statusVoucher);
            })
            ->when($modeVoucher, function ($q) use ($modeVoucher) {
                $q->where('mode_voucher', $modeVoucher);
            })
            ->when($tipeDiskon, function ($q) use ($tipeDiskon) {
                $q->where('tipe_diskon', $this->normalizeTipeDiskonValue($tipeDiskon));
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
        $this->normalizeRequestField($request);

        $validator = Validator::make($request->all(), $this->rules());

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

        $items = $this->normalizeItemsPayload($request);
        $itemsError = $this->validateVoucherItems($items);

        if ($itemsError) {
            return response()->json([
                'status' => false,
                'message' => $itemsError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $voucher = MasterVoucherDiskon::create(
                $this->buildVoucherPayload($request, $actor)
            );

            $this->syncItems($voucher->id, $items, $actor);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data voucher diskon berhasil disimpan',
                'data' => $voucher->fresh()->load($this->voucherRelations()),
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
            ->with($this->voucherRelations())
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
        $this->normalizeRequestField($request);

        $voucher = MasterVoucherDiskon::active()->find($id);

        if (!$voucher) {
            return response()->json([
                'status' => false,
                'message' => 'Data voucher diskon tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $businessError = $this->validateBusinessRule($request, $voucher->id);

        if ($businessError) {
            return response()->json([
                'status' => false,
                'message' => $businessError,
            ], 422);
        }

        $items = $this->normalizeItemsPayload($request);
        $itemsError = $this->validateVoucherItems($items);

        if ($itemsError) {
            return response()->json([
                'status' => false,
                'message' => $itemsError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $voucher->update(
                $this->buildVoucherPayload($request, $actor, true)
            );

            if ($request->has('items')) {
                $this->syncItems($voucher->id, $items, $actor);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data voucher diskon berhasil diperbarui',
                'data' => $voucher->fresh()->load($this->voucherRelations()),
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

    private function rules(): array
    {
        return [
            'kode_voucher' => 'nullable|string|max:100',
            'nama_voucher' => 'required|string|max:150',
            'deskripsi' => 'nullable|string',

            'mode_voucher' => [
                'required',
                'string',
                Rule::in(['direct', 'generate']),
            ],

            'toko_id' => 'nullable|exists:master_toko,id',
            'is_all_toko' => 'nullable|boolean',

            'kategori_voucher_id' => 'required|integer',
            'jenis_voucher_id' => 'nullable|integer',
            'template_voucher_id' => 'nullable|integer',

            'tipe_diskon' => [
                'required',
                'string',
                Rule::in(['percent', 'nominal', 'persen', 'rupiah']),
            ],

            'total_diskon' => 'required|numeric|min:0',
            'total_diskon_maksimal' => 'nullable|numeric|min:0',
            'qty_generate' => 'nullable|integer|min:0',

            'is_unlimited_generate' => 'nullable|boolean',
            'is_bisa_digabung_promo' => 'nullable|boolean',
            'is_unlimited_date' => 'nullable|boolean',

            'tanggal_mulai' => 'nullable|date',
            'tanggal_akhir' => 'nullable|date|after_or_equal:tanggal_mulai',

            'status_voucher' => 'nullable|integer|in:0,1,2',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'items' => 'nullable|array',
            'items.*.item_type' => [
                'required_with:items',
                'string',
                Rule::in(['treatment', 'produk']),
            ],
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.harga_snapshot' => 'nullable|numeric|min:0',
            'items.*.tipe_diskon_item' => [
                'nullable',
                'string',
                Rule::in(['percent', 'nominal', 'persen', 'rupiah']),
            ],
            'items.*.nilai_diskon_item' => 'nullable|numeric|min:0',
        ];
    }

    private function voucherRelations(): array
    {
        return [
            'toko',
            'items' => function ($q) {
                $q->active()
                    ->orderBy('item_type')
                    ->orderBy('id');
            },
        ];
    }

    private function normalizeRequestField(Request $request): void
    {
        if (!$request->has('items') && $request->has('item')) {
            $request->merge([
                'items' => $request->item,
            ]);
        }

        if (!$request->filled('nama_voucher') && $request->filled('nama')) {
            $request->merge([
                'nama_voucher' => $request->nama,
            ]);
        }

        if (!$request->filled('total_diskon') && $request->filled('nilai_diskon')) {
            $request->merge([
                'total_diskon' => $request->nilai_diskon,
            ]);
        }

        if (!$request->filled('total_diskon_maksimal') && $request->filled('maksimal_diskon')) {
            $request->merge([
                'total_diskon_maksimal' => $request->maksimal_diskon,
            ]);
        }

        if (!$request->filled('tipe_diskon') && $request->filled('diskon_type')) {
            $request->merge([
                'tipe_diskon' => $request->diskon_type,
            ]);
        }

        $modeVoucher = $request->input('mode_voucher', 'direct') === 'generate'
            ? 'generate'
            : 'direct';

        $tipeDiskon = $this->normalizeTipeDiskonValue($request->input('tipe_diskon'));

        $isGenerateMode = $modeVoucher === 'generate';

        $totalDiskonMaksimal = $request->input('total_diskon_maksimal');

        $request->merge([
            'mode_voucher' => $modeVoucher,
            'kode_voucher' => $isGenerateMode
                ? null
                : $this->normalizeKodeVoucher($request->input('kode_voucher')),
            'tipe_diskon' => $tipeDiskon,
            'total_diskon_maksimal' => $tipeDiskon === 'percent'
                ? $this->toNullableNumber($totalDiskonMaksimal)
                : null,
            'is_all_toko' => $this->toIntBool($request->input('is_all_toko')),
            'is_unlimited_date' => $this->toIntBool($request->input('is_unlimited_date')),
            'is_unlimited_generate' => $isGenerateMode
                ? 0
                : $this->toIntBool($request->input('is_unlimited_generate')),
            'is_bisa_digabung_promo' => $this->toIntBool($request->input('is_bisa_digabung_promo')),
        ]);

        if ((int) $request->input('is_all_toko') === 1) {
            $request->merge([
                'toko_id' => null,
            ]);
        }

        if ((int) $request->input('is_unlimited_date') === 1) {
            $request->merge([
                'tanggal_mulai' => null,
                'tanggal_akhir' => null,
            ]);
        }

        if ($request->input('mode_voucher') === 'generate') {
            $request->merge([
                'kode_voucher' => null,
                'is_unlimited_generate' => 0,
                'qty_generate' => max(1, (int) $request->input('qty_generate', 1)),
            ]);

            return;
        }

        if ((int) $request->input('is_unlimited_generate') === 1) {
            $request->merge([
                'qty_generate' => 0,
            ]);

            return;
        }

        $request->merge([
            'qty_generate' => max(1, (int) $request->input('qty_generate', 1)),
        ]);
    }

    private function validateBusinessRule(Request $request, $ignoreId = null): ?string
    {
        $isAllToko = (int) ($request->is_all_toko ?? 0);
        $isUnlimitedDate = (int) ($request->is_unlimited_date ?? 0);
        $isUnlimitedGenerate = (int) ($request->is_unlimited_generate ?? 0);
        $modeVoucher = $request->mode_voucher;
        $tipeDiskon = $this->normalizeTipeDiskonValue($request->tipe_diskon);

        if ($isAllToko !== 1 && !$request->toko_id) {
            return 'Toko wajib dipilih jika voucher tidak berlaku untuk semua cabang';
        }

        if ($isUnlimitedDate !== 1) {
            if (!$request->tanggal_mulai) {
                return 'Tanggal mulai wajib diisi jika voucher memakai periode tanggal';
            }

            if (!$request->tanggal_akhir) {
                return 'Tanggal akhir wajib diisi jika voucher memakai periode tanggal';
            }
        }

        if ($tipeDiskon === 'percent') {
            if ((float) $request->total_diskon > 100) {
                return 'Total diskon persen tidak boleh lebih dari 100';
            }

            if ((float) ($request->total_diskon_maksimal ?? 0) < 1) {
                return 'Total diskon maksimal wajib diisi jika tipe diskon persen';
            }
        }

        if ($modeVoucher === 'direct') {
            if (!$request->kode_voucher) {
                return 'Kode voucher wajib diisi untuk voucher direct';
            }

            if ($this->isKodeVoucherExists($request->kode_voucher, $ignoreId)) {
                return 'Kode voucher sudah digunakan pada voucher lain';
            }

            if ($isUnlimitedGenerate !== 1 && (int) ($request->qty_generate ?? 0) < 1) {
                return 'Batas jumlah penggunaan minimal 1';
            }
        }

        if ($modeVoucher === 'generate') {
            if ((int) ($request->qty_generate ?? 0) < 1) {
                return 'Jumlah voucher generate minimal 1';
            }

            if ($isUnlimitedGenerate === 1) {
                return 'Generate voucher tidak bisa memakai opsi tanpa batas jumlah';
            }
        }

        return null;
    }

    private function buildVoucherPayload(Request $request, string $actor, bool $isUpdate = false): array
    {
        $modeVoucher = $request->mode_voucher;
        $isDirectMode = $modeVoucher === 'direct';
        $isUnlimitedGenerate = $isDirectMode && (int) ($request->is_unlimited_generate ?? 0) === 1;
        $tipeDiskon = $this->normalizeTipeDiskonValue($request->tipe_diskon);

        $payload = [
            'kode_voucher' => $isDirectMode ? $request->kode_voucher : null,
            'nama_voucher' => $request->nama_voucher,
            'deskripsi' => $request->deskripsi,

            'mode_voucher' => $modeVoucher,

            'toko_id' => (int) ($request->is_all_toko ?? 0) === 1 ? null : $request->toko_id,
            'is_all_toko' => (int) ($request->is_all_toko ?? 0),

            'kategori_voucher_id' => $request->kategori_voucher_id,
            'jenis_voucher_id' => $request->jenis_voucher_id,
            'template_voucher_id' => $request->template_voucher_id,

            'tipe_diskon' => $tipeDiskon,
            'total_diskon' => $request->total_diskon,
            'total_diskon_maksimal' => $tipeDiskon === 'percent'
                ? (float) ($request->total_diskon_maksimal ?? 0)
                : null,

            'is_unlimited_generate' => $isUnlimitedGenerate ? 1 : 0,
            'qty_generate' => $isUnlimitedGenerate ? 0 : (int) ($request->qty_generate ?? 1),

            'is_bisa_digabung_promo' => (int) ($request->is_bisa_digabung_promo ?? 0),
            'is_unlimited_date' => (int) ($request->is_unlimited_date ?? 0),

            'tanggal_mulai' => (int) ($request->is_unlimited_date ?? 0) === 1 ? null : $request->tanggal_mulai,
            'tanggal_akhir' => (int) ($request->is_unlimited_date ?? 0) === 1 ? null : $request->tanggal_akhir,

            'status_voucher' => (int) ($request->status_voucher ?? 0),
            'sort_order' => (int) ($request->sort_order ?? 0),
        ];

        if ($isUpdate) {
            $payload['updated_by'] = $actor;
            $payload['updated_at'] = now();

            return $payload;
        }

        $payload['is_delete'] = 0;
        $payload['created_by'] = $actor;
        $payload['created_at'] = now();

        return $payload;
    }

    private function normalizeItemsPayload(Request $request): array
    {
        $rows = $request->input('items', []);

        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function ($item) {
                $tipeDiskonItem = $item['tipe_diskon_item'] ?? null;
                $nilaiDiskonItem = $item['nilai_diskon_item'] ?? null;

                return [
                    'item_type' => $item['item_type'] ?? null,
                    'item_id' => $item['item_id'] ?? null,
                    'harga_snapshot' => $this->toNullableNumber($item['harga_snapshot'] ?? null),
                    'tipe_diskon_item' => $this->isBlankValue($tipeDiskonItem)
                        ? null
                        : $this->normalizeTipeDiskonValue($tipeDiskonItem),
                    'nilai_diskon_item' => $this->toNullableNumber($nilaiDiskonItem),
                ];
            })
            ->filter(function ($item) {
                return !empty($item['item_type']) && !empty($item['item_id']);
            })
            ->values()
            ->toArray();
    }

    private function validateVoucherItems(array $items): ?string
    {
        if (!count($items)) {
            return null;
        }

        $keys = collect($items)->map(function ($item) {
            return $item['item_type'] . '-' . $item['item_id'];
        });

        if ($keys->unique()->count() !== $keys->count()) {
            return 'Item voucher tidak boleh duplikat';
        }

        foreach ($items as $item) {
            if (
                in_array($item['tipe_diskon_item'], ['percent', 'persen'], true) &&
                $item['nilai_diskon_item'] !== null &&
                (float) $item['nilai_diskon_item'] > 100
            ) {
                return 'Nilai diskon item persen tidak boleh lebih dari 100';
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
            $existingItem = MasterVoucherDiskonItem::where('voucher_diskon_id', $voucherId)
                ->where('item_type', $item['item_type'])
                ->where('item_id', $item['item_id'])
                ->first();

            $payload = [
                'harga_snapshot' => $item['harga_snapshot'],
                'tipe_diskon_item' => $item['tipe_diskon_item'],
                'nilai_diskon_item' => $item['nilai_diskon_item'],
                'is_delete' => 0,
                'updated_by' => $actor,
                'updated_at' => now(),
            ];

            if ($existingItem) {
                $existingItem->update($payload);
                continue;
            }

            MasterVoucherDiskonItem::create([
                'voucher_diskon_id' => $voucherId,
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'],
                'harga_snapshot' => $item['harga_snapshot'],
                'tipe_diskon_item' => $item['tipe_diskon_item'],
                'nilai_diskon_item' => $item['nilai_diskon_item'],
                'is_delete' => 0,
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        }
    }

    private function normalizeKodeVoucher($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $kode = strtoupper(trim((string) $value));
        $kode = preg_replace('/\s+/', '', $kode);
        $kode = preg_replace('/[^A-Z0-9\-_]/', '', $kode);

        return $kode !== '' ? $kode : null;
    }

    private function normalizeTipeDiskonValue($value): string
    {
        $value = strtolower((string) $value);

        if (in_array($value, ['percent', 'persen'], true)) {
            return 'percent';
        }

        if (in_array($value, ['nominal', 'rupiah'], true)) {
            return 'nominal';
        }

        return 'percent';
    }

    private function toIntBool($value): int
    {
        return in_array($value, [true, 1, '1', 'true', 'TRUE', 'yes', 'YES'], true)
            ? 1
            : 0;
    }

    private function isKodeVoucherExists(string $kodeVoucher, $ignoreId = null): bool
    {
        return MasterVoucherDiskon::query()
            ->active()
            ->where('mode_voucher', 'direct')
            ->where('kode_voucher', $kodeVoucher)
            ->when($ignoreId, function ($q) use ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            })
            ->exists();
    }

    private function isBlankValue($value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function toNullableNumber($value): ?float
    {
        if ($this->isBlankValue($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = trim((string) $value);

        $value = str_replace('Rp', '', $value);
        $value = str_replace(' ', '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}