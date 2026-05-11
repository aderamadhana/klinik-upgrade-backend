<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterSuplier;
use App\Models\Master\MasterSupplierToko;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterSupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $tokoId = $request->get('toko_id');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterSuplier::query()
            ->active()
            ->with(['tokoAktif.toko'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('kontak_person', 'like', "%{$search}%")
                        ->orWhere('no_telp', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('alamat', 'like', "%{$search}%")
                        ->orWhere('kota', 'like', "%{$search}%")
                        ->orWhereHas('tokoAktif.toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        });
                });
            })
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->whereHas('tokoAktif', function ($supplierToko) use ($tokoId) {
                    $supplierToko->active()
                        ->where('toko_id', $tokoId);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data supplier berhasil diambil',
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
            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_supplier', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    }),
            ],
            'nama' => 'required|string|max:200',
            'kontak_person' => 'nullable|string|max:150',
            'no_telp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'alamat' => 'nullable|string',
            'kota' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'toko' => 'nullable|array',
            'toko.*.toko_id' => 'required_with:toko|exists:master_toko,id',
            'toko.*.is_default' => 'nullable|boolean',

            'penempatan' => 'nullable|array',
            'penempatan.*.toko_id' => 'required_with:penempatan|exists:master_toko,id',
            'penempatan.*.is_default' => 'nullable|boolean',

            'toko_ids' => 'nullable|array',
            'toko_ids.*' => 'exists:master_toko,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokoError = $this->validateTokoPayload($this->getRawTokoRows($request));

        if ($tokoError) {
            return response()->json([
                'status' => false,
                'message' => $tokoError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $supplier = MasterSuplier::create([
                'kode' => $request->kode,
                'nama' => $request->nama,
                'kontak_person' => $request->kontak_person,
                'no_telp' => $request->no_telp,
                'email' => $request->email,
                'alamat' => $request->alamat,
                'kota' => $request->kota,
                'sort_order' => $request->sort_order ?? 0,
                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);

            $this->syncSupplierToko($supplier->id, $this->normalizeTokoPayload($request));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data supplier berhasil disimpan',
                'data' => $supplier->fresh()->load(['tokoAktif.toko']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data supplier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterSuplier::query()
            ->active()
            ->with(['tokoAktif.toko'])
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data supplier tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail supplier berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $supplier = MasterSuplier::active()->find($id);

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Data supplier tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_supplier', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    })
                    ->ignore($id),
            ],
            'nama' => 'required|string|max:200',
            'kontak_person' => 'nullable|string|max:150',
            'no_telp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'alamat' => 'nullable|string',
            'kota' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'toko' => 'nullable|array',
            'toko.*.toko_id' => 'required_with:toko|exists:master_toko,id',
            'toko.*.is_default' => 'nullable|boolean',

            'penempatan' => 'nullable|array',
            'penempatan.*.toko_id' => 'required_with:penempatan|exists:master_toko,id',
            'penempatan.*.is_default' => 'nullable|boolean',

            'toko_ids' => 'nullable|array',
            'toko_ids.*' => 'exists:master_toko,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokoError = $this->validateTokoPayload($this->getRawTokoRows($request));

        if ($tokoError) {
            return response()->json([
                'status' => false,
                'message' => $tokoError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $supplier->update([
                'kode' => $request->kode,
                'nama' => $request->nama,
                'kontak_person' => $request->kontak_person,
                'no_telp' => $request->no_telp,
                'email' => $request->email,
                'alamat' => $request->alamat,
                'kota' => $request->kota,
                'sort_order' => $request->sort_order ?? 0,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            if ($this->hasTokoPayload($request)) {
                $this->syncSupplierToko($supplier->id, $this->normalizeTokoPayload($request));
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data supplier berhasil diperbarui',
                'data' => $supplier->fresh()->load(['tokoAktif.toko']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data supplier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $supplier = MasterSuplier::active()->find($id);

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Data supplier tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $supplier->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            MasterSupplierToko::where('supplier_id', $id)->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data supplier berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data supplier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateTokoPayload(array $toko): ?string
    {
        if (!count($toko)) {
            return null;
        }

        $tokoIds = collect($toko)
            ->pluck('toko_id')
            ->filter()
            ->values();

        if ($tokoIds->count() !== count($toko)) {
            return 'Semua data toko harus memilih toko';
        }

        if ($tokoIds->unique()->count() !== $tokoIds->count()) {
            return 'Toko supplier tidak boleh duplikat';
        }

        return null;
    }

    private function syncSupplierToko($supplierId, array $toko): void
    {
        MasterSupplierToko::where('supplier_id', $supplierId)->update([
            'is_delete' => 1,
            'updated_by' => auth('api')->user()->username ?? 'system',
            'updated_at' => now(),
        ]);

        foreach ($toko as $item) {
            $tokoId = $item['toko_id'];
            $isDefault = (int) ($item['is_default'] ?? 0) === 1 ? 1 : 0;

            if ($isDefault === 1) {
                MasterSupplierToko::where('toko_id', $tokoId)
                    ->where('supplier_id', '!=', $supplierId)
                    ->active()
                    ->update([
                        'is_default' => 0,
                        'updated_by' => auth('api')->user()->username ?? 'system',
                        'updated_at' => now(),
                    ]);
            }

            $supplierToko = MasterSupplierToko::where('supplier_id', $supplierId)
                ->where('toko_id', $tokoId)
                ->first();

            if ($supplierToko) {
                $supplierToko->update([
                    'is_default' => $isDefault,
                    'is_delete' => 0,
                    'updated_by' => auth('api')->user()->username ?? 'system',
                    'updated_at' => now(),
                ]);

                continue;
            }

            MasterSupplierToko::create([
                'supplier_id' => $supplierId,
                'toko_id' => $tokoId,
                'is_default' => $isDefault,
                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);
        }
    }

    private function getRawTokoRows(Request $request): array
    {
        if ($request->has('toko') && is_array($request->input('toko'))) {
            return $request->input('toko');
        }

        if ($request->has('penempatan') && is_array($request->input('penempatan'))) {
            return $request->input('penempatan');
        }

        if ($request->has('toko_ids') && is_array($request->input('toko_ids'))) {
            return collect($request->input('toko_ids'))
                ->map(function ($tokoId) {
                    return [
                        'toko_id' => $tokoId,
                        'is_default' => 0,
                    ];
                })
                ->values()
                ->toArray();
        }

        return [];
    }

    private function normalizeTokoPayload(Request $request): array
    {
        return collect($this->getRawTokoRows($request))
            ->map(function ($item) {
                return [
                    'toko_id' => $item['toko_id'] ?? null,
                    'is_default' => (int) ($item['is_default'] ?? 0),
                ];
            })
            ->filter(function ($item) {
                return !empty($item['toko_id']);
            })
            ->values()
            ->toArray();
    }

    private function hasTokoPayload(Request $request): bool
    {
        return $request->has('toko')
            || $request->has('penempatan')
            || $request->has('toko_ids');
    }
}