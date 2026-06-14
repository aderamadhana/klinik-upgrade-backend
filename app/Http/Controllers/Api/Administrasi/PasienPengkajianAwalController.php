<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Services\Administrasi\PasienPengkajianAwalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasienPengkajianAwalController extends Controller
{
    public function __construct(
        private readonly PasienPengkajianAwalService $service
    ) {
    }

    public function index(int $id): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Data pengkajian awal pasien berhasil diambil.',
                'data' => $this->service->index($id),
            ]);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse();
        } catch (Throwable $exception) {
            return $this->errorResponse(
                'Gagal mengambil data pengkajian awal pasien.',
                $exception
            );
        }
    }

    public function show(int $id, int $pengkajianId): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Detail pengkajian awal pasien berhasil diambil.',
                'data' => $this->service->show($id, $pengkajianId),
            ]);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Data pengkajian awal tidak ditemukan.');
        } catch (Throwable $exception) {
            return $this->errorResponse(
                'Gagal mengambil detail pengkajian awal pasien.',
                $exception
            );
        }
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            return response()->json([
                'status' => true,
                'message' => 'Pengkajian awal pasien berhasil disimpan.',
                'data' => $this->service->store(
                    $id,
                    $payload,
                    $this->actorContext()
                ),
            ], 201);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse();
        } catch (Throwable $exception) {
            return $this->errorResponse(
                'Gagal menyimpan pengkajian awal pasien.',
                $exception
            );
        }
    }

    public function update(
        Request $request,
        int $id,
        int $pengkajianId
    ): JsonResponse {
        $payload = $this->validatedPayload($request);

        try {
            return response()->json([
                'status' => true,
                'message' => 'Pengkajian awal pasien berhasil diperbarui.',
                'data' => $this->service->update(
                    $id,
                    $pengkajianId,
                    $payload,
                    $this->actorContext()
                ),
            ]);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Data pengkajian awal tidak ditemukan.');
        } catch (Throwable $exception) {
            return $this->errorResponse(
                'Gagal memperbarui pengkajian awal pasien.',
                $exception
            );
        }
    }

    private function validatedPayload(Request $request): array
    {
        $validator = Validator::make(
            $request->all(),
            [
                'tanggal_pengkajian' => ['required', 'date'],
                's_keluhan_utama' => ['required', 'string', 'max:10000'],
                's_riwayat_penyakit_sekarang' => ['nullable', 'string', 'max:10000'],
                's_riwayat_penyakit_dahulu' => ['nullable', 'string', 'max:10000'],
                's_riwayat_penyakit_keluarga' => ['nullable', 'string', 'max:10000'],
                'o_keadaan_umum' => ['required', 'string', 'max:255'],
                'o_gcs' => ['nullable', 'string', 'max:100'],
                'o_eye_gcs' => ['nullable', 'integer', 'between:1,4'],
                'o_verbal_gcs' => ['nullable', 'integer', 'between:1,5'],
                'o_motor_gcs' => ['nullable', 'integer', 'between:1,6'],
                'o_keadaan_tht_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_tht' => ['nullable', 'required_if:o_keadaan_tht_checklist,2', 'string', 'max:2000'],
                'o_keadaan_kepala_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_kepala' => ['nullable', 'required_if:o_keadaan_kepala_checklist,2', 'string', 'max:2000'],
                'o_keadaan_mata_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_mata' => ['nullable', 'required_if:o_keadaan_mata_checklist,2', 'string', 'max:2000'],
                'o_keadaan_leher_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_leher' => ['nullable', 'required_if:o_keadaan_leher_checklist,2', 'string', 'max:2000'],
                'o_keadaan_paru_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_paru' => ['nullable', 'required_if:o_keadaan_paru_checklist,2', 'string', 'max:2000'],
                'o_keadaan_jantung_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_jantung' => ['nullable', 'required_if:o_keadaan_jantung_checklist,2', 'string', 'max:2000'],
                'o_keadaan_abdomen_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_abdomen' => ['nullable', 'required_if:o_keadaan_abdomen_checklist,2', 'string', 'max:2000'],
                'o_keadaan_ekstremitas_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_ekstremitas' => ['nullable', 'required_if:o_keadaan_ekstremitas_checklist,2', 'string', 'max:2000'],
                'o_keadaan_kulit_checklist' => ['required', 'integer', 'in:1,2'],
                'o_keadaan_kulit' => ['nullable', 'required_if:o_keadaan_kulit_checklist,2', 'string', 'max:2000'],
                'pemeriksaan_fisik_khusus' => ['nullable', 'string', 'max:10000'],
                'pemeriksaan_penunjang' => ['nullable', 'string', 'max:10000'],
                'a_diagnosa' => ['required', 'string', 'max:10000'],
                'p_rencana_terapi' => ['required', 'string', 'max:10000'],
                'rujuk_ke' => ['nullable', 'string', 'max:255'],
                'tanggal_kontrol' => ['nullable', 'date'],
                'info_hasil_pemeriksaan' => ['required', 'boolean'],
                'info_tindakan_pengobatan_resiko' => ['required', 'boolean'],
                'info_kemungkinan_komplikasi' => ['required', 'boolean'],
                'status_paham_pasien' => ['required', 'integer', 'in:0,1'],
            ],
            [
                'tanggal_pengkajian.required' => 'Tanggal pengkajian wajib diisi.',
                's_keluhan_utama.required' => 'Keluhan utama wajib diisi.',
                'o_keadaan_umum.required' => 'Keadaan umum wajib diisi.',
                'a_diagnosa.required' => 'Diagnosis atau assessment wajib diisi.',
                'p_rencana_terapi.required' => 'Rencana terapi wajib diisi.',
                '*.required_if' => 'Keterangan wajib diisi untuk hasil pemeriksaan abnormal.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function actorContext(): array
    {
        $user = auth('api')->user();
        $karyawanId = $user?->karyawan_id;
        $namaKaryawan = null;

        if ($karyawanId) {
            $namaKaryawan = DB::table('master_karyawan')
                ->where('id', $karyawanId)
                ->value('nama');
        }

        return [
            'user_id' => $user?->id,
            'karyawan_id' => $karyawanId,
            'nama' => $namaKaryawan
                ?: $user?->display_name
                ?: $user?->nama
                ?: $user?->username,
        ];
    }

    private function notFoundResponse(
        string $message = 'Pasien tidak ditemukan.'
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 404);
    }

    private function errorResponse(
        string $message,
        Throwable $exception
    ): JsonResponse {
        report($exception);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $exception->getMessage() : null,
        ], 500);
    }
}
