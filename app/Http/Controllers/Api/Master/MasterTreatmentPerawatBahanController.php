<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterTreatmentPerawatBahan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterTreatmentPerawatBahanController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $treatmentId = $request->get('treatment_id');
        $perawatBahanId = $request->get('perawat_bahan_id');
        $isActive = $request->get('is_active');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterTreatmentPerawatBahan::query()
            ->where('is_delete', 0)
            ->with(['treatment', 'bahan'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->whereHas('treatment', function ($treatment) use ($search) {
                        $treatment->where('nama', 'like', "%{$search}%")
                            ->orWhere('kode_accurate', 'like', "%{$search}%")
                            ->orWhere('kode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('bahan', function ($bahan) use ($search) {
                        $bahan->where('nama_bahan', 'like', "%{$search}%")
                            ->orWhere('kode_accurate_obat_bahan', 'like', "%{$search}%")
                            ->orWhere('satuan', 'like', "%{$search}%");
                    })
                    ->orWhere('satuan', 'like', "%{$search}%");
                });
            })
            ->when($treatmentId, function ($q) use ($treatmentId) {
                $q->where('treatment_id', $treatmentId);
            })
            ->when($perawatBahanId, function ($q) use ($perawatBahanId) {
                $q->where('perawat_bahan_id', $perawatBahanId);
            })
            ->when($isActive !== null && $isActive !== '', function ($q) use ($isActive) {
                $q->where('is_active', (int) $isActive);
            })
            ->orderBy('treatment_id')
            ->orderBy('id');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data bahan per treatment berhasil diambil',
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
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $duplicate = $this->findDuplicate(
            $request->treatment_id,
            $request->perawat_bahan_id
        );

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'Bahan ini sudah terdaftar pada treatment tersebut',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data = MasterTreatmentPerawatBahan::create($this->payload($request, true));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data bahan per treatment berhasil disimpan',
                'data' => $data->fresh()->load(['treatment', 'bahan']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data bahan per treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterTreatmentPerawatBahan::query()
            ->where('is_delete', 0)
            ->with(['treatment', 'bahan'])
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data bahan per treatment tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail bahan per treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $data = MasterTreatmentPerawatBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data bahan per treatment tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->rules($id));

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $duplicate = $this->findDuplicate(
            $request->treatment_id,
            $request->perawat_bahan_id,
            $id
        );

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'Bahan ini sudah terdaftar pada treatment tersebut',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data->update($this->payload($request, false));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data bahan per treatment berhasil diperbarui',
                'data' => $data->fresh()->load(['treatment', 'bahan']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data bahan per treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $data = MasterTreatmentPerawatBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data bahan per treatment tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $data->update([
                'is_active' => 0,
                'is_delete' => 1,
                'updated_by' => $this->actor(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data bahan per treatment berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data bahan per treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function options(Request $request)
    {
        $treatmentId = $request->get('treatment_id');

        $data = MasterTreatmentPerawatBahan::query()
            ->active()
            ->with(['treatment', 'bahan'])
            ->when($treatmentId, function ($q) use ($treatmentId) {
                $q->where('treatment_id', $treatmentId);
            })
            ->orderBy('id')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'treatment_id' => $item->treatment_id,
                    'perawat_bahan_id' => $item->perawat_bahan_id,
                    'nama_treatment' => $item->treatment->nama ?? null,
                    'nama_bahan' => $item->bahan->nama_bahan ?? null,
                    'kode_accurate_obat_bahan' => $item->bahan->kode_accurate_obat_bahan ?? null,
                    'jumlah_default' => $item->jumlah_default,
                    'satuan' => $item->satuan ?? $item->bahan->satuan ?? null,
                    'label' => trim(($item->bahan->nama_bahan ?? '-') . ' - ' . ($item->jumlah_default ?? 0) . ' ' . ($item->satuan ?? $item->bahan->satuan ?? '')),
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Opsi bahan per treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    private function rules($ignoreId = null): array
    {
        return [
            'treatment_id' => [
                'required',
                'integer',
                Rule::exists('master_treatment', 'id')->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                }),
            ],
            'perawat_bahan_id' => [
                'required',
                'integer',
                Rule::exists('master_perawat_bahan', 'id')->where(function ($q) {
                    $q->where('is_delete', 0)
                        ->where('is_active', 1);
                }),
            ],
            'jumlah_default' => 'required|numeric|min:0.0001',
            'satuan' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ];
    }

    private function payload(Request $request, bool $isCreate): array
    {
        $satuan = $request->satuan;

        if (!$satuan && $request->perawat_bahan_id) {
            $satuan = DB::table('master_perawat_bahan')
                ->where('id', $request->perawat_bahan_id)
                ->value('satuan');
        }

        $payload = [
            'treatment_id' => (int) $request->treatment_id,
            'perawat_bahan_id' => (int) $request->perawat_bahan_id,
            'jumlah_default' => $request->jumlah_default,
            'satuan' => $satuan,
            'is_active' => $request->has('is_active')
                ? (int) $request->boolean('is_active')
                : 1,
            'updated_by' => $this->actor(),
            'updated_at' => now(),
        ];

        if ($isCreate) {
            $payload['is_delete'] = 0;
            $payload['created_by'] = $this->actor();
            $payload['created_at'] = now();
        }

        return $payload;
    }

    private function findDuplicate($treatmentId, $perawatBahanId, $ignoreId = null)
    {
        return MasterTreatmentPerawatBahan::query()
            ->where('is_delete', 0)
            ->where('treatment_id', $treatmentId)
            ->where('perawat_bahan_id', $perawatBahanId)
            ->when($ignoreId, function ($q) use ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            })
            ->first();
    }

    private function actor(): string
    {
        return auth('api')->user()->username ?? 'system';
    }
}