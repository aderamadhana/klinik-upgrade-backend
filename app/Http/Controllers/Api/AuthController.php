<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterRole;
use App\Models\Master\MasterToko;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;

class AuthController extends Controller
{
    private const FULL_ACCESS_ROLE_IDS = [1, 7, 9];

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'Username wajib diisi',
            'password.required' => 'Password wajib diisi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $credentials = [
            'username'  => $request->username,
            'password'  => $request->password,
            'is_active' => 1,
            'is_delete' => 0,
        ];

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json([
                'status'  => false,
                'message' => 'Username atau password salah',
            ], 401);
        }

        $user = Auth::guard('api')->user();

        $user->update([
            'last_login_at' => now(),
        ]);

        $user->refresh();

        return $this->respondWithToken($token, $user, 'Login berhasil');
    }

    public function refresh(Request $request)
    {
        try {
            if (! JWTAuth::getToken()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token tidak ditemukan',
                ], 401);
            }

            $newToken = JWTAuth::parseToken()->refresh();

            $user = Auth::guard('api')
                ->setToken($newToken)
                ->user();

            if (! $user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User tidak ditemukan',
                ], 401);
            }

            if ((int) $user->is_active !== 1 || (int) $user->is_delete !== 0) {
                try {
                    JWTAuth::setToken($newToken)->invalidate();
                } catch (\Throwable $e) {
                    // Abaikan. Token akan tetap dianggap tidak valid dari sisi response.
                }

                return response()->json([
                    'status'  => false,
                    'message' => 'Akun sudah tidak aktif. Silakan login ulang.',
                ], 401);
            }

            return $this->respondWithToken($newToken, $user, 'Token berhasil diperbarui');
        } catch (TokenExpiredException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Session sudah habis. Silakan login ulang.',
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Token tidak valid. Silakan login ulang.',
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Token tidak ditemukan atau tidak bisa diperbarui.',
            ], 401);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Terjadi kesalahan saat memperbarui token.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function me()
    {
        $user = Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'status'  => false,
                'message' => 'User tidak terautentikasi',
            ], 401);
        }

        $user->load([
            'karyawan',
            'penempatan' => function ($query) {
                $query->where('is_delete', 0)
                    ->where('is_active', 1)
                    ->with('toko');
            },
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Data user berhasil diambil',
            'data'    => [
                'user'   => $this->formatUser($user),
                'access' => $this->buildAccessPayload($user),
            ],
        ]);
    }

    public function logout()
    {
        try {
            Auth::guard('api')->logout();

            return response()->json([
                'status'  => true,
                'message' => 'Logout berhasil',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Logout gagal atau token sudah tidak valid',
            ], 401);
        }
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => 'Password lama wajib diisi',
            'new_password.required'     => 'Password baru wajib diisi',
            'new_password.min'          => 'Password baru minimal 6 karakter',
            'new_password.confirmed'    => 'Konfirmasi password baru tidak sama',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'status'  => false,
                'message' => 'User tidak terautentikasi',
            ], 401);
        }

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Password lama tidak sesuai',
            ], 422);
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Password baru tidak boleh sama dengan password lama',
            ], 422);
        }

        $user->update([
            'password'             => Hash::make($request->new_password),
            'must_change_password' => 0,
            'updated_by'           => $user->username ?? 'system',
            'updated_at'           => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Password berhasil diubah',
        ]);
    }

    protected function respondWithToken($token, $user, $message = 'OK')
    {
        $expiresIn = Auth::guard('api')->factory()->getTTL() * 60;

        $user->load([
            'karyawan',
            'penempatan' => function ($query) {
                $query->where('is_delete', 0)
                    ->where('is_active', 1)
                    ->with('toko');
            },
        ]);

        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => [
                'access_token' => $token,
                'token_type'   => 'bearer',
                'expires_in'   => $expiresIn,
                'expires_at'   => now()->addSeconds($expiresIn)->timestamp,

                'must_change_password' => (int) ($user->must_change_password ?? 0),

                'user'   => $this->formatUser($user),
                'access' => $this->buildAccessPayload($user),
            ],
        ]);
    }

    protected function formatUser($user)
    {
        $isFullAccess = $this->isFullAccess($user);

        return [
            'id'                   => $user->id,
            'karyawan_id'          => $user->karyawan_id,
            'role_id'              => $user->role_id,
            'role_name'            => $user->role_name,
            'username'             => $user->username,
            'email'                => $user->email,
            'nama'                 => $user->nama,
            'display_name'         => $user->display_name,
            'is_active'            => (int) $user->is_active,
            'must_change_password' => (int) ($user->must_change_password ?? 0),
            'last_login_at'        => $user->last_login_at,
            'is_full_access'       => $isFullAccess,
            'karyawan'             => $user->karyawan,
        ];
    }

    protected function buildAccessPayload($user)
    {
        $isFullAccess = $this->isFullAccess($user);

        $roleItems = $this->getRoleItems($user, $isFullAccess);
        $penempatanItems = $this->getPenempatanItems($user, $isFullAccess);

        $primaryPenempatan = $penempatanItems->firstWhere('is_primary', 1)
            ?? $penempatanItems->first();

        return [
            'is_full_access' => $isFullAccess,
            'roles'          => $roleItems->values(),
            'penempatan'     => $penempatanItems->values(),
            'primary_toko'   => $primaryPenempatan,
        ];
    }

    protected function isFullAccess($user)
    {
        return in_array((int) $user->role_id, self::FULL_ACCESS_ROLE_IDS, true);
    }

    protected function getRoleItems($user, $isFullAccess)
    {
        if ($isFullAccess) {
            return MasterRole::query()
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
                });
        }

        return collect([
            [
                'id'        => $user->role_id,
                'role_id'   => $user->role_id,
                'kode_role' => null,
                'role_name' => $user->role_name,
                'nama_role' => $user->role_name,
            ],
        ]);
    }

    protected function getPenempatanItems($user, $isFullAccess)
    {
        if ($isFullAccess) {
            return MasterToko::query()
                ->where('is_delete', 0)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($toko, $index) {
                    return [
                        'id'         => null,
                        'toko_id'    => $toko->id,
                        'kode'       => $toko->kode,
                        'kode_toko'  => $toko->kode_toko,
                        'nama_toko'  => $toko->nama_toko,
                        'jenis_toko' => $toko->jenis_toko,
                        'alamat'     => $toko->alamat,
                        'is_primary' => $index === 0 ? 1 : 0,
                        'is_active'  => 1,
                        'source'     => 'all_toko',
                    ];
                });
        }

        return $user->penempatan
            ->filter(function ($item) {
                return (int) $item->is_delete === 0
                    && (int) $item->is_active === 1
                    && $item->toko;
            })
            ->map(function ($item) {
                return [
                    'id'         => $item->id,
                    'toko_id'    => $item->toko_id,
                    'kode'       => $item->toko->kode ?? null,
                    'kode_toko'  => $item->toko->kode_toko ?? null,
                    'nama_toko'  => $item->toko->nama_toko ?? null,
                    'jenis_toko' => $item->toko->jenis_toko ?? null,
                    'alamat'     => $item->toko->alamat ?? null,
                    'is_primary' => (int) $item->is_primary,
                    'is_active'  => (int) $item->is_active,
                    'source'     => 'master_user_penempatan',
                ];
            });
    }
}