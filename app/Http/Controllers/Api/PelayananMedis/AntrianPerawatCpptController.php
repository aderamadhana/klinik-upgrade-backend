<?php

namespace App\Http\Controllers\Api\PelayananMedis;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterAssessment;
use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterSubjective;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiPerawatCppt;
use App\Models\Registrasi\RegistrasiTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AntrianPerawatCpptController extends Controller
{
    public function store(Request $request, $id)
    {
        $validated = $request->validate([
            'tanggal_pengisian' => ['required', 'date'],
            'perawat_id' => ['required', 'integer'],
            'subjective_ids' => ['required', 'array', 'min:1'],
            'subjective_ids.*' => ['required', 'integer', 'distinct'],
            'subjective_lainnya' => ['nullable', 'string', 'max:10000'],
            'objective' => ['required', 'string', 'max:10000'],
            'assessment_ids' => ['required', 'array', 'min:1'],
            'assessment_ids.*' => ['required', 'integer', 'distinct'],
            'assessment_lainnya' => ['nullable', 'string', 'max:10000'],
            'plan' => ['required', 'string', 'max:10000'],
            'tindakan' => ['required', 'string', 'max:10000'],
        ], [
            'tanggal_pengisian.required' => 'Tanggal dan jam pengisian wajib diisi.',
            'tanggal_pengisian.date' => 'Format tanggal dan jam pengisian tidak valid.',
            'perawat_id.required' => 'Perawat penanggung jawab wajib dipilih.',
            'perawat_id.integer' => 'Perawat penanggung jawab tidak valid.',
            'subjective_ids.required' => 'Subjective wajib dipilih.',
            'subjective_ids.array' => 'Format pilihan Subjective tidak valid.',
            'subjective_ids.min' => 'Pilih minimal satu Subjective.',
            'subjective_ids.*.integer' => 'Pilihan Subjective tidak valid.',
            'subjective_ids.*.distinct' => 'Pilihan Subjective tidak boleh duplikat.',
            'objective.required' => 'Catatan objective wajib diisi.',
            'assessment_ids.required' => 'Assessment wajib dipilih.',
            'assessment_ids.array' => 'Format pilihan Assessment tidak valid.',
            'assessment_ids.min' => 'Pilih minimal satu Assessment.',
            'assessment_ids.*.integer' => 'Pilihan Assessment tidak valid.',
            'assessment_ids.*.distinct' => 'Pilihan Assessment tidak boleh duplikat.',
            'plan.required' => 'Catatan plan wajib diisi.',
            'tindakan.required' => 'Catatan tindakan dan evaluasi wajib diisi.',
            '*.max' => 'Isi field tidak boleh lebih dari 10.000 karakter.',
        ]);

        $subjectiveIds = $this->normalizeIds($validated['subjective_ids']);
        $assessmentIds = $this->normalizeIds($validated['assessment_ids']);

        $cppt = DB::transaction(function () use (
            $validated,
            $subjectiveIds,
            $assessmentIds,
            $id
        ) {
            $registrasi = RegistrasiKunjungan::query()
                ->where(function ($query) {
                    $query->whereNull('is_delete')
                        ->orWhere('is_delete', 0);
                })
                ->lockForUpdate()
                ->findOrFail($id);

            if ((int) $registrasi->is_treatment !== 1) {
                throw ValidationException::withMessages([
                    'registrasi' => ['Registrasi ini tidak memiliki layanan treatment.'],
                ]);
            }

            $task = RegistrasiTask::query()
                ->where('registrasi_id', $registrasi->id)
                ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
                ->where(function ($query) {
                    $query->whereNull('is_delete')
                        ->orWhere('is_delete', 0);
                })
                ->orderBy('task_order')
                ->lockForUpdate()
                ->first();

            if (!$task) {
                throw ValidationException::withMessages([
                    'registrasi' => ['Task tindakan perawat tidak ditemukan.'],
                ]);
            }

            $paymentTask = RegistrasiTask::query()
                ->where('registrasi_id', $registrasi->id)
                ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                ->where(function ($query) {
                    $query->whereNull('is_delete')
                        ->orWhere('is_delete', 0);
                })
                ->orderBy('task_order')
                ->first();

            if (!$paymentTask || (int) $paymentTask->status !== RegistrasiTask::STATUS_SELESAI) {
                throw ValidationException::withMessages([
                    'registrasi' => ['CPPT hanya dapat diisi setelah pembayaran selesai.'],
                ]);
            }

            $username = $this->username();
            $now = now();

            if ((int) $task->status === RegistrasiTask::STATUS_MENUNGGU) {
                $task->update([
                    'status' => RegistrasiTask::STATUS_PROSES,
                    'started_at' => $task->started_at ?: $now,
                    'updated_by' => $username,
                    'updated_at' => $now,
                ]);
            }

            if ((int) $task->status !== RegistrasiTask::STATUS_PROSES) {
                throw ValidationException::withMessages([
                    'registrasi' => ['CPPT tidak dapat diubah karena task perawat sudah selesai atau dibatalkan.'],
                ]);
            }

            $subjectiveMap = MasterSubjective::query()
                ->active()
                ->where('is_active', 1)
                ->whereIn('id', $subjectiveIds)
                ->get()
                ->keyBy(fn ($item) => (int) $item->id);

            if ($subjectiveMap->count() !== count($subjectiveIds)) {
                throw ValidationException::withMessages([
                    'subjective_ids' => [
                        'Salah satu Subjective yang dipilih tidak aktif atau tidak ditemukan.',
                    ],
                ]);
            }

            $assessmentMap = MasterAssessment::query()
                ->active()
                ->where('is_active', 1)
                ->whereIn('id', $assessmentIds)
                ->get()
                ->keyBy(fn ($item) => (int) $item->id);

            if ($assessmentMap->count() !== count($assessmentIds)) {
                throw ValidationException::withMessages([
                    'assessment_ids' => [
                        'Salah satu Assessment yang dipilih tidak aktif atau tidak ditemukan.',
                    ],
                ]);
            }

            $perawat = MasterKaryawan::query()
                ->active()
                ->whereKey($validated['perawat_id'])
                ->whereHas('jabatan', function ($query) {
                    $query->active()
                        ->whereIn('kode_jabatan', ['NS', 'BC']);
                })
                ->whereHas('penempatan', function ($query) use ($registrasi) {
                    $query->active()
                        ->where('toko_id', $registrasi->toko_id);
                })
                ->first();

            if (!$perawat) {
                throw ValidationException::withMessages([
                    'perawat_id' => [
                        'Perawat penanggung jawab harus merupakan Nurse atau Beautician aktif pada cabang registrasi.',
                    ],
                ]);
            }

            $subjectiveLabels = collect($subjectiveIds)
                ->map(fn ($subjectiveId) => trim((string) $subjectiveMap->get($subjectiveId)?->nama))
                ->filter()
                ->values()
                ->all();

            $assessmentLabels = collect($assessmentIds)
                ->map(function ($assessmentId) use ($assessmentMap) {
                    $assessment = $assessmentMap->get($assessmentId);

                    return trim(
                        implode(' - ', array_filter([
                            trim((string) $assessment?->kode),
                            trim((string) $assessment?->nama),
                        ]))
                    );
                })
                ->filter()
                ->values()
                ->all();

            $subjectiveLainnya = $this->nullableText($validated['subjective_lainnya'] ?? null);
            $assessmentLainnya = $this->nullableText($validated['assessment_lainnya'] ?? null);

            $cppt = RegistrasiPerawatCppt::query()
                ->where('registrasi_id', $registrasi->id)
                ->where(function ($query) {
                    $query->whereNull('is_delete')
                        ->orWhere('is_delete', 0);
                })
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $payload = [
                'task_id' => $task->id,
                'dokter_id' => $registrasi->dokter_awal_id,
                'perawat_id' => $perawat->id,
                'subjective' => $this->composeSnapshot($subjectiveLabels, $subjectiveLainnya),
                'subjective_lainnya' => $subjectiveLainnya,
                'objective' => trim($validated['objective']),
                'assessment' => $this->composeSnapshot($assessmentLabels, $assessmentLainnya),
                'assessment_lainnya' => $assessmentLainnya,
                'plan' => trim($validated['plan']),
                'tindakan' => trim($validated['tindakan']),
                'tanggal_pengisian' => $validated['tanggal_pengisian'],
                'status' => RegistrasiPerawatCppt::STATUS_FINAL,
                'is_delete' => 0,
                'updated_by' => $username,
                'updated_at' => $now,
            ];

            if ($cppt) {
                $cppt->fill($payload);
                $cppt->save();
            } else {
                $cppt = RegistrasiPerawatCppt::query()->create([
                    'registrasi_id' => $registrasi->id,
                    ...$payload,
                    'created_by' => $username,
                    'created_at' => $now,
                ]);
            }

            $cppt->subjectives()->sync(
                $this->buildPivotSync($subjectiveIds, $now)
            );
            $cppt->assessments()->sync(
                $this->buildPivotSync($assessmentIds, $now)
            );

            $this->syncNurseTaskProgress($registrasi, $task, $now, $username);

            return $cppt;
        });

        return response()->json([
            'status' => true,
            'message' => 'CPPT perawat berhasil disimpan.',
            'data' => $cppt->fresh([
                'dokter',
                'perawat.jabatan',
                'subjectives',
                'assessments',
            ]),
        ]);
    }

    private function composeSnapshot(array $referenceLabels, ?string $additionalText): string
    {
        $referenceText = implode(', ', array_filter(array_map('trim', $referenceLabels)));

        return trim(
            implode("\n", array_filter([
                $referenceText,
                $additionalText,
            ]))
        );
    }

    private function buildPivotSync(array $ids, $createdAt): array
    {
        $sync = [];

        foreach ($ids as $index => $referenceId) {
            $sync[$referenceId] = [
                'sort_order' => $index + 1,
                'created_at' => $createdAt,
            ];
        }

        return $sync;
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map('intval', $ids),
                    fn ($id) => $id > 0
                )
            )
        );
    }

    private function nullableText($value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }


    private function syncNurseTaskProgress(
        RegistrasiKunjungan $registrasi,
        RegistrasiTask $task,
        $now,
        string $username
    ): void {
        $cpptDone = $this->hasFinalCppt($registrasi->id);
        $beforeAfterDone = $this->hasCompleteBeforeAfter($registrasi->id, $task->id);
        $bahanDone = $this->hasBahanTreatment($registrasi->id, $task->id);

        if ($cpptDone && $beforeAfterDone && $bahanDone) {
            $task->update([
                'status' => RegistrasiTask::STATUS_SELESAI,
                'finished_at' => $task->finished_at ?: $now,
                'updated_by' => $username,
                'updated_at' => $now,
            ]);

            $registrasi->update([
                'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                'status' => RegistrasiKunjungan::STATUS_SELESAI,
                'updated_by' => $username,
                'updated_at' => $now,
            ]);
        }
    }

    private function hasFinalCppt(int $registrasiId): bool
    {
        return DB::table('registrasi_perawat_cppt')
            ->where('registrasi_id', $registrasiId)
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where('status', RegistrasiPerawatCppt::STATUS_FINAL)
            ->exists();
    }

    private function hasCompleteBeforeAfter(int $registrasiId, int $taskId): bool
    {
        $photos = DB::table('registrasi_perawat_before_after_foto')
            ->select('tipe_foto', 'urutan')
            ->where('registrasi_id', $registrasiId)
            ->where('task_id', $taskId)
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->whereNotNull('file_path')
            ->whereIn('tipe_foto', ['before', 'after'])
            ->whereBetween('urutan', [1, 3])
            ->get();

        return $photos->where('tipe_foto', 'before')->pluck('urutan')->unique()->count() >= 3
            && $photos->where('tipe_foto', 'after')->pluck('urutan')->unique()->count() >= 3;
    }

    private function hasBahanTreatment(int $registrasiId, int $taskId): bool
    {
        return DB::table('registrasi_perawat_bahan_treatment_detail')
            ->where('registrasi_id', $registrasiId)
            ->where('task_id', $taskId)
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($query) {
                $query->where('status', 1)
                    ->orWhere('jumlah_terpakai', '>', 0);
            })
            ->exists();
    }

    private function username(): string
    {
        $user = auth()->user();

        return $user?->username
            ?? $user?->name
            ?? 'system';
    }
}
