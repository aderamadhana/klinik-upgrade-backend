<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterToko;
use App\Models\Pasien;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PasienController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);

        if ($perPage <= 0) {
            $perPage = 10;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $search = trim((string) $request->get('search', ''));

        $query = Pasien::query()
            ->active()
            ->with([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('no_rm', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%")
                    ->orWhere('no_identitas', 'like', "%{$search}%")
                    ->orWhere('no_hp', 'like', "%{$search}%")
                    ->orWhere('no_wa', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tipe_pasien')) {
            $query->where('tipe_pasien', $this->mapTipePasien($request->tipe_pasien));
        }

        if ($request->filled('jenis_kelamin')) {
            $query->where('jenis_kelamin', $request->jenis_kelamin);
        }

        if ($request->filled('pekerjaan_id')) {
            $query->where('pekerjaan_id', $request->pekerjaan_id);
        }

        if ($request->filled('agama_id')) {
            $query->where('agama_id', $request->agama_id);
        }

        if ($request->filled('provinsi_kode')) {
            $query->where('provinsi_kode', $request->provinsi_kode);
        }

        if ($request->filled('kota_kode')) {
            $query->where('kota_kode', $request->kota_kode);
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(function ($pasien) {
            return $this->formatPasien($pasien);
        });

        return response()->json([
            'status' => true,
            'message' => 'Data pasien berhasil diambil',
            'data' => $paginator,
        ]);
    }

    public function show($id)
    {
        $pasien = Pasien::query()
            ->active()
            ->with([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ])
            ->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail pasien berhasil diambil',
            'data' => $this->formatPasien($pasien),
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->validator($request, null, 'store');

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payload = $this->payload($request);

            $payload['no_rm'] = $this->generateNoRm($payload['toko_id']);
            $payload['created_by'] = $this->authUserId();
            $payload['created_at'] = now();
            $payload['updated_at'] = null;

            $pasien = Pasien::create($payload);

            DB::commit();

            $pasien->load([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Data pasien berhasil ditambahkan',
                'data' => $this->formatPasien($pasien),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Data pasien gagal ditambahkan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $pasien = Pasien::query()
            ->active()
            ->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $validator = $this->validator($request, $id, 'update');

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payload = $this->payload($request);

            unset($payload['no_rm']);
            unset($payload['toko_id']);
            unset($payload['is_delete']);

            $payload['updated_by'] = $this->authUserId();
            $payload['updated_at'] = now();

            $pasien->update($payload);

            DB::commit();

            $pasien = $pasien->fresh()->load([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Data pasien berhasil diperbarui',
                'data' => $this->formatPasien($pasien),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Data pasien gagal diperbarui',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $pasien = Pasien::query()
            ->active()
            ->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $pasien->update([
                'is_delete' => 1,
                'updated_by' => $this->authUserId(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data pasien berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Data pasien gagal dihapus',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validator(Request $request, $id = null, $mode = 'store')
    {
        return Validator::make($request->all(), [
            'nama' => 'nullable|string|max:150',
            'nama_pasien' => 'nullable|string|max:150',

            'tipe_pasien' => 'required',

            'toko_id' => $mode === 'store'
                ? 'required|exists:master_toko,id'
                : 'nullable|exists:master_toko,id',

            'no_identitas' => 'required|string|max:50',

            'jenis_kelamin' => 'required|in:L,P',

            'pekerjaan_id' => 'required|exists:master_pekerjaan,id',
            'pekerjaan' => 'nullable',

            'status_pernikahan' => 'nullable',

            'agama_id' => 'required|exists:master_agama,id',
            'agama' => 'nullable',

            'tempat_lahir' => 'required|string|max:100',
            'tanggal_lahir' => 'required|date',

            'no_telp' => 'nullable|string|max:30',
            'no_hp' => 'required|string|max:30',
            'no_wa' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',

            'provinsi_kode' => 'nullable|string|max:20',
            'kota_kode' => 'nullable|string|max:20',
            'kecamatan_kode' => 'nullable|string|max:20',
            'kelurahan_kode' => 'nullable|string|max:20',

            'provinsi' => 'nullable|string|max:20',
            'kota' => 'nullable|string|max:20',
            'kecamatan' => 'nullable|string|max:20',
            'kelurahan' => 'nullable|string|max:20',

            'alamat' => 'nullable|string',
            'alamat_detail' => 'required|string',

            'sumber_info' => 'nullable|string|max:150',

            'alergi_obat' => 'nullable|string',
            'masalah_kulit' => 'nullable|string',
            'catatan' => 'nullable|string',
        ], [
            'nama.required' => 'Nama pasien wajib diisi',
            'tipe_pasien.required' => 'Tipe pasien wajib diisi',

            'toko_id.required' => 'Cabang wajib diisi',
            'toko_id.exists' => 'Cabang tidak valid',

            'no_identitas.required' => 'KTP/SIM/Passport wajib diisi',

            'jenis_kelamin.required' => 'Jenis kelamin wajib diisi',
            'jenis_kelamin.in' => 'Jenis kelamin tidak valid',

            'pekerjaan_id.required' => 'Pekerjaan wajib diisi',
            'pekerjaan_id.exists' => 'Pekerjaan tidak valid',

            'agama_id.required' => 'Agama wajib diisi',
            'agama_id.exists' => 'Agama tidak valid',

            'tempat_lahir.required' => 'Tempat lahir wajib diisi',
            'tanggal_lahir.required' => 'Tanggal lahir wajib diisi',
            'tanggal_lahir.date' => 'Format tanggal lahir tidak valid',

            'no_hp.required' => 'No. HP wajib diisi',
            'email.email' => 'Format email tidak valid',

            'alamat_detail.required' => 'Alamat detail wajib diisi',
        ])->after(function ($validator) use ($request) {
            if (!$request->filled('nama') && !$request->filled('nama_pasien')) {
                $validator->errors()->add('nama_pasien', 'Nama pasien wajib diisi');
            }

            $tipePasien = $this->mapTipePasien($request->tipe_pasien);

            if (!in_array($tipePasien, [1, 2], true)) {
                $validator->errors()->add('tipe_pasien', 'Tipe pasien tidak valid');
            }

            $statusPernikahan = $this->mapStatusPernikahan($request->status_pernikahan);

            if (
                $request->filled('status_pernikahan') &&
                !in_array($statusPernikahan, [1, 2, 3], true)
            ) {
                $validator->errors()->add('status_pernikahan', 'Status pernikahan tidak valid');
            }

            $this->validateDigitsField(
                $validator,
                'no_identitas',
                $request->input('no_identitas'),
                16,
                'KTP/SIM/Passport',
                true
            );

            $this->validateDigitsField(
                $validator,
                'no_telp',
                $request->input('no_telp'),
                10,
                'No. Telp',
                false
            );

            $this->validateMobilePhone62(
                $validator,
                'no_hp',
                $request->input('no_hp'),
                'No. HP',
                true
            );

            $this->validateMobilePhone62(
                $validator,
                'no_wa',
                $request->input('no_wa'),
                'No. WA',
                false
            );
        });
    }

    private function payload(Request $request)
    {
        return [
            'nama' => $request->nama ?? $request->nama_pasien,

            'tipe_pasien' => $this->mapTipePasien($request->tipe_pasien),

            'toko_id' => $request->toko_id,

            'no_identitas' => $this->cleanDigits($request->no_identitas),

            'jenis_kelamin' => $request->jenis_kelamin,

            'pekerjaan_id' => $request->pekerjaan_id ?? $this->extractId($request->pekerjaan),

            'status_pernikahan' => $this->mapStatusPernikahan($request->status_pernikahan),

            'agama_id' => $request->agama_id ?? $this->extractId($request->agama),

            'tempat_lahir' => $request->tempat_lahir,
            'tanggal_lahir' => $request->tanggal_lahir,

            'no_telp' => $this->cleanNullableDigits($request->no_telp),
            'no_hp' => $this->normalizePhone62($request->no_hp),
            'no_wa' => $this->normalizePhone62($request->no_wa),
            'email' => $request->email,

            'provinsi_kode' => $request->provinsi_kode ?? $this->extractCode($request->provinsi),
            'kota_kode' => $request->kota_kode ?? $this->extractCode($request->kota),
            'kecamatan_kode' => $request->kecamatan_kode ?? $this->extractCode($request->kecamatan),
            'kelurahan_kode' => $request->kelurahan_kode ?? $this->extractCode($request->kelurahan),

            'alamat' => $request->alamat ?? $request->alamat_detail,

            'sumber_info' => $request->sumber_info,

            'alergi_obat' => $request->alergi_obat,
            'masalah_kulit' => $request->masalah_kulit,
            'catatan' => $request->catatan,

            'is_delete' => 0,
        ];
    }

    private function validateDigitsField($validator, $field, $value, $maxLength, $label, $required = false)
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                $validator->errors()->add($field, "{$label} wajib diisi");
            }

            return;
        }

        if (!preg_match('/^[0-9]+$/', $value)) {
            $validator->errors()->add($field, "{$label} hanya boleh angka");
            return;
        }

        if (strlen($value) > $maxLength) {
            $validator->errors()->add($field, "{$label} maksimal {$maxLength} digit");
        }
    }

    private function validateMobilePhone62($validator, $field, $value, $label, $required = false)
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                $validator->errors()->add($field, "{$label} wajib diisi");
            }

            return;
        }

        if (!preg_match('/^[0-9]+$/', $value)) {
            $validator->errors()->add($field, "{$label} hanya boleh angka");
            return;
        }

        $normalized = $this->normalizePhone62($value);

        if (!$normalized || !str_starts_with($normalized, '62')) {
            $validator->errors()->add($field, "{$label} harus menggunakan format nomor Indonesia");
            return;
        }

        if (strlen($normalized) > 13) {
            $validator->errors()->add($field, "{$label} maksimal 13 digit termasuk kode negara 62");
        }
    }

    private function cleanDigits($value)
    {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function cleanNullableDigits($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->cleanDigits($value);
    }

    private function normalizePhone62($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $digits = $this->cleanDigits($value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62' . $digits;
        }

        return $digits;
    }

    private function formatPasien($pasien)
    {
        return [
            'id' => $pasien->id,
            'no_rm' => $pasien->no_rm,

            'nama' => $pasien->nama,
            'nama_pasien' => $pasien->nama,

            'tipe_pasien' => $pasien->tipe_pasien,
            'tipe_pasien_text' => $this->tipePasienText($pasien->tipe_pasien),

            'toko_id' => $pasien->toko_id,
            'toko' => $pasien->relationLoaded('toko') && $pasien->toko
                ? [
                    'id' => $pasien->toko->id,
                    'kode_toko' => $pasien->toko->kode_toko,
                    'nama_toko' => $pasien->toko->nama_toko,
                    'label' => $pasien->toko->nama_toko,
                    'value' => $pasien->toko->id,
                ]
                : null,

            'no_identitas' => $pasien->no_identitas,

            'jenis_kelamin' => $pasien->jenis_kelamin,
            'jenis_kelamin_text' => $this->jenisKelaminText($pasien->jenis_kelamin),

            'pekerjaan_id' => $pasien->pekerjaan_id,
            'pekerjaan' => $pasien->relationLoaded('pekerjaan') && $pasien->pekerjaan
                ? [
                    'id' => $pasien->pekerjaan->id,
                    'nama_pekerjaan' => $pasien->pekerjaan->nama_pekerjaan,
                    'label' => $pasien->pekerjaan->nama_pekerjaan,
                    'value' => $pasien->pekerjaan->id,
                ]
                : null,

            'status_pernikahan' => $pasien->status_pernikahan,
            'status_pernikahan_text' => $this->statusPernikahanText($pasien->status_pernikahan),

            'agama_id' => $pasien->agama_id,
            'agama' => $pasien->relationLoaded('agama') && $pasien->agama
                ? [
                    'id' => $pasien->agama->id,
                    'kode_agama' => $pasien->agama->kode_agama,
                    'nama_agama' => $pasien->agama->nama_agama,
                    'label' => $pasien->agama->nama_agama,
                    'value' => $pasien->agama->id,
                ]
                : null,

            'tempat_lahir' => $pasien->tempat_lahir,
            'tanggal_lahir' => $this->formatDate($pasien->tanggal_lahir),

            'no_telp' => $pasien->no_telp,
            'no_hp' => $pasien->no_hp,
            'no_wa' => $pasien->no_wa,
            'email' => $pasien->email,

            'provinsi_kode' => $pasien->provinsi_kode,
            'kota_kode' => $pasien->kota_kode,
            'kecamatan_kode' => $pasien->kecamatan_kode,
            'kelurahan_kode' => $pasien->kelurahan_kode,

            'alamat' => $pasien->alamat,
            'alamat_detail' => $pasien->alamat,

            'sumber_info' => $pasien->sumber_info,

            'alergi_obat' => $pasien->alergi_obat,
            'masalah_kulit' => $pasien->masalah_kulit,
            'catatan' => $pasien->catatan,

            'is_delete' => $pasien->is_delete,

            'created_by' => $pasien->created_by,
            'updated_by' => $pasien->updated_by,
            'created_at' => $this->formatDateTime($pasien->created_at),
            'updated_at' => $this->formatDateTime($pasien->updated_at),
        ];
    }

    private function generateNoRm($tokoId, $date = null)
    {
        $date = $date ?: now()->format('Y-m-d');

        $toko = MasterToko::query()
            ->where('id', $tokoId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->lockForUpdate()
            ->first();

        if (!$toko) {
            throw new \Exception('Data cabang tidak ditemukan');
        }

        if (empty($toko->kode_toko)) {
            throw new \Exception('Kode toko belum diatur');
        }

        $tanggal = date('Ymd', strtotime($date));
        $prefix = $toko->kode_toko . $tanggal;

        $lastNoRm = Pasien::query()
            ->where('toko_id', $tokoId)
            ->where('no_rm', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('no_rm')
            ->value('no_rm');

        if (!$lastNoRm) {
            return $prefix . '001';
        }

        $lastNumber = (int) substr($lastNoRm, -3);
        $nextNumber = $lastNumber + 1;

        return $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function mapTipePasien($value)
    {
        if (is_array($value)) {
            $value = $value['value'] ?? $value['id'] ?? $value['title'] ?? null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return match (strtolower(trim((string) $value))) {
            'pasien' => 1,
            'non pasien', 'non_pasien', 'non-pasien' => 2,
            default => null,
        };
    }

    private function mapStatusPernikahan($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $value = $value['value'] ?? $value['id'] ?? $value['title'] ?? null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return match (strtolower(trim((string) $value))) {
            'belum menikah' => 1,
            'menikah' => 2,
            'cerai' => 3,
            default => null,
        };
    }

    private function extractId($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $value = $value['id'] ?? $value['value'] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function extractCode($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value['code']
                ?? $value['kode']
                ?? $value['value']
                ?? $value['id']
                ?? null;
        }

        return (string) $value;
    }

    private function tipePasienText($value)
    {
        return match ((int) $value) {
            1 => 'Pasien',
            2 => 'Non Pasien',
            default => null,
        };
    }

    private function statusPernikahanText($value)
    {
        return match ((int) $value) {
            1 => 'Belum Menikah',
            2 => 'Menikah',
            3 => 'Cerai',
            default => null,
        };
    }

    private function jenisKelaminText($value)
    {
        return match ($value) {
            'L' => 'Laki-laki',
            'P' => 'Perempuan',
            default => null,
        };
    }

    private function formatDate($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d');
        }

        return date('Y-m-d', strtotime($value));
    }

    private function formatDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', strtotime($value));
    }

    private function authUserId()
    {
        if (auth('api')->check()) {
            return auth('api')->id();
        }

        return null;
    }
}