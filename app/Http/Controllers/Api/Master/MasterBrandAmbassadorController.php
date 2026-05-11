<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterBrandAmbassador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterBrandAmbassadorController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $tokoId = $request->get('toko_id');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterBrandAmbassador::query()
            ->active()
            ->with(['toko'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('no_telp', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('instagram', 'like', "%{$search}%")
                        ->orWhere('alamat', 'like', "%{$search}%")
                        ->orWhere('catatan', 'like', "%{$search}%")
                        ->orWhereHas('toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        });
                });
            })
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->where('toko_id', $tokoId);
            })
            ->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data brand ambassador berhasil diambil',
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
            'toko_id' => 'required|exists:master_toko,id',

            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_brand_ambassador', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    }),
            ],

            'nama' => 'required|string|max:150',
            'no_telp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'instagram' => 'nullable|string|max:100',
            'alamat' => 'nullable|string',
            'catatan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data = MasterBrandAmbassador::create([
                'toko_id' => $request->toko_id,

                'kode' => $request->kode,
                'nama' => $request->nama,
                'no_telp' => $request->no_telp,
                'email' => $request->email,
                'instagram' => $request->instagram,
                'alamat' => $request->alamat,
                'catatan' => $request->catatan,

                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data brand ambassador berhasil disimpan',
                'data' => $data->fresh()->load(['toko']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data brand ambassador',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterBrandAmbassador::query()
            ->active()
            ->with(['toko'])
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data brand ambassador tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail brand ambassador berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $brandAmbassador = MasterBrandAmbassador::active()->find($id);

        if (!$brandAmbassador) {
            return response()->json([
                'status' => false,
                'message' => 'Data brand ambassador tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'toko_id' => 'required|exists:master_toko,id',

            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_brand_ambassador', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    })
                    ->ignore($id),
            ],

            'nama' => 'required|string|max:150',
            'no_telp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'instagram' => 'nullable|string|max:100',
            'alamat' => 'nullable|string',
            'catatan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $brandAmbassador->update([
                'toko_id' => $request->toko_id,

                'kode' => $request->kode,
                'nama' => $request->nama,
                'no_telp' => $request->no_telp,
                'email' => $request->email,
                'instagram' => $request->instagram,
                'alamat' => $request->alamat,
                'catatan' => $request->catatan,

                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data brand ambassador berhasil diperbarui',
                'data' => $brandAmbassador->fresh()->load(['toko']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data brand ambassador',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $brandAmbassador = MasterBrandAmbassador::active()->find($id);

        if (!$brandAmbassador) {
            return response()->json([
                'status' => false,
                'message' => 'Data brand ambassador tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $brandAmbassador->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data brand ambassador berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data brand ambassador',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}