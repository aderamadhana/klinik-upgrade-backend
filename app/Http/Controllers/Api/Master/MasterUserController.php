<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterUser;
use App\Models\Master\MasterUserPenempatan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MasterUserController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $query = MasterUser::query()
            ->active()
            ->with(['karyawan', 'penempatan.toko'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('username', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('display_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('role_name', 'like', "%{$search}%")
                        ->orWhereHas('karyawan', function ($karyawan) use ($search) {
                            $karyawan->where('nama', 'like', "%{$search}%")
                                ->orWhere('kode', 'like', "%{$search}%");
                        })
                        ->orWhereHas('penempatan.toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('nama');

        $perPage = (int) $request->get('per_page', 10);
        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data user berhasil diambil',
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
            'karyawan_id' => 'nullable|exists:master_karyawan,id',

            'role_id' => 'required|integer',
            'role_name' => 'required|string|max:100',

            'username' => 'required|string|max:100|unique:master_user,username',
            'password' => 'nullable|string|min:6',
            'email' => 'nullable|email|max:150|unique:master_user,email',

            'nama' => 'required|string|max:150',
            'display_name' => 'nullable|string|max:150',

            'is_active' => 'nullable|boolean',
            'must_change_password' => 'nullable|boolean',

            'penempatan' => 'nullable|array',
            'penempatan.*.toko_id' => 'required|exists:master_toko,id',
            'penempatan.*.is_primary' => 'nullable|boolean',
            'penempatan.*.is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $penempatanError = $this->validatePenempatan($request->penempatan ?? []);

        if ($penempatanError) {
            return response()->json([
                'status' => false,
                'message' => $penempatanError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = MasterUser::create([
                'karyawan_id' => $request->karyawan_id,

                'role_id' => $request->role_id,
                'role_name' => $request->role_name,

                'username' => $request->username,
                'password' => Hash::make($request->password ?: '123456'),
                'email' => $request->email,

                'nama' => $request->nama,
                'display_name' => $request->display_name ?: $request->nama,

                'is_active' => $request->is_active ?? 1,
                'is_delete' => 0,
                'must_change_password' => $request->password ? 0 : 1,

                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);

            $this->syncPenempatan($user->id, $request->penempatan ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data user berhasil disimpan',
                'data' => $user->fresh()->load(['karyawan', 'penempatan.toko']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterUser::query()
            ->active()
            ->with(['karyawan', 'penempatan.toko'])
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data user tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail user berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = MasterUser::active()->find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Data user tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'karyawan_id' => 'nullable|exists:master_karyawan,id',

            'role_id' => 'required|integer',
            'role_name' => 'required|string|max:100',

            'username' => 'required|string|max:100|unique:master_user,username,' . $id,
            'password' => 'nullable|string|min:6',
            'email' => 'nullable|email|max:150|unique:master_user,email,' . $id,

            'nama' => 'required|string|max:150',
            'display_name' => 'nullable|string|max:150',

            'is_active' => 'nullable|boolean',
            'must_change_password' => 'nullable|boolean',

            'penempatan' => 'nullable|array',
            'penempatan.*.toko_id' => 'required|exists:master_toko,id',
            'penempatan.*.is_primary' => 'nullable|boolean',
            'penempatan.*.is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $penempatanError = $this->validatePenempatan($request->penempatan ?? []);

        if ($penempatanError) {
            return response()->json([
                'status' => false,
                'message' => $penempatanError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payload = [
                'karyawan_id' => $request->karyawan_id,

                'role_id' => $request->role_id,
                'role_name' => $request->role_name,

                'username' => $request->username,
                'email' => $request->email,

                'nama' => $request->nama,
                'display_name' => $request->display_name ?: $request->nama,

                'is_active' => $request->is_active ?? 1,
                'must_change_password' => $request->must_change_password ?? $user->must_change_password,

                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ];

            if ($request->filled('password')) {
                $payload['password'] = Hash::make($request->password);
                $payload['must_change_password'] = 0;
            }

            $user->update($payload);

            $this->syncPenempatan($user->id, $request->penempatan ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data user berhasil diperbarui',
                'data' => $user->fresh()->load(['karyawan', 'penempatan.toko']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $user = MasterUser::active()->find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Data user tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $user->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            MasterUserPenempatan::where('user_id', $id)->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data user berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resetPassword($id)
    {
        $user = MasterUser::active()->find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Data user tidak ditemukan',
            ], 404);
        }

        $user->update([
            'password' => Hash::make('123456'),
            'must_change_password' => 1,
            'updated_by' => auth('api')->user()->username ?? 'system',
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password user berhasil direset ke default 123456',
        ]);
    }

    private function validatePenempatan(array $penempatan): ?string
    {
        if (!count($penempatan)) {
            return null;
        }

        $primaryCount = collect($penempatan)
            ->filter(fn ($item) => (int) ($item['is_primary'] ?? 0) === 1)
            ->count();

        if ($primaryCount !== 1) {
            return 'Harus ada tepat 1 penempatan utama';
        }

        $tokoIds = collect($penempatan)
            ->pluck('toko_id')
            ->filter()
            ->values();

        if ($tokoIds->count() !== count($penempatan)) {
            return 'Semua penempatan harus memilih toko';
        }

        if ($tokoIds->unique()->count() !== $tokoIds->count()) {
            return 'Toko pada penempatan tidak boleh duplikat';
        }

        return null;
    }

    private function syncPenempatan($userId, array $penempatan): void
    {
        MasterUserPenempatan::where('user_id', $userId)->update([
            'is_delete' => 1,
            'updated_by' => auth('api')->user()->username ?? 'system',
            'updated_at' => now(),
        ]);

        foreach ($penempatan as $index => $item) {
            MasterUserPenempatan::create([
                'user_id' => $userId,
                'toko_id' => $item['toko_id'],
                'is_primary' => $item['is_primary'] ?? ($index === 0 ? 1 : 0),
                'is_active' => $item['is_active'] ?? 1,
                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);
        }
    }
}