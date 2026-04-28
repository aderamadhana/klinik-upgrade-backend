<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterKaryawanPenempatan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterKaryawanController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $data = MasterKaryawan::query()
            ->active()
            ->with(['jabatan', 'penempatan.toko'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('no_telp', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%");
                });
            })
            ->orderBy('sort_order')
            ->orderBy('nama')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'status' => true,
            'message' => 'Data karyawan berhasil diambil',
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'jabatan_id' => 'required|exists:master_jabatan,id',
            'kode' => 'required|string|max:30|unique:master_karyawan,kode',
            'nama' => 'required|string|max:150',
            'alamat' => 'nullable|string',
            'foto_karyawan' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20|unique:master_karyawan,nik',
            'no_ihs' => 'nullable|string|max:100',
            'gender' => 'nullable|in:L,P',
            'birthday_date' => 'nullable|date',
            'no_sip_dok' => 'nullable|string|max:100',
            'is_dokter_spesialis' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',

            'penempatan' => 'nullable|array',
            'penempatan.*.toko_id' => 'required|exists:master_toko,id',
            'penempatan.*.is_primary' => 'nullable|boolean',
            'penempatan.*.tanggal_mulai' => 'nullable|date',
            'penempatan.*.tanggal_selesai' => 'nullable|date|after_or_equal:penempatan.*.tanggal_mulai',
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
            $karyawan = MasterKaryawan::create([
                'jabatan_id' => $request->jabatan_id,
                'kode' => $request->kode,
                'nama' => $request->nama,
                'alamat' => $request->alamat,
                'foto_karyawan' => $request->foto_karyawan,
                'no_telp' => $request->no_telp,
                'nik' => $request->nik,
                'no_ihs' => $request->no_ihs,
                'gender' => $request->gender,
                'birthday_date' => $request->birthday_date,
                'no_sip_dok' => $request->no_sip_dok,
                'is_dokter_spesialis' => $request->is_dokter_spesialis ?? 0,
                'is_delete' => 0,
                'sort_order' => $request->sort_order ?? 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);

            $this->syncPenempatan($karyawan->id, $request->penempatan ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data karyawan berhasil disimpan',
                'data' => $karyawan->load(['jabatan', 'penempatan.toko']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data karyawan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterKaryawan::with(['jabatan', 'penempatan.toko'])
            ->active()
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data karyawan tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail karyawan berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $karyawan = MasterKaryawan::active()->find($id);

        if (!$karyawan) {
            return response()->json([
                'status' => false,
                'message' => 'Data karyawan tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'jabatan_id' => 'required|exists:master_jabatan,id',
            'kode' => 'required|string|max:30|unique:master_karyawan,kode,' . $id,
            'nama' => 'required|string|max:150',
            'alamat' => 'nullable|string',
            'foto_karyawan' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20|unique:master_karyawan,nik,' . $id,
            'no_ihs' => 'nullable|string|max:100',
            'gender' => 'nullable|in:L,P',
            'birthday_date' => 'nullable|date',
            'no_sip_dok' => 'nullable|string|max:100',
            'is_dokter_spesialis' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',

            'penempatan' => 'nullable|array',
            'penempatan.*.toko_id' => 'required|exists:master_toko,id',
            'penempatan.*.is_primary' => 'nullable|boolean',
            'penempatan.*.tanggal_mulai' => 'nullable|date',
            'penempatan.*.tanggal_selesai' => 'nullable|date',
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
            $karyawan->update([
                'jabatan_id' => $request->jabatan_id,
                'kode' => $request->kode,
                'nama' => $request->nama,
                'alamat' => $request->alamat,
                'foto_karyawan' => $request->foto_karyawan,
                'no_telp' => $request->no_telp,
                'nik' => $request->nik,
                'no_ihs' => $request->no_ihs,
                'gender' => $request->gender,
                'birthday_date' => $request->birthday_date,
                'no_sip_dok' => $request->no_sip_dok,
                'is_dokter_spesialis' => $request->is_dokter_spesialis ?? 0,
                'sort_order' => $request->sort_order ?? $karyawan->sort_order,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            $this->syncPenempatan($karyawan->id, $request->penempatan ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data karyawan berhasil diperbarui',
                'data' => $karyawan->fresh()->load(['jabatan', 'penempatan.toko']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data karyawan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $karyawan = MasterKaryawan::active()->find($id);

        if (!$karyawan) {
            return response()->json([
                'status' => false,
                'message' => 'Data karyawan tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $karyawan->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            MasterKaryawanPenempatan::where('karyawan_id', $id)->update([
                'is_delete' => 1,
                'updated_by' => auth('api')->user()->username ?? 'system',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data karyawan berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data karyawan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function syncPenempatan($karyawanId, array $penempatan): void
    {
        MasterKaryawanPenempatan::where('karyawan_id', $karyawanId)->update([
            'is_delete' => 1,
            'updated_by' => auth('api')->user()->username ?? 'system',
            'updated_at' => now(),
        ]);

        foreach ($penempatan as $index => $item) {
            MasterKaryawanPenempatan::create([
                'karyawan_id' => $karyawanId,
                'toko_id' => $item['toko_id'],
                'is_primary' => $item['is_primary'] ?? ($index === 0 ? 1 : 0),
                'tanggal_mulai' => $item['tanggal_mulai'] ?? now()->toDateString(),
                'tanggal_selesai' => $item['tanggal_selesai'] ?? null,
                'is_delete' => 0,
                'created_by' => auth('api')->user()->username ?? 'system',
                'created_at' => now(),
            ]);
        }
    }
}