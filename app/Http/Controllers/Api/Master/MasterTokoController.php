<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterToko;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterTokoController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $jenisToko = $request->get('jenis_toko');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterToko::query()
            ->active()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('kode_toko', 'like', "%{$search}%")
                        ->orWhere('nama_toko', 'like', "%{$search}%")
                        ->orWhere('no_telepon', 'like', "%{$search}%")
                        ->orWhere('alamat', 'like', "%{$search}%")
                        ->orWhere('source_template', 'like', "%{$search}%");
                });
            })
            ->when($jenisToko !== null && $jenisToko !== '', function ($q) use ($jenisToko) {
                $q->where('jenis_toko', (int) $jenisToko);
            })
            ->orderBy('sort_order')
            ->orderBy('nama_toko');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data toko berhasil diambil',
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
                'max:20',
                Rule::unique('master_toko', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    }),
            ],

            'kode_toko' => [
                'required',
                'string',
                'max:10',
                Rule::unique('master_toko', 'kode_toko')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    }),
            ],

            'nama_toko' => 'required|string|max:100',
            'jenis_toko' => 'required|integer|in:1,2,3',
            'no_telepon' => 'nullable|string|max:30',
            'alamat' => 'nullable|string',
            'source_template' => 'nullable|string|max:255',
            'token_cdn' => 'nullable|string',
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
            $data = MasterToko::create([
                'kode' => $request->kode,
                'kode_toko' => $request->kode_toko,
                'nama_toko' => $request->nama_toko,
                'jenis_toko' => $request->jenis_toko,
                'no_telepon' => $request->no_telepon,
                'alamat' => $request->alamat,
                'source_template' => $request->source_template,
                'token_cdn' => $request->token_cdn,
                'sort_order' => $request->sort_order ?? 0,

                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data toko berhasil disimpan',
                'data' => $data->fresh(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data toko',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterToko::query()
            ->active()
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data toko tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail toko berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $toko = MasterToko::active()->find($id);

        if (!$toko) {
            return response()->json([
                'status' => false,
                'message' => 'Data toko tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'kode' => [
                'required',
                'string',
                'max:20',
                Rule::unique('master_toko', 'kode')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    })
                    ->ignore($id),
            ],

            'kode_toko' => [
                'required',
                'string',
                'max:10',
                Rule::unique('master_toko', 'kode_toko')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        });
                    })
                    ->ignore($id),
            ],

            'nama_toko' => 'required|string|max:100',
            'jenis_toko' => 'required|integer|in:1,2,3',
            'no_telepon' => 'nullable|string|max:30',
            'alamat' => 'nullable|string',
            'source_template' => 'nullable|string|max:255',
            'token_cdn' => 'nullable|string',
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
            $toko->update([
                'kode' => $request->kode,
                'kode_toko' => $request->kode_toko,
                'nama_toko' => $request->nama_toko,
                'jenis_toko' => $request->jenis_toko,
                'no_telepon' => $request->no_telepon,
                'alamat' => $request->alamat,
                'source_template' => $request->source_template,
                'token_cdn' => $request->token_cdn,
                'sort_order' => $request->sort_order ?? 0,

                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data toko berhasil diperbarui',
                'data' => $toko->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data toko',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $toko = MasterToko::active()->find($id);

        if (!$toko) {
            return response()->json([
                'status' => false,
                'message' => 'Data toko tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $toko->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data toko berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data toko',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function options(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $jenisToko = $request->get('jenis_toko');

        $query = MasterToko::query()
            ->active()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('kode_toko', 'like', "%{$search}%")
                        ->orWhere('nama_toko', 'like', "%{$search}%");
                });
            })
            ->when($jenisToko !== null && $jenisToko !== '', function ($q) use ($jenisToko) {
                $q->where('jenis_toko', (int) $jenisToko);
            })
            ->orderBy('sort_order')
            ->orderBy('nama_toko')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data opsi toko berhasil diambil',
            'data' => $query,
        ]);
    }
}