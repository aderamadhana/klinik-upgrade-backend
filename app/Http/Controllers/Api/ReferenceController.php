<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Master\MasterRole;
use App\Models\Master\MasterJabatan;
use App\Models\Master\MasterToko;
use App\Models\Master\MasterKaryawan;


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
            ->select('id', 'kode', 'kode_toko', 'nama_toko', 'jenis_toko', 'no_telepon', 'alamat')
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

    public function karyawanCode(Request $request)
    {
        $request->validate([
            'jabatan_id' => 'required|integer',
            'toko_id' => 'required|integer',
        ]);

        $jabatan = MasterJabatan::query()
            ->where('id', $request->jabatan_id)
            ->first();

        $toko = MasterToko::query()
            ->where('id', $request->toko_id)
            ->first();

        if (!$jabatan || !$toko) {
            return response()->json([
                'message' => 'Jabatan atau toko tidak ditemukan',
            ], 404);
        }

        $kodeToko = $toko->kode
            ?? $toko->kode_toko
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $toko->nama ?? 'TKO'), 0, 3));

        $kodeJabatan = $jabatan->kode
            ?? $jabatan->kode_jabatan
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $jabatan->nama ?? 'KRY'), 0, 3));

        $kodeToko = strtoupper($kodeToko);
        $kodeJabatan = strtoupper($kodeJabatan);

        $prefix = 'KRY-' . $kodeToko . '-' . $kodeJabatan . '-';

        $lastKode = MasterKaryawan::query()
            ->where('kode', 'like', $prefix . '%')
            ->orderByRaw('CAST(RIGHT(kode, 4) AS UNSIGNED) DESC')
            ->value('kode');

        $lastNumber = 0;

        if ($lastKode) {
            $lastNumber = (int) substr($lastKode, -4);
        }

        $nextNumber = $lastNumber + 1;

        $kode = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        return response()->json([
            'data' => [
                'kode' => $kode,
            ],
        ]);
    }
}