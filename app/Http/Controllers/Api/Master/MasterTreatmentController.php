<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterTreatment;
use App\Models\Master\MasterTreatmentToko;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterTreatmentController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        $kategoriSales = $request->get('kategori_sales');
        $unitId = $request->get('unit_id');
        $tipeId = $request->get('tipe_id');
        $tokoId = $request->get('toko_id');
        $isPpn = $request->get('is_ppn');
        $isActive = $request->get('is_active');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterTreatment::query()
            ->active()
            ->with($this->treatmentRelations())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('kode_accurate', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('kategori_sales', 'like', "%{$search}%")
                        ->orWhereHas('unit', function ($unit) use ($search) {
                            $unit->where('nama_unit_treatment', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tipe', function ($tipe) use ($search) {
                            $tipe->where('nama_tipe_treatment', 'like', "%{$search}%");
                        })
                        ->orWhereHas('hargaToko.toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        });
                });
            })
            ->when($kategoriSales, function ($q) use ($kategoriSales) {
                $q->where('kategori_sales', $kategoriSales);
            })
            ->when($unitId, function ($q) use ($unitId) {
                $q->where('unit_id', $unitId);
            })
            ->when($tipeId, function ($q) use ($tipeId) {
                $q->where('tipe_id', $tipeId);
            })
            ->when($isPpn !== null && $isPpn !== '', function ($q) use ($isPpn) {
                $q->where('is_ppn', (int) $isPpn);
            })
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->whereHas('hargaToko', function ($hargaToko) use ($tokoId) {
                    $hargaToko->active()
                        ->where('toko_id', $tokoId);
                });
            })
            ->when($isActive !== null && $isActive !== '', function ($q) use ($isActive) {
                $q->whereHas('hargaToko', function ($hargaToko) use ($isActive) {
                    $hargaToko->active()
                        ->where('is_active', (int) $isActive);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data treatment berhasil diambil',
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

        $validator = Validator::make($request->all(), [
            'kode_accurate' => 'nullable|string|max:50',
            'nama' => 'required|string|max:255',
            'kategori_sales' => 'nullable|string|max:100',

            'unit_id' => 'nullable|exists:master_unit_treatment,id',
            'tipe_id' => 'nullable|exists:master_tipe_treatment,id',

            'waktu' => 'nullable|integer|min:0',
            'is_ppn' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'toko_mapping' => 'required|array|min:1',
            'toko_mapping.*.toko_id' => 'required|exists:master_toko,id',

            'toko_mapping.*.harga_terendah' => 'nullable|numeric|min:0',
            'toko_mapping.*.tarif' => 'nullable|numeric|min:0',
            'toko_mapping.*.biaya_modal' => 'nullable|numeric|min:0',

            'toko_mapping.*.tarif_dokter' => 'nullable|numeric|min:0',
            'toko_mapping.*.tarif_beautician' => 'nullable|numeric|min:0',

            'toko_mapping.*.presentase_tarif_dokter' => 'nullable|numeric|min:0|max:100',
            'toko_mapping.*.presentase_tarif_dokter_sp' => 'nullable|numeric|min:0|max:100',

            'toko_mapping.*.flat_tarif_dokter' => 'nullable|numeric|min:0',
            'toko_mapping.*.flat_tarif_dokter_sp' => 'nullable|numeric|min:0',

            'toko_mapping.*.insentif_use' => 'nullable|string|max:30',
            'toko_mapping.*.insentif_use_sp' => 'nullable|string|max:30',

            'toko_mapping.*.is_active' => 'nullable|boolean',
            'toko_mapping.*.sort_order' => 'nullable|integer|min:0|max:32767',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokoPayload = $this->normalizeTokoPayload($request);
        $tokoError = $this->validateTreatmentToko($tokoPayload);

        if ($tokoError) {
            return response()->json([
                'status' => false,
                'message' => $tokoError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $treatment = MasterTreatment::create([
                'kode_accurate' => $request->kode_accurate,
                'nama' => $request->nama,
                'kategori_sales' => $request->kategori_sales,

                'unit_id' => $request->unit_id,
                'tipe_id' => $request->tipe_id,

                'waktu' => $request->waktu ?? 0,
                'is_ppn' => $request->is_ppn ?? 0,
                'is_delete' => 0,
                'sort_order' => $request->sort_order ?? 0,

                'created_by' => $actor,
                'created_at' => now(),
            ]);

            $this->syncTreatmentToko($treatment->id, $tokoPayload, $actor);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data treatment berhasil disimpan',
                'data' => $treatment->fresh()->load($this->treatmentRelations()),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterTreatment::query()
            ->active()
            ->with($this->treatmentRelations())
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data treatment tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->normalizeRequestField($request);

        $treatment = MasterTreatment::active()->find($id);

        if (!$treatment) {
            return response()->json([
                'status' => false,
                'message' => 'Data treatment tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'kode_accurate' => 'nullable|string|max:50',
            'nama' => 'required|string|max:255',
            'kategori_sales' => 'nullable|string|max:100',

            'unit_id' => 'nullable|exists:master_unit_treatment,id',
            'tipe_id' => 'nullable|exists:master_tipe_treatment,id',

            'waktu' => 'nullable|integer|min:0',
            'is_ppn' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'toko_mapping' => 'required|array|min:1',
            'toko_mapping.*.toko_id' => 'required|exists:master_toko,id',

            'toko_mapping.*.harga_terendah' => 'nullable|numeric|min:0',
            'toko_mapping.*.tarif' => 'nullable|numeric|min:0',
            'toko_mapping.*.biaya_modal' => 'nullable|numeric|min:0',

            'toko_mapping.*.tarif_dokter' => 'nullable|numeric|min:0',
            'toko_mapping.*.tarif_beautician' => 'nullable|numeric|min:0',

            'toko_mapping.*.presentase_tarif_dokter' => 'nullable|numeric|min:0|max:100',
            'toko_mapping.*.presentase_tarif_dokter_sp' => 'nullable|numeric|min:0|max:100',

            'toko_mapping.*.flat_tarif_dokter' => 'nullable|numeric|min:0',
            'toko_mapping.*.flat_tarif_dokter_sp' => 'nullable|numeric|min:0',

            'toko_mapping.*.insentif_use' => 'nullable|string|max:30',
            'toko_mapping.*.insentif_use_sp' => 'nullable|string|max:30',

            'toko_mapping.*.is_active' => 'nullable|boolean',
            'toko_mapping.*.sort_order' => 'nullable|integer|min:0|max:32767',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokoPayload = $this->normalizeTokoPayload($request);
        $tokoError = $this->validateTreatmentToko($tokoPayload);

        if ($tokoError) {
            return response()->json([
                'status' => false,
                'message' => $tokoError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $treatment->update([
                'kode_accurate' => $request->kode_accurate,
                'nama' => $request->nama,
                'kategori_sales' => $request->kategori_sales,

                'unit_id' => $request->unit_id,
                'tipe_id' => $request->tipe_id,

                'waktu' => $request->waktu ?? 0,
                'is_ppn' => $request->is_ppn ?? 0,
                'sort_order' => $request->sort_order ?? 0,

                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            $this->syncTreatmentToko($treatment->id, $tokoPayload, $actor);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data treatment berhasil diperbarui',
                'data' => $treatment->fresh()->load($this->treatmentRelations()),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $treatment = MasterTreatment::active()->find($id);

        if (!$treatment) {
            return response()->json([
                'status' => false,
                'message' => 'Data treatment tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $treatment->update([
                'is_delete' => 1,
                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            MasterTreatmentToko::where('treatment_id', $id)->update([
                'is_delete' => 1,
                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data treatment berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function treatmentRelations(): array
    {
        return [
            'unit',
            'tipe',
            'hargaToko' => function ($q) {
                $q->active()
                    ->with('toko')
                    ->orderBy('sort_order');
            },
        ];
    }

    private function normalizeRequestField(Request $request): void
    {
        if (!$request->has('nama') && $request->has('nama_treatment')) {
            $request->merge([
                'nama' => $request->nama_treatment,
            ]);
        }

        if (!$request->has('unit_id') && $request->has('unit_treatment_id')) {
            $request->merge([
                'unit_id' => $request->unit_treatment_id,
            ]);
        }

        if (!$request->has('tipe_id') && $request->has('tipe_treatment_id')) {
            $request->merge([
                'tipe_id' => $request->tipe_treatment_id,
            ]);
        }

        if (!$request->has('toko_mapping') && $request->has('toko')) {
            $request->merge([
                'toko_mapping' => $request->toko,
            ]);
        }
    }

    private function normalizeTokoPayload(Request $request): array
    {
        $rows = $request->input('toko_mapping', []);

        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function ($item) {
                return [
                    'toko_id' => $item['toko_id'] ?? null,

                    'harga_terendah' => $item['harga_terendah'] ?? 0,
                    'tarif' => $item['tarif'] ?? 0,
                    'biaya_modal' => $item['biaya_modal'] ?? 0,

                    'tarif_dokter' => $item['tarif_dokter'] ?? 0,
                    'tarif_beautician' => $item['tarif_beautician'] ?? 0,

                    'presentase_tarif_dokter' => $item['presentase_tarif_dokter'] ?? 0,
                    'presentase_tarif_dokter_sp' => $item['presentase_tarif_dokter_sp'] ?? 0,

                    'flat_tarif_dokter' => $item['flat_tarif_dokter'] ?? 0,
                    'flat_tarif_dokter_sp' => $item['flat_tarif_dokter_sp'] ?? 0,

                    'insentif_use' => $item['insentif_use'] ?? null,
                    'insentif_use_sp' => $item['insentif_use_sp'] ?? null,

                    'is_active' => $item['is_active'] ?? 1,
                    'sort_order' => $item['sort_order'] ?? 0,
                ];
            })
            ->filter(function ($item) {
                return !empty($item['toko_id']);
            })
            ->values()
            ->toArray();
    }

    private function validateTreatmentToko(array $items): ?string
    {
        if (!count($items)) {
            return 'Minimal harus ada 1 konfigurasi cabang';
        }

        $tokoIds = collect($items)
            ->pluck('toko_id')
            ->filter()
            ->values();

        if ($tokoIds->count() !== count($items)) {
            return 'Semua konfigurasi cabang wajib memilih toko';
        }

        if ($tokoIds->unique()->count() !== $tokoIds->count()) {
            return 'Toko pada konfigurasi treatment tidak boleh duplikat';
        }

        foreach ($items as $item) {
            $numericFields = [
                'harga_terendah' => 'Harga terendah',
                'tarif' => 'Tarif',
                'biaya_modal' => 'Biaya modal',
                'tarif_dokter' => 'Tarif dokter',
                'tarif_beautician' => 'Tarif beautician',
                'presentase_tarif_dokter' => 'Persentase tarif dokter',
                'presentase_tarif_dokter_sp' => 'Persentase tarif dokter SP',
                'flat_tarif_dokter' => 'Flat tarif dokter',
                'flat_tarif_dokter_sp' => 'Flat tarif dokter SP',
            ];

            foreach ($numericFields as $field => $label) {
                if ((float) ($item[$field] ?? 0) < 0) {
                    return "{$label} tidak boleh kurang dari 0";
                }
            }

            if ((float) ($item['presentase_tarif_dokter'] ?? 0) > 100) {
                return 'Persentase tarif dokter tidak boleh lebih dari 100';
            }

            if ((float) ($item['presentase_tarif_dokter_sp'] ?? 0) > 100) {
                return 'Persentase tarif dokter SP tidak boleh lebih dari 100';
            }
        }

        return null;
    }

    private function syncTreatmentToko($treatmentId, array $items, string $actor): void
    {
        MasterTreatmentToko::where('treatment_id', $treatmentId)->update([
            'is_delete' => 1,
            'updated_by' => $actor,
            'updated_at' => now(),
        ]);

        foreach ($items as $item) {
            $treatmentToko = MasterTreatmentToko::where('treatment_id', $treatmentId)
                ->where('toko_id', $item['toko_id'])
                ->first();

            $payload = [
                'treatment_id' => $treatmentId,
                'toko_id' => $item['toko_id'],

                'harga_terendah' => $item['harga_terendah'] ?? 0,
                'tarif' => $item['tarif'] ?? 0,
                'biaya_modal' => $item['biaya_modal'] ?? 0,

                'tarif_dokter' => $item['tarif_dokter'] ?? 0,
                'tarif_beautician' => $item['tarif_beautician'] ?? 0,

                'presentase_tarif_dokter' => $item['presentase_tarif_dokter'] ?? 0,
                'presentase_tarif_dokter_sp' => $item['presentase_tarif_dokter_sp'] ?? 0,

                'flat_tarif_dokter' => $item['flat_tarif_dokter'] ?? 0,
                'flat_tarif_dokter_sp' => $item['flat_tarif_dokter_sp'] ?? 0,

                'insentif_use' => $item['insentif_use'] ?? null,
                'insentif_use_sp' => $item['insentif_use_sp'] ?? null,

                'is_active' => $item['is_active'] ?? 1,
                'is_delete' => 0,
                'sort_order' => $item['sort_order'] ?? 0,
            ];

            if ($treatmentToko) {
                $treatmentToko->update(array_merge($payload, [
                    'updated_by' => $actor,
                    'updated_at' => now(),
                ]));

                continue;
            }

            MasterTreatmentToko::create(array_merge($payload, [
                'created_by' => $actor,
                'created_at' => now(),
            ]));
        }
    }
}