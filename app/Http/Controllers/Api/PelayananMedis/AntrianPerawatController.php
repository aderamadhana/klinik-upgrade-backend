<?php

namespace App\Http\Controllers\Api\PelayananMedis;

use App\Http\Controllers\Controller;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AntrianPerawatController extends Controller
{
    public function index(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan',
                'treatmentDetails.treatment',
                'treatmentDetails.treatmentToko',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->where('status', RegistrasiKunjungan::STATUS_AKTIF)
            ->where('current_task', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
            ->where('is_treatment', 1)
            ->whereHas('tasks', function ($q) {
                $q->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                    ->where('status', RegistrasiTask::STATUS_SELESAI);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_kunjungan', $request->tanggal);
        }

        if ($request->filled('status')) {
            $this->applyStatusFilter($query, $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('pasien', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%");
                    })
                    ->orWhereHas('dokterAwal', function ($d) use ($search) {
                        $d->where('nama', 'like', "%{$search}%");
                    })
                    ->orWhereHas('perawatAwal', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%");
                    })
                    ->orWhereHas('treatmentDetails', function ($t) use ($search) {
                        $t->where('nama_treatment', 'like', "%{$search}%");
                    });
            });
        }

        $summaryQuery = clone $query;

        $rows = $query
            ->orderBy('registered_at')
            ->orderBy('id')
            ->paginate((int) $request->get('per_page', 15));

        $items = $rows->getCollection()
            ->map(fn ($row) => $this->formatQueueRow($row))
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data antrian perawat berhasil diambil',
            'rows' => $items,
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'from' => $rows->firstItem(),
            'to' => $rows->lastItem(),
            'summary' => $this->buildSummary($summaryQuery),
        ]);
    }

    public function show($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan',
                'treatmentDetails.treatment',
                'treatmentDetails.treatmentToko',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->findOrFail($id);

        if (!$this->isNurseQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian perawat',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail antrian perawat berhasil diambil',
            'data' => $this->formatQueueRow($registrasi),
        ]);
    }

    public function start($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'tasks',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->findOrFail($id);

        if (!$this->isNurseQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian perawat',
            ], 422);
        }

        if ((int) $registrasi->current_task !== RegistrasiTask::TYPE_TINDAKAN_PERAWAT) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi belum masuk tahap antrian perawat',
            ], 422);
        }

        $paymentTask = $this->getPaymentTask($registrasi);

        if (!$paymentTask || (int) $paymentTask->status !== RegistrasiTask::STATUS_SELESAI) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian perawat hanya bisa diproses setelah pembayaran selesai',
            ], 422);
        }

        $task = $this->getNurseTask($registrasi);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task perawat tidak ditemukan',
            ], 422);
        }

        if ((int) $task->status === RegistrasiTask::STATUS_PROSES) {
            return response()->json([
                'status' => true,
                'message' => 'Antrian perawat sudah dalam proses',
                'data' => $this->formatQueueRow($registrasi),
            ]);
        }

        if ((int) $task->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian perawat tidak bisa diproses',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_PROSES,
                'started_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi->update([
                'current_task' => RegistrasiTask::TYPE_TINDAKAN_PERAWAT,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $registrasi = $registrasi->fresh([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks',
                'treatmentDetails',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Antrian perawat berhasil diproses',
                'data' => $this->formatQueueRow($registrasi),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses antrian perawat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function finish($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'tasks',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->findOrFail($id);

        if (!$this->isNurseQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian perawat',
            ], 422);
        }

        $task = $this->getNurseTask($registrasi);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task perawat tidak ditemukan',
            ], 422);
        }

        if (!in_array((int) $task->status, [
            RegistrasiTask::STATUS_MENUNGGU,
            RegistrasiTask::STATUS_PROSES,
        ], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Task perawat tidak bisa diselesaikan',
            ], 422);
        }

        $cppt = $this->hasCppt($registrasi);
        $beforeAfter = $this->hasBeforeAfter($registrasi);
        $bahanTreatment = $this->hasBahanTreatment($registrasi);

        if (!$cppt || !$beforeAfter || !$bahanTreatment) {
            return response()->json([
                'status' => false,
                'message' => 'CPPT, Before/After, dan Bahan Treatment wajib dilengkapi sebelum menyelesaikan antrian perawat',
                'data' => [
                    'cppt' => $cppt,
                    'before_after' => $beforeAfter,
                    'bahan_treatment' => $bahanTreatment,
                ],
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_SELESAI,
                'finished_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi->update([
                'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                'status' => RegistrasiKunjungan::STATUS_SELESAI,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Antrian perawat berhasil diselesaikan',
                'data' => $this->formatQueueRow($registrasi->fresh([
                    'toko',
                    'pasien',
                    'dokterAwal',
                    'perawatAwal',
                    'tasks',
                    'treatmentDetails',
                    'perawatCppts',
                    'beforeAfterFotos',
                    'bahanTreatmentDetails',
                ])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyelesaikan antrian perawat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'tasks',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->findOrFail($id);

        if (!$this->isNurseQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian perawat',
            ], 422);
        }

        $task = $this->getNurseTask($registrasi);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task perawat tidak ditemukan',
            ], 422);
        }

        if ((int) $task->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena pasien sudah mulai dilayani',
            ], 422);
        }

        if ($this->hasCppt($registrasi) || $this->hasBeforeAfter($registrasi) || $this->hasBahanTreatment($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena data tindakan sudah diinput',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_BATAL,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi->update([
                'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                'status' => RegistrasiKunjungan::STATUS_SELESAI,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Antrian perawat berhasil dihapus dari daftar',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus antrian perawat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function applyStatusFilter($query, $status)
    {
        $taskStatus = $this->mapQueueStatusToTaskStatus($status);

        if ($taskStatus === null) {
            return;
        }

        $query->whereHas('tasks', function ($q) use ($taskStatus) {
            $q->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
                ->where('status', $taskStatus);
        });
    }

    private function buildSummary($query)
    {
        $rows = $query->get();

        $mapped = $rows->map(function ($row) {
            $row->loadMissing([
                'tasks',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ]);

            return $this->formatQueueRow($row);
        });

        return [
            'total' => $mapped->count(),
            'menunggu' => $mapped->where('status_antrian_perawat', 'menunggu')->count(),
            'diproses' => $mapped->where('status_antrian_perawat', 'proses')->count(),
            'selesai' => $mapped->where('status_antrian_perawat', 'selesai')->count(),
        ];
    }

    private function isNurseQueue(RegistrasiKunjungan $registrasi)
    {
        return (int) $registrasi->is_treatment === 1
            && $this->getNurseTask($registrasi) !== null;
    }

    private function getNurseTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
                ->sortBy('task_order')
                ->first();
        }

        return $registrasi->tasks()
            ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
            ->orderBy('task_order')
            ->first();
    }

    private function getPaymentTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                ->sortBy('task_order')
                ->first();
        }

        return $registrasi->tasks()
            ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            ->orderBy('task_order')
            ->first();
    }

    private function formatQueueRow(RegistrasiKunjungan $row)
    {
        $task = $this->getNurseTask($row);

        $cppt = $this->hasCppt($row);
        $beforeAfter = $this->hasBeforeAfter($row);
        $bahanTreatment = $this->hasBahanTreatment($row);

        $row->setAttribute('registrasi_id', $row->id);
        $row->setAttribute('antrian_perawat_id', $row->id);
        $row->setAttribute('queue_id', $row->id);

        $row->setAttribute('nomor_antrian', $row->kode_registrasi);
        $row->setAttribute('no_antrian', $row->kode_registrasi);

        $row->setAttribute('nama_pasien', $row->pasien?->nama);
        $row->setAttribute('no_rm', $row->pasien?->no_rm);
        $row->setAttribute('no_hp', $row->pasien?->no_hp);

        $row->setAttribute('nama_dokter', $row->dokterAwal?->nama);
        $row->setAttribute('nama_perawat', $row->perawatAwal?->nama);

        $row->setAttribute('tanggal_label', $this->formatDate($row->tanggal_kunjungan));
        $row->setAttribute('waktu_kunjungan', $this->formatTime($row->registered_at));

        $row->setAttribute('nama_tindakan', $this->getTreatmentNames($row));
        $row->setAttribute('total_treatment_item', $row->treatmentDetails?->count() ?? 0);

        $row->setAttribute('cppt', $cppt);
        $row->setAttribute('before_after', $beforeAfter);
        $row->setAttribute('bahan_treatment', $bahanTreatment);

        $row->setAttribute('status_task', $task?->status);
        $row->setAttribute('status_antrian_perawat', $this->getQueueStatus($task, $cppt, $beforeAfter, $bahanTreatment));

        $row->setAttribute('can_delete_antrian', $task && (int) $task->status === RegistrasiTask::STATUS_MENUNGGU && !$cppt && !$beforeAfter && !$bahanTreatment);
        $row->setAttribute('can_process_antrian', $task && in_array((int) $task->status, [
            RegistrasiTask::STATUS_MENUNGGU,
            RegistrasiTask::STATUS_PROSES,
        ], true));

        return $row;
    }

    private function getQueueStatus($task, bool $cppt, bool $beforeAfter, bool $bahanTreatment)
    {
        if ($task && (int) $task->status === RegistrasiTask::STATUS_SELESAI) {
            return 'selesai';
        }

        if ($task && (int) $task->status === RegistrasiTask::STATUS_PROSES) {
            return 'proses';
        }

        if ($cppt || $beforeAfter || $bahanTreatment) {
            return 'proses';
        }

        return 'menunggu';
    }

    private function getTreatmentNames(RegistrasiKunjungan $row)
    {
        if (!$row->relationLoaded('treatmentDetails') || !$row->treatmentDetails) {
            return '-';
        }

        $names = $row->treatmentDetails
            ->filter(function ($item) {
                return !isset($item->is_delete) || (int) $item->is_delete === 0;
            })
            ->map(function ($item) {
                return $item->nama_treatment
                    ?? $item->treatment?->nama
                    ?? $item->treatmentToko?->nama_treatment
                    ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        return $names->count() ? $names->implode(', ') : '-';
    }

    private function hasCppt(RegistrasiKunjungan $row)
    {
        if (!$row->relationLoaded('perawatCppts')) {
            return false;
        }

        return $this->hasActiveCollection($row->perawatCppts);
    }

    private function hasBeforeAfter(RegistrasiKunjungan $row)
    {
        if (!$row->relationLoaded('beforeAfterFotos')) {
            return false;
        }

        return $this->hasActiveCollection($row->beforeAfterFotos);
    }

    private function hasBahanTreatment(RegistrasiKunjungan $row)
    {
        if (!$row->relationLoaded('bahanTreatmentDetails')) {
            return false;
        }

        return $this->hasActiveCollection($row->bahanTreatmentDetails);
    }

    private function hasActiveCollection($collection)
    {
        if (!$collection) {
            return false;
        }

        return $collection
            ->filter(function ($item) {
                return !isset($item->is_delete) || (int) $item->is_delete === 0;
            })
            ->count() > 0;
    }

    private function mapQueueStatusToTaskStatus($status)
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'menunggu' => RegistrasiTask::STATUS_MENUNGGU,
            'proses', 'diproses' => RegistrasiTask::STATUS_PROSES,
            'selesai' => RegistrasiTask::STATUS_SELESAI,
            'batal' => RegistrasiTask::STATUS_BATAL,
            default => null,
        };
    }

    private function formatDate($value)
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatTime($value)
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function username()
    {
        return auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';
    }
}