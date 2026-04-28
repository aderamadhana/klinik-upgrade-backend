<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterRole;
use App\Models\Master\MasterJabatan;
use App\Models\Master\MasterToko;

class ReferenceController extends Controller
{
    public function roles()
    {
        $data = MasterRole::active()
            ->select('id', 'kode_role', 'nama_role')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data role berhasil diambil',
            'data' => $data,
        ]);
    }

    public function jabatan()
    {
        $data = MasterJabatan::active()
            ->select('id', 'kode_jabatan', 'nama_jabatan')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data jabatan berhasil diambil',
            'data' => $data,
        ]);
    }

    public function toko()
    {
        $data = MasterToko::active()
            ->select('id', 'kode', 'kode_toko', 'nama', 'jenis_toko', 'no_telepon', 'alamat')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data toko berhasil diambil',
            'data' => $data,
        ]);
    }

    public function initialMaster()
    {
        return response()->json([
            'status' => true,
            'message' => 'Data referensi awal berhasil diambil',
            'data' => [
                'roles' => MasterRole::active()
                    ->select('id', 'kode_role', 'nama_role')
                    ->orderBy('sort_order')
                    ->get(),

                'jabatan' => MasterJabatan::active()
                    ->select('id', 'kode_jabatan', 'nama_jabatan')
                    ->orderBy('sort_order')
                    ->get(),

                'toko' => MasterToko::active()
                    ->select('id', 'kode', 'kode_toko', 'nama', 'jenis_toko', 'no_telepon', 'alamat')
                    ->orderBy('sort_order')
                    ->get(),
            ],
        ]);
    }
}