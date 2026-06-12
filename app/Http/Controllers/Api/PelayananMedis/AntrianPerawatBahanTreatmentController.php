<?php

namespace App\Http\Controllers\Api\PelayananMedis;

use App\Http\Controllers\Controller;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiPerawatBahanTreatmentDetail;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AntrianPerawatBahanTreatmentController extends Controller
{
    public function show($id)
    {
        $registrasi = $this->getRegistrasi($id);
        $this->assertCanOpen($registrasi);

        $task = $this->getNurseTask($registrasi);
        $data = $this->buildResponseData($registrasi, $task);

        return response()->json([
            'status' => true,
            'message' => 'Data bahan treatment berhasil diambil.',
            'data' => $data,
        ]);
    }

    public function store(Request $request, $id)
    {
        $validated = $request->validate([
            'tanggal_pengisian' => ['required', 'date'],
            'perawat_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.treatment_detail_id' => ['required', 'integer'],
            'items.*.master_treatment_perawat_bahan_id' => ['required', 'integer'],
            'items.*.perawat_bahan_id' => ['required', 'integer'],
            'items.*.jumlah_terpakai' => ['required', 'numeric', 'min:0'],
        ], [
            'tanggal_pengisian.required' => 'Tanggal pengisian wajib diisi.',
            'tanggal_pengisian.date' => 'Format tanggal pengisian tidak valid.',
            'perawat_id.required' => 'Perawat penanggung jawab wajib dipilih.',
            'items.required' => 'Daftar bahan treatment wajib dikirim.',
            'items.array' => 'Format bahan treatment tidak valid.',
            'items.min' => 'Minimal satu bahan treatment wajib dikirim.',
            'items.*.treatment_detail_id.required' => 'Treatment detail bahan tidak valid.',
            'items.*.master_treatment_perawat_bahan_id.required' => 'Template bahan treatment tidak valid.',
            'items.*.perawat_bahan_id.required' => 'Bahan treatment tidak valid.',
            'items.*.jumlah_terpakai.required' => 'Jumlah terpakai wajib diisi.',
            'items.*.jumlah_terpakai.numeric' => 'Jumlah terpakai harus berupa angka.',
            'items.*.jumlah_terpakai.min' => 'Jumlah terpakai tidak boleh minus.',
        ]);

        $inputItems = collect($validated['items'])
            ->map(function ($item) {
                return [
                    'id' => isset($item['id']) ? (int) $item['id'] : null,
                    'treatment_detail_id' => (int) $item['treatment_detail_id'],
                    'master_treatment_perawat_bahan_id' => (int) $item['master_treatment_perawat_bahan_id'],
                    'perawat_bahan_id' => (int) $item['perawat_bahan_id'],
                    'jumlah_terpakai' => (float) $item['jumlah_terpakai'],
                ];
            })
            ->values();

        if ($inputItems->where('jumlah_terpakai', '>', 0)->count() < 1) {
            throw ValidationException::withMessages([
                'items' => ['Isi minimal satu jumlah bahan yang benar-benar terpakai.'],
            ]);
        }

        $data = DB::transaction(function () use ($id, $validated, $inputItems) {
            $registrasi = $this->getRegistrasi($id, true);
            $this->assertCanOpen($registrasi);

            $task = $this->getNurseTask($registrasi, true);

            if (!$task) {
                throw ValidationException::withMessages([
                    'registrasi' => ['Task tindakan perawat tidak ditemukan.'],
                ]);
            }

            $username = $this->username();
            $now = now();

            if (in_array((int) $task->status, [
                RegistrasiTask::STATUS_SELESAI,
                RegistrasiTask::STATUS_BATAL,
            ], true)) {
                throw ValidationException::withMessages([
                    'registrasi' => ['Bahan treatment tidak dapat diubah karena task perawat sudah selesai atau dibatalkan.'],
                ]);
            }

            $templates = $this->getValidTemplateMap($registrasi);

            if ($templates->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Template bahan treatment belum tersedia untuk treatment pada registrasi ini.'],
                ]);
            }

            foreach ($inputItems as $item) {
                $key = $this->templateKey(
                    $item['treatment_detail_id'],
                    $item['master_treatment_perawat_bahan_id'],
                    $item['perawat_bahan_id']
                );

                if (!$templates->has($key)) {
                    throw ValidationException::withMessages([
                        'items' => ['Salah satu bahan tidak sesuai dengan treatment registrasi ini.'],
                    ]);
                }

                $template = $templates->get($key);

                if ($item['id']) {
                    $existingById = RegistrasiPerawatBahanTreatmentDetail::query()
                        ->where('registrasi_id', $registrasi->id)
                        ->where(function ($query) {
                            $query->whereNull('is_delete')->orWhere('is_delete', 0);
                        })
                        ->where('id', $item['id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$existingById) {
                        throw ValidationException::withMessages([
                            'items' => ['Salah satu detail bahan tidak ditemukan pada registrasi ini.'],
                        ]);
                    }
                }

                $detail = RegistrasiPerawatBahanTreatmentDetail::query()
                    ->where('registrasi_id', $registrasi->id)
                    ->where('treatment_detail_id', $template['treatment_detail_id'])
                    ->where('master_treatment_perawat_bahan_id', $template['master_treatment_perawat_bahan_id'])
                    ->where('perawat_bahan_id', $template['perawat_bahan_id'])
                    ->where(function ($query) {
                        $query->whereNull('is_delete')->orWhere('is_delete', 0);
                    })
                    ->lockForUpdate()
                    ->first();

                $payload = [
                    'registrasi_id' => $registrasi->id,
                    'task_id' => $task->id,
                    'treatment_detail_id' => $template['treatment_detail_id'],
                    'master_treatment_perawat_bahan_id' => $template['master_treatment_perawat_bahan_id'],
                    'treatment_id' => $template['treatment_id'],
                    'perawat_bahan_id' => $template['perawat_bahan_id'],
                    'nama_bahan' => $template['nama_bahan'],
                    'jumlah_default' => $template['jumlah_default'],
                    'jumlah_terpakai' => $item['jumlah_terpakai'],
                    'satuan' => $template['satuan'],
                    'tanggal_pengisian' => $validated['tanggal_pengisian'],
                    'toko_id' => $registrasi->toko_id,
                    'perawat_id' => (int) $validated['perawat_id'],
                    'status' => $item['jumlah_terpakai'] > 0
                        ? RegistrasiPerawatBahanTreatmentDetail::STATUS_SUDAH_DIISI
                        : RegistrasiPerawatBahanTreatmentDetail::STATUS_BELUM_DIISI,
                    'is_delete' => 0,
                    'updated_by' => $username,
                    'updated_at' => $now,
                ];

                if ($detail) {
                    $detail->fill($payload);
                    $detail->save();
                } else {
                    RegistrasiPerawatBahanTreatmentDetail::query()->create([
                        ...$payload,
                        'created_by' => $username,
                        'created_at' => $now,
                    ]);
                }
            }

            $registrasi = $this->getRegistrasi($registrasi->id);
            $task = $this->getNurseTask($registrasi);

            return $this->buildResponseData($registrasi, $task);
        });

        return response()->json([
            'status' => true,
            'message' => 'Bahan treatment berhasil disimpan.',
            'data' => $data,
        ]);
    }

    private function getRegistrasi($id, bool $lock = false): RegistrasiKunjungan
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'treatmentDetails' => function ($q) {
                    $q->where(function ($query) {
                        $query->whereNull('is_delete')->orWhere('is_delete', 0);
                    })->orderBy('id');
                },
                'bahanTreatmentDetails' => function ($q) {
                    $q->where(function ($query) {
                        $query->whereNull('is_delete')->orWhere('is_delete', 0);
                    })->orderBy('treatment_detail_id')->orderBy('id');
                },
            ])
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function assertCanOpen(RegistrasiKunjungan $registrasi): void
    {
        if ((int) $registrasi->is_treatment !== 1) {
            throw ValidationException::withMessages([
                'registrasi' => ['Registrasi ini tidak memiliki layanan treatment.'],
            ]);
        }

        $task = $this->getNurseTask($registrasi);

        if (!$task) {
            throw ValidationException::withMessages([
                'registrasi' => ['Task tindakan perawat tidak ditemukan.'],
            ]);
        }

        $paymentTask = $this->getPaymentTask($registrasi);

        if (!$paymentTask || (int) $paymentTask->status !== RegistrasiTask::STATUS_SELESAI) {
            throw ValidationException::withMessages([
                'registrasi' => ['Bahan treatment hanya dapat diisi setelah pembayaran selesai.'],
            ]);
        }
    }

    private function getNurseTask(RegistrasiKunjungan $registrasi, bool $lock = false)
    {
        if (!$lock && $registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
                ->sortBy('task_order')
                ->first();
        }

        $query = RegistrasiTask::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->orderBy('task_order');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function getPaymentTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                ->sortBy('task_order')
                ->first();
        }

        return RegistrasiTask::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->orderBy('task_order')
            ->first();
    }

    private function buildResponseData(RegistrasiKunjungan $registrasi, $task): array
    {
        $existingMap = $registrasi->bahanTreatmentDetails
            ? $registrasi->bahanTreatmentDetails->keyBy(function ($detail) {
                return $this->templateKey(
                    (int) $detail->treatment_detail_id,
                    (int) $detail->master_treatment_perawat_bahan_id,
                    (int) $detail->perawat_bahan_id
                );
            })
            : collect();

        $treatments = $registrasi->treatmentDetails
            ->map(function (RegistrasiTreatmentDetail $treatmentDetail) use ($existingMap) {
                $templates = $this->getTemplatesForTreatmentDetail($treatmentDetail);

                $items = $templates->map(function ($template) use ($existingMap) {
                    $key = $this->templateKey(
                        $template['treatment_detail_id'],
                        $template['master_treatment_perawat_bahan_id'],
                        $template['perawat_bahan_id']
                    );

                    $existing = $existingMap->get($key);

                    return [
                        'id' => $existing?->id,
                        'treatment_detail_id' => $template['treatment_detail_id'],
                        'master_treatment_perawat_bahan_id' => $template['master_treatment_perawat_bahan_id'],
                        'treatment_id' => $template['treatment_id'],
                        'perawat_bahan_id' => $template['perawat_bahan_id'],
                        'nama_bahan' => $existing?->nama_bahan ?? $template['nama_bahan'],
                        'jumlah_default' => (float) ($existing?->jumlah_default ?? $template['jumlah_default']),
                        'jumlah_terpakai' => $existing?->jumlah_terpakai !== null
                            ? (float) $existing->jumlah_terpakai
                            : (float) $template['jumlah_default'],
                        'satuan' => $existing?->satuan ?? $template['satuan'],
                        'status' => (int) ($existing?->status ?? RegistrasiPerawatBahanTreatmentDetail::STATUS_BELUM_DIISI),
                    ];
                })->values();

                return [
                    'id' => (int) $treatmentDetail->id,
                    'treatment_detail_id' => (int) $treatmentDetail->id,
                    'treatment_id' => (int) $treatmentDetail->treatment_id,
                    'nama' => $treatmentDetail->nama_treatment,
                    'jumlah' => (int) max(1, $treatmentDetail->jumlah ?? 1),
                    'items' => $items,
                ];
            })
            ->filter(fn ($treatment) => $treatment['items']->count() > 0)
            ->values();

        $totalBahan = $treatments->sum(fn ($treatment) => $treatment['items']->count());
        $totalTerisi = $treatments->sum(function ($treatment) {
            return $treatment['items']->filter(fn ($item) => (float) $item['jumlah_terpakai'] > 0)->count();
        });

        return [
            'summary' => [
                'registrasi_id' => (int) $registrasi->id,
                'task_id' => $task?->id,
                'task_status' => $task?->status,
                'kode_registrasi' => $registrasi->kode_registrasi,
                'pasien' => $registrasi->pasien?->nama ?? '-',
                'no_rm' => $registrasi->pasien?->no_rm ?? '-',
                'cabang' => $registrasi->toko?->nama ?? '-',
                'tanggal_kunjungan' => optional($registrasi->tanggal_kunjungan)->format('Y-m-d') ?: (string) $registrasi->tanggal_kunjungan,
                'channel' => $registrasi->channel_konsultasi_label ?? '-',
                'perawat_id' => $registrasi->perawat_awal_id,
                'perawat' => $registrasi->perawatAwal?->nama ?? '-',
                'total_treatment' => $treatments->count(),
                'total_bahan' => $totalBahan,
                'total_terisi' => $totalTerisi,
                'status' => $totalBahan > 0 && $totalTerisi >= $totalBahan ? 'Lengkap' : ($totalTerisi > 0 ? 'Sebagian' : 'Draft'),
                'can_edit' => $task && !in_array((int) $task->status, [
                    RegistrasiTask::STATUS_SELESAI,
                    RegistrasiTask::STATUS_BATAL,
                ], true),
            ],
            'treatments' => $treatments,
        ];
    }

    private function getValidTemplateMap(RegistrasiKunjungan $registrasi)
    {
        return $registrasi->treatmentDetails
            ->flatMap(fn (RegistrasiTreatmentDetail $detail) => $this->getTemplatesForTreatmentDetail($detail))
            ->keyBy(fn ($item) => $this->templateKey(
                $item['treatment_detail_id'],
                $item['master_treatment_perawat_bahan_id'],
                $item['perawat_bahan_id']
            ));
    }

    private function getTemplatesForTreatmentDetail(RegistrasiTreatmentDetail $treatmentDetail)
    {
        $jumlahTreatment = max(1, (int) ($treatmentDetail->jumlah ?? 1));

        return DB::table('master_treatment_perawat_bahan as mtpb')
            ->leftJoin('master_perawat_bahan as mpb', 'mpb.id', '=', 'mtpb.perawat_bahan_id')
            ->where('mtpb.treatment_id', $treatmentDetail->treatment_id)
            ->where('mtpb.is_active', 1)
            ->where('mtpb.is_delete', 0)
            ->where(function ($query) {
                $query->whereNull('mpb.is_delete')->orWhere('mpb.is_delete', 0);
            })
            ->where(function ($query) {
                $query->whereNull('mpb.is_active')->orWhere('mpb.is_active', 1);
            })
            ->orderBy('mtpb.id')
            ->get([
                'mtpb.id as master_treatment_perawat_bahan_id',
                'mtpb.treatment_id',
                'mtpb.perawat_bahan_id',
                'mtpb.jumlah_default',
                'mtpb.satuan as template_satuan',
                'mpb.nama_bahan',
                'mpb.satuan as master_satuan',
            ])
            ->map(function ($row) use ($treatmentDetail, $jumlahTreatment) {
                $namaBahan = trim((string) ($row->nama_bahan ?? ''));

                return [
                    'treatment_detail_id' => (int) $treatmentDetail->id,
                    'master_treatment_perawat_bahan_id' => (int) $row->master_treatment_perawat_bahan_id,
                    'treatment_id' => (int) $row->treatment_id,
                    'perawat_bahan_id' => (int) $row->perawat_bahan_id,
                    'nama_bahan' => $namaBahan !== '' ? $namaBahan : 'Bahan #' . $row->perawat_bahan_id,
                    'jumlah_default' => (float) $row->jumlah_default * $jumlahTreatment,
                    'satuan' => $row->template_satuan ?: $row->master_satuan,
                ];
            });
    }

    private function templateKey(int $treatmentDetailId, int $masterTreatmentPerawatBahanId, int $perawatBahanId): string
    {
        return $treatmentDetailId . ':' . $masterTreatmentPerawatBahanId . ':' . $perawatBahanId;
    }

    private function username(): string
    {
        $user = auth()->user();

        return $user?->username
            ?? $user?->name
            ?? 'system';
    }
}
