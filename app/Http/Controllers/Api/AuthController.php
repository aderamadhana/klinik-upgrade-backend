<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterUser;
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
            'username' => $request->username,
            'password' => $request->password,
            'is_active' => 1,
            'is_delete' => 0,
        ];

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json([
                'status' => false,
                'message' => 'Username atau password salah',
            ], 401);
        }

        $user = auth('api')->user();

        $user->update([
            'last_login_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Login berhasil',
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'must_change_password' => (int) $user->must_change_password,
                'user' => $user,
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
}