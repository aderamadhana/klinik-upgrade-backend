<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\master\MasterPerawatBahan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterPerawatBahanController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $isActive = $request->get('is_active');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterPerawatBahan::query()
            ->where('is_delete', 0)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('nama_bahan', 'like', "%{$search}%")
                        ->orWhere('kode_accurate_obat_bahan', 'like', "%{$search}%")
                        ->orWhere('satuan', 'like', "%{$search}%");
                });
            })
            ->when($isActive !== null && $isActive !== '', function ($q) use ($isActive) {
                $q->where('is_active', (int) $isActive);
            })
            ->orderBy('nama_bahan')
            ->orderBy('id');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data bahan perawat berhasil diambil',
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

        DB::beginTransaction();

        try {
            $data = MasterPerawatBahan::create($this->payload($request, true));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data bahan perawat berhasil disimpan',
                'data' => $data,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data bahan perawat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterPerawatBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data bahan perawat tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail bahan perawat berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $data = MasterPerawatBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data bahan perawat tidak ditemukan',
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

        DB::beginTransaction();

        try {
            $data->update($this->payload($request, false));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data bahan perawat berhasil diperbarui',
                'data' => $data->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data bahan perawat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $data = MasterPerawatBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data bahan perawat tidak ditemukan',
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
                'message' => 'Data bahan perawat berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data bahan perawat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function options(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        $data = MasterPerawatBahan::query()
            ->where('is_delete', 0)
            ->where('is_active', 1)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('nama_bahan', 'like', "%{$search}%")
                        ->orWhere('kode_accurate_obat_bahan', 'like', "%{$search}%");
                });
            })
            ->orderBy('nama_bahan')
            ->limit(100)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Opsi bahan perawat berhasil diambil',
            'data' => $data,
        ]);
    }

    private function rules($ignoreId = null): array
    {
        return [
            'nama_bahan' => [
                'required',
                'string',
                'max:150',
                Rule::unique('master_perawat_bahan', 'nama_bahan')
                    ->ignore($ignoreId)
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'kode_accurate_obat_bahan' => 'nullable|string|max:100',
            'satuan' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ];
    }

    private function payload(Request $request, bool $isCreate): array
    {
        $payload = [
            'nama_bahan' => trim((string) $request->nama_bahan),
            'kode_accurate_obat_bahan' => $request->kode_accurate_obat_bahan,
            'satuan' => $request->satuan,
            'is_active' => $request->has('is_active')
                ? (int) $request->boolean('is_active')
                : 1,
        ];

        if ($isCreate) {
            $payload['is_delete'] = 0;
            $payload['created_by'] = $this->actor();
            $payload['created_at'] = now();
        }

        $payload['updated_by'] = $this->actor();
        $payload['updated_at'] = now();

        return $payload;
    }

    private function actor(): string
    {
        return auth('api')->user()->username ?? 'system';
    }
}