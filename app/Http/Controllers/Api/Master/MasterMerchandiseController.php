<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterMerchandise;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterMerchandiseController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $jenisReward = $request->get('jenis_reward');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterMerchandise::query()
            ->active()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('jenis_reward', 'like', "%{$search}%")
                        ->orWhere('deskripsi', 'like', "%{$search}%");
                });
            })
            ->when($jenisReward, function ($q) use ($jenisReward) {
                $q->where('jenis_reward', $jenisReward);
            })
            ->orderBy('sort_order')
            ->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data merchandise berhasil diambil',
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
                Rule::unique('master_merchandise', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    }),
            ],

            'nama' => 'required|string|max:150',
            'jenis_reward' => 'required|string|max:50',

            'nilai_diskon_persen' => 'nullable|numeric|min:0|max:100',
            'nilai_diskon_nominal' => 'nullable|numeric|min:0|max:9999999999999999.99',

            'harga_poin' => 'required|integer|min:0',
            'stok' => 'required|integer|min:0',

            'deskripsi' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0|max:32767',
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
            $data = MasterMerchandise::create([
                'kode' => $request->kode,
                'nama' => $request->nama,
                'jenis_reward' => $request->jenis_reward,

                'nilai_diskon_persen' => $request->nilai_diskon_persen,
                'nilai_diskon_nominal' => $request->nilai_diskon_nominal,

                'harga_poin' => $request->harga_poin,
                'stok' => $request->stok,

                'deskripsi' => $request->deskripsi,
                'sort_order' => $request->sort_order ?? 0,

                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data merchandise berhasil disimpan',
                'data' => $data->fresh(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data merchandise',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterMerchandise::query()
            ->active()
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data merchandise tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail merchandise berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $merchandise = MasterMerchandise::active()->find($id);

        if (!$merchandise) {
            return response()->json([
                'status' => false,
                'message' => 'Data merchandise tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_merchandise', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    })
                    ->ignore($id),
            ],

            'nama' => 'required|string|max:150',
            'jenis_reward' => 'required|string|max:50',

            'nilai_diskon_persen' => 'nullable|numeric|min:0|max:100',
            'nilai_diskon_nominal' => 'nullable|numeric|min:0|max:9999999999999999.99',

            'harga_poin' => 'required|integer|min:0',
            'stok' => 'required|integer|min:0',

            'deskripsi' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0|max:32767',
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
            $merchandise->update([
                'kode' => $request->kode,
                'nama' => $request->nama,
                'jenis_reward' => $request->jenis_reward,

                'nilai_diskon_persen' => $request->nilai_diskon_persen,
                'nilai_diskon_nominal' => $request->nilai_diskon_nominal,

                'harga_poin' => $request->harga_poin,
                'stok' => $request->stok,

                'deskripsi' => $request->deskripsi,
                'sort_order' => $request->sort_order ?? 0,

                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data merchandise berhasil diperbarui',
                'data' => $merchandise->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data merchandise',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $merchandise = MasterMerchandise::active()->find($id);

        if (!$merchandise) {
            return response()->json([
                'status' => false,
                'message' => 'Data merchandise tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $merchandise->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data merchandise berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data merchandise',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}