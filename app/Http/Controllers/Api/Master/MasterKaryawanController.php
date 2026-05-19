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
        $search = trim((string) $request->get('search', ''));
        $jabatanId = $request->get('jabatan_id');
        $tokoId = $request->get('toko_id');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterKaryawan::query()
            ->active()
            ->with(['jabatan', 'penempatan.toko'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('no_telp', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhereHas('jabatan', function ($jabatan) use ($search) {
                            $jabatan->where('nama', 'like', "%{$search}%")
                                ->orWhere('nama_jabatan', 'like', "%{$search}%");
                        })
                        ->orWhereHas('penempatan.toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        });
                });
            })
            ->when($jabatanId, function ($q) use ($jabatanId) {
                $q->where('jabatan_id', $jabatanId);
            })
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->whereHas('penempatan', function ($penempatan) use ($tokoId) {
                    $penempatan->active()
                        ->where('toko_id', $tokoId);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data karyawan berhasil diambil',
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
        $validator = $this->validator($request);

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
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);

            $this->syncPenempatan($karyawan->id, $request->penempatan ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data karyawan berhasil disimpan',
                'data' => $karyawan->fresh()->load(['jabatan', 'penempatan.toko']),
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

        $validator = $this->validator($request, $id);

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
                'updated_by' => $this->username(),
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
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            MasterKaryawanPenempatan::query()
                ->where('karyawan_id', $karyawan->id)
                ->where('is_delete', 0)
                ->update([
                    'is_delete' => 1,
                    'is_primary' => 0,
                    'updated_by' => $this->username(),
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

    private function validator(Request $request, $id = null)
    {
        $validator = Validator::make($request->all(), [
            'jabatan_id' => 'required|exists:master_jabatan,id',
            'kode' => 'required|string|max:30|unique:master_karyawan,kode' . ($id ? ',' . $id : ''),
            'nama' => 'required|string|max:150',
            'alamat' => 'nullable|string',
            'foto_karyawan' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20|unique:master_karyawan,nik' . ($id ? ',' . $id : ''),
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

        $validator->after(function ($validator) use ($request) {
            $penempatan = collect($request->penempatan ?? []);

            $primaryCount = $penempatan
                ->filter(function ($item) {
                    return !empty($item['is_primary']);
                })
                ->count();

            if ($primaryCount > 1) {
                $validator->errors()->add(
                    'penempatan',
                    'Penempatan utama hanya boleh satu'
                );
            }

            $keys = [];

            foreach ($penempatan as $index => $item) {
                $tokoId = $item['toko_id'] ?? null;
                $tanggalMulai = $item['tanggal_mulai'] ?? null;
                $tanggalSelesai = $item['tanggal_selesai'] ?? null;

                if (empty($tokoId)) {
                    continue;
                }

                $normalizedTanggalMulai = $this->normalizeDate($tanggalMulai);
                $key = $tokoId . '|' . ($normalizedTanggalMulai ?: 'NULL');

                if (isset($keys[$key])) {
                    $validator->errors()->add(
                        "penempatan.{$index}.toko_id",
                        'Kombinasi toko dan tanggal mulai tidak boleh duplikat'
                    );
                }

                $keys[$key] = true;

                if (
                    !empty($tanggalMulai) &&
                    !empty($tanggalSelesai) &&
                    strtotime($tanggalSelesai) < strtotime($tanggalMulai)
                ) {
                    $validator->errors()->add(
                        "penempatan.{$index}.tanggal_selesai",
                        'Tanggal selesai tidak boleh lebih kecil dari tanggal mulai'
                    );
                }
            }
        });

        return $validator;
    }

    private function syncPenempatan($karyawanId, array $penempatan)
    {
        $username = $this->username();
        $now = now();

        $normalized = collect($penempatan)
            ->filter(function ($item) {
                return !empty($item['toko_id']);
            })
            ->map(function ($item) {
                return [
                    'toko_id' => (int) $item['toko_id'],
                    'is_primary' => !empty($item['is_primary']) ? 1 : 0,
                    'tanggal_mulai' => $this->normalizeDate($item['tanggal_mulai'] ?? null),
                    'tanggal_selesai' => $this->normalizeDate($item['tanggal_selesai'] ?? null),
                ];
            })
            ->values();

        $primaryCount = $normalized
            ->where('is_primary', 1)
            ->count();

        if ($primaryCount > 1) {
            throw new \Exception('Penempatan utama hanya boleh satu');
        }

        $incomingKeys = [];
        $keepIds = [];

        foreach ($normalized as $item) {
            $key = $this->makePenempatanKey(
                $karyawanId,
                $item['toko_id'],
                $item['tanggal_mulai']
            );

            if (isset($incomingKeys[$key])) {
                throw new \Exception('Terdapat data penempatan yang duplikat');
            }

            $incomingKeys[$key] = true;
        }

        $existingRows = MasterKaryawanPenempatan::query()
            ->where('karyawan_id', $karyawanId)
            ->get()
            ->keyBy(function ($row) {
                return $this->makePenempatanKey(
                    $row->karyawan_id,
                    $row->toko_id,
                    $row->tanggal_mulai
                );
            });

        MasterKaryawanPenempatan::query()
            ->where('karyawan_id', $karyawanId)
            ->where('is_delete', 0)
            ->update([
                'is_primary' => 0,
                'updated_by' => $username,
                'updated_at' => $now,
            ]);

        foreach ($normalized as $item) {
            $key = $this->makePenempatanKey(
                $karyawanId,
                $item['toko_id'],
                $item['tanggal_mulai']
            );

            if ($existingRows->has($key)) {
                $row = $existingRows->get($key);

                MasterKaryawanPenempatan::query()
                    ->where('id', $row->id)
                    ->update([
                        'toko_id' => $item['toko_id'],
                        'is_primary' => $item['is_primary'],
                        'tanggal_mulai' => $item['tanggal_mulai'],
                        'tanggal_selesai' => $item['tanggal_selesai'],
                        'is_delete' => 0,
                        'updated_by' => $username,
                        'updated_at' => $now,
                    ]);

                $keepIds[] = $row->id;
            } else {
                $newId = MasterKaryawanPenempatan::query()
                    ->insertGetId([
                        'karyawan_id' => $karyawanId,
                        'toko_id' => $item['toko_id'],
                        'is_primary' => $item['is_primary'],
                        'tanggal_mulai' => $item['tanggal_mulai'],
                        'tanggal_selesai' => $item['tanggal_selesai'],
                        'is_delete' => 0,
                        'created_by' => $username,
                        'updated_by' => null,
                        'created_at' => $now,
                        'updated_at' => null,
                    ]);

                $keepIds[] = $newId;
            }
        }

        $deleteQuery = MasterKaryawanPenempatan::query()
            ->where('karyawan_id', $karyawanId)
            ->where('is_delete', 0);

        if (count($keepIds) > 0) {
            $deleteQuery->whereNotIn('id', $keepIds);
        }

        $deleteQuery->update([
            'is_delete' => 1,
            'is_primary' => 0,
            'updated_by' => $username,
            'updated_at' => $now,
        ]);
    }

    private function makePenempatanKey($karyawanId, $tokoId, $tanggalMulai)
    {
        return implode('|', [
            (int) $karyawanId,
            (int) $tokoId,
            $this->normalizeDate($tanggalMulai) ?: 'NULL',
        ]);
    }

    private function normalizeDate($date)
    {
        if (empty($date)) {
            return null;
        }

        return date('Y-m-d', strtotime($date));
    }

    private function username()
    {
        return auth('api')->user()->username ?? 'system';
    }
}