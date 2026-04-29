<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterUser;
use App\Models\Master\MasterRole;
use App\Models\Master\MasterToko;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $credentials = [
            'username'  => $request->username,
            'password'  => $request->password,
            'is_active' => 1,
            'is_delete' => 0,
        ];

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json([
                'status'  => false,
                'message' => 'Username atau password salah',
            ], 401);
        }

        $user = auth('api')->user();

        $user->load([
            'karyawan',
            'penempatan' => function ($query) {
                $query->where('is_delete', 0)
                    ->where('is_active', 1)
                    ->with('toko');
            },
        ]);

        $user->update([
            'last_login_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Role full access
        |--------------------------------------------------------------------------
        | 
        | 1 = ADMINISTRATOR
        | 7 = Superuser
        | 9 = IT
        */
        $fullAccessRoleIds = [1, 7, 9];

        $isFullAccess = in_array((int) $user->role_id, $fullAccessRoleIds, true);

        /*
        |--------------------------------------------------------------------------
        | Role Items
        |--------------------------------------------------------------------------
        */
        if ($isFullAccess) {
            $roleItems = MasterRole::query()
                ->where('is_delete', 0)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($role) {
                    return [
                        'id'        => $role->id,
                        'role_id'   => $role->id,
                        'kode_role' => $role->kode_role,
                        'role_name' => $role->nama_role,
                        'nama_role' => $role->nama_role,
                    ];
                })
                ->values();
        } else {
            $roleItems = collect([
                [
                    'id'        => $user->role_id,
                    'role_id'   => $user->role_id,
                    'kode_role' => null,
                    'role_name' => $user->role_name,
                    'nama_role' => $user->role_name,
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Penempatan / Cabang Items
        |--------------------------------------------------------------------------
        */
        if ($isFullAccess) {
            $penempatanItems = MasterToko::query()
                ->where('is_delete', 0)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($toko, $index) {
                    return [
                        'id'             => null,
                        'toko_id'        => $toko->id,
                        'kode'           => $toko->kode,
                        'kode_toko'      => $toko->kode_toko,
                        'nama_toko'      => $toko->nama_toko,
                        'jenis_toko'     => $toko->jenis_toko,
                        'alamat'         => $toko->alamat,
                        'is_primary'     => $index === 0 ? 1 : 0,
                        'is_active'      => 1,
                        'source'         => 'all_toko',
                    ];
                })
                ->values();
        } else {
            $penempatanItems = $user->penempatan
                ->filter(function ($item) {
                    return
                        (int) $item->is_delete === 0 &&
                        (int) $item->is_active === 1 &&
                        $item->toko;
                })
                ->map(function ($item) {
                    return [
                        'id'             => $item->id,
                        'toko_id'        => $item->toko_id,
                        'kode'           => $item->toko->kode ?? null,
                        'kode_toko'      => $item->toko->kode_toko ?? null,
                        'nama_toko'      => $item->toko->nama_toko ?? null,
                        'jenis_toko'     => $item->toko->jenis_toko ?? null,
                        'alamat'         => $item->toko->alamat ?? null,
                        'is_primary'     => (int) $item->is_primary,
                        'is_active'      => (int) $item->is_active,
                        'source'         => 'master_user_penempatan',
                    ];
                })
                ->values();
        }

        $primaryPenempatan = $penempatanItems
            ->firstWhere('is_primary', 1) ?? $penempatanItems->first();

        return response()->json([
            'status'  => true,
            'message' => 'Login berhasil',
            'data'    => [
                'access_token' => $token,
                'token_type'   => 'bearer',
                'expires_in'   => auth('api')->factory()->getTTL() * 60,

                'must_change_password' => (int) $user->must_change_password,

                'user' => [
                    'id'                   => $user->id,
                    'karyawan_id'          => $user->karyawan_id,
                    'role_id'              => $user->role_id,
                    'role_name'            => $user->role_name,
                    'username'             => $user->username,
                    'email'                => $user->email,
                    'nama'                 => $user->nama,
                    'display_name'         => $user->display_name,
                    'is_active'            => (int) $user->is_active,
                    'must_change_password' => (int) $user->must_change_password,
                    'last_login_at'        => $user->last_login_at,
                    'is_full_access'       => $isFullAccess,
                    'karyawan'             => $user->karyawan,
                ],

                'access' => [
                    'is_full_access' => $isFullAccess,
                    'roles'          => $roleItems,
                    'penempatan'     => $penempatanItems,
                    'primary_toko'   => $primaryPenempatan,
                ],
            ],
        ]);
    }

    public function me()
    {
        $user = Auth::guard('api')->user();

        return response()->json([
            'status' => true,
            'message' => 'Data user berhasil diambil',
            'data' => $user->load(['karyawan', 'penempatan.toko']),
        ]);
    }

    public function logout()
    {
        Auth::guard('api')->logout();

        return response()->json([
            'status' => true,
            'message' => 'Logout berhasil',
        ]);
    }

    public function refresh()
    {
        return response()->json([
            'status' => true,
            'message' => 'Token berhasil diperbarui',
            'data' => [
                'access_token' => Auth::guard('api')->refresh(),
                'token_type' => 'bearer',
                'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => 'Password lama wajib diisi',
            'new_password.required' => 'Password baru wajib diisi',
            'new_password.min' => 'Password baru minimal 6 karakter',
            'new_password.confirmed' => 'Konfirmasi password baru tidak sama',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User tidak terautentikasi',
            ], 401);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Password lama tidak sesuai',
            ], 422);
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Password baru tidak boleh sama dengan password lama',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => 0,
            'updated_by' => $user->username ?? 'system',
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password berhasil diubah',
        ]);
    }
}