<?php

namespace App\Http\Controllers\Api\PelayananMedis;

use App\Http\Controllers\Controller;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiPerawatBeforeAfterFoto;
use App\Models\Registrasi\RegistrasiTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AntrianPerawatBeforeAfterController extends Controller
{
    private const SLOT_COUNT = 3;
    private const MAX_FILE_KB = 5120;
    private const STORAGE_DISK = 'local';

    public function show(int $id)
    {
        $registrasi = $this->findRegistrasi($id);

        if (!$registrasi) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak ditemukan.',
            ], 404);
        }

        $task = $this->findNurseTask($registrasi->id);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task tindakan perawat tidak ditemukan pada registrasi ini.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Data before dan after berhasil dimuat.',
            'data' => $this->buildResponseData($registrasi, $task),
        ]);
    }

    public function store(Request $request, int $id)
    {
        $registrasi = $this->findRegistrasi($id);

        if (!$registrasi) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak ditemukan.',
            ], 404);
        }

        $task = $this->findNurseTask($registrasi->id);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task tindakan perawat tidak ditemukan pada registrasi ini.',
            ], 422);
        }

        $request->validate($this->uploadRules(), $this->uploadMessages());

        $newFiles = [];
        $oldFiles = [];

        DB::beginTransaction();

        try {
            $lockedRegistrasi = RegistrasiKunjungan::query()
                ->whereKey($registrasi->id)
                ->active()
                ->lockForUpdate()
                ->first();

            $lockedTask = RegistrasiTask::query()
                ->whereKey($task->id)
                ->where('registrasi_id', $registrasi->id)
                ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
                ->where(function ($query) {
                    $query->where('is_delete', 0)->orWhereNull('is_delete');
                })
                ->lockForUpdate()
                ->first();

            if (!$lockedRegistrasi || !$lockedTask) {
                throw ValidationException::withMessages([
                    'registrasi' => 'Data registrasi atau task perawat sudah tidak tersedia.',
                ]);
            }

            $username = $this->authenticatedUsername();
            $now = now();

            if ((int) $lockedTask->status === RegistrasiTask::STATUS_MENUNGGU) {
                $lockedTask->update([
                    'status' => RegistrasiTask::STATUS_PROSES,
                    'started_at' => $lockedTask->started_at ?: $now,
                    'updated_by' => $username,
                    'updated_at' => $now,
                ]);
            }

            $this->validateEditableState($lockedRegistrasi, $lockedTask);

            $existingPhotos = RegistrasiPerawatBeforeAfterFoto::query()
                ->where('registrasi_id', $lockedRegistrasi->id)
                ->where('task_id', $lockedTask->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($photo) => $this->slotKey($photo->tipe_foto, $photo->urutan));

            $this->validateCompleteSubmission($request, $existingPhotos);

            foreach ($this->slotDefinitions() as $slot) {
                $inputKey = $slot['input_key'];

                if (!$request->hasFile($inputKey)) {
                    continue;
                }

                $uploadedFile = $request->file($inputKey);
                $slotKey = $this->slotKey($slot['type'], $slot['order']);
                $existingPhoto = $existingPhotos->get($slotKey);

                $directory = sprintf(
                    'pelayanan-medis/antrian-perawat/%d/before-after/%d',
                    $lockedRegistrasi->id,
                    $lockedTask->id,
                );

                $extension = strtolower($uploadedFile->extension() ?: 'jpg');
                $storedName = sprintf(
                    '%s_%d_%s.%s',
                    $slot['type'],
                    $slot['order'],
                    Str::uuid(),
                    $extension,
                );

                $storedPath = $uploadedFile->storeAs(
                    $directory,
                    $storedName,
                    self::STORAGE_DISK,
                );

                if (!$storedPath) {
                    throw new \RuntimeException('File gagal disimpan ke storage.');
                }

                $newFiles[] = [
                    'disk' => self::STORAGE_DISK,
                    'path' => $storedPath,
                ];

                if ($existingPhoto && $existingPhoto->file_path) {
                    $oldDisk = $this->detectDisk($existingPhoto->file_path);

                    if ($oldDisk) {
                        $oldFiles[] = [
                            'disk' => $oldDisk,
                            'path' => $existingPhoto->file_path,
                        ];
                    }
                }

                $photo = $existingPhoto ?: new RegistrasiPerawatBeforeAfterFoto();
                $photo->registrasi_id = $lockedRegistrasi->id;
                $photo->task_id = $lockedTask->id;
                $photo->treatment_detail_id = null;
                $photo->tipe_foto = $slot['type'];
                $photo->urutan = $slot['order'];
                $photo->file_name = Str::limit($uploadedFile->getClientOriginalName(), 255, '');
                $photo->file_path = $storedPath;
                $photo->tanggal_upload = now();
                $photo->uploaded_by = $this->authenticatedUserId();
                $photo->is_delete = 0;
                $photo->updated_by = $username;

                if (!$photo->exists) {
                    $photo->created_by = $username;
                }

                $photo->save();

                $photo->file_url = sprintf(
                    '/api/pelayanan-medis/antrian-perawat/%d/before-after/photo/%d',
                    $lockedRegistrasi->id,
                    $photo->id,
                );
                $photo->save();
            }

            $this->assertCompletePhotoSet($lockedRegistrasi->id, $lockedTask->id);
            $this->syncNurseTaskProgress($lockedRegistrasi, $lockedTask, $now, $username);

            DB::commit();

            $this->deleteFiles($oldFiles, $newFiles);

            $lockedTask->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Foto before dan after berhasil disimpan.',
                'data' => $this->buildResponseData($lockedRegistrasi, $lockedTask),
            ]);
        } catch (ValidationException $exception) {
            DB::rollBack();
            $this->deleteFiles($newFiles);
            throw $exception;
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->deleteFiles($newFiles);
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Foto before dan after gagal disimpan.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function photo(int $id, int $photoId)
    {
        $registrasi = $this->findRegistrasi($id);

        if (!$registrasi) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak ditemukan.',
            ], 404);
        }

        $task = $this->findNurseTask($registrasi->id);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task tindakan perawat tidak ditemukan.',
            ], 404);
        }

        $photo = RegistrasiPerawatBeforeAfterFoto::query()
            ->active()
            ->whereKey($photoId)
            ->where('registrasi_id', $registrasi->id)
            ->where('task_id', $task->id)
            ->first();

        if (!$photo || !$photo->file_path) {
            return response()->json([
                'status' => false,
                'message' => 'Foto tidak ditemukan.',
            ], 404);
        }

        $disk = $this->detectDisk($photo->file_path);

        if (!$disk) {
            return response()->json([
                'status' => false,
                'message' => 'File foto tidak ditemukan pada storage.',
            ], 404);
        }

        return Storage::disk($disk)->response(
            $photo->file_path,
            $photo->file_name ?: basename($photo->file_path),
            [
                'Cache-Control' => 'private, max-age=300, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function findRegistrasi(int $id): ?RegistrasiKunjungan
    {
        return RegistrasiKunjungan::query()
            ->active()
            ->find($id);
    }

    private function findNurseTask(int $registrasiId): ?RegistrasiTask
    {
        return RegistrasiTask::query()
            ->where('registrasi_id', $registrasiId)
            ->where('task_type', RegistrasiTask::TYPE_TINDAKAN_PERAWAT)
            ->where(function ($query) {
                $query->where('is_delete', 0)->orWhereNull('is_delete');
            })
            ->orderBy('task_order')
            ->orderBy('id')
            ->first();
    }

    private function validateEditableState(
        RegistrasiKunjungan $registrasi,
        RegistrasiTask $task,
    ): void {
        if ((int) $task->status === RegistrasiTask::STATUS_SELESAI) {
            throw ValidationException::withMessages([
                'task' => 'Task tindakan perawat sudah selesai dan tidak dapat diubah.',
            ]);
        }

        if ((int) $task->status === RegistrasiTask::STATUS_BATAL) {
            throw ValidationException::withMessages([
                'task' => 'Task tindakan perawat sudah dibatalkan.',
            ]);
        }

        if ((int) $task->status !== RegistrasiTask::STATUS_PROSES) {
            throw ValidationException::withMessages([
                'task' => 'Status task tindakan perawat tidak valid untuk proses upload.',
            ]);
        }

        if ((int) $registrasi->current_task !== RegistrasiTask::TYPE_TINDAKAN_PERAWAT) {
            throw ValidationException::withMessages([
                'registrasi' => 'Registrasi tidak sedang berada pada tahap tindakan perawat.',
            ]);
        }
    }

    private function validateCompleteSubmission(Request $request, $existingPhotos): void
    {
        $errors = [];

        foreach ($this->slotDefinitions() as $slot) {
            $slotKey = $this->slotKey($slot['type'], $slot['order']);
            $existingPhoto = $existingPhotos->get($slotKey);
            $hasActiveExistingPhoto = $existingPhoto
                && (int) $existingPhoto->is_delete === 0
                && !empty($existingPhoto->file_path);

            if (!$request->hasFile($slot['input_key']) && !$hasActiveExistingPhoto) {
                $errors[$slot['input_key']] = sprintf(
                    'Foto %s %d wajib diunggah.',
                    ucfirst($slot['type']),
                    $slot['order'],
                );
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertCompletePhotoSet(int $registrasiId, int $taskId): void
    {
        $photos = RegistrasiPerawatBeforeAfterFoto::query()
            ->active()
            ->where('registrasi_id', $registrasiId)
            ->where('task_id', $taskId)
            ->whereIn('tipe_foto', [
                RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE,
                RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER,
            ])
            ->whereBetween('urutan', [1, self::SLOT_COUNT])
            ->get(['tipe_foto', 'urutan']);

        $beforeCount = $photos
            ->where('tipe_foto', RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE)
            ->pluck('urutan')
            ->unique()
            ->count();

        $afterCount = $photos
            ->where('tipe_foto', RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER)
            ->pluck('urutan')
            ->unique()
            ->count();

        if ($beforeCount !== self::SLOT_COUNT || $afterCount !== self::SLOT_COUNT) {
            throw ValidationException::withMessages([
                'photos' => 'Foto before dan after harus lengkap masing-masing 3 foto.',
            ]);
        }
    }


    private function syncNurseTaskProgress(
        RegistrasiKunjungan $registrasi,
        RegistrasiTask $task,
        $now,
        string $username
    ): void {
        $cpptDone = $this->hasFinalCppt($registrasi->id, $task->id);
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

    private function hasFinalCppt(int $registrasiId, int $taskId): bool
    {
        return DB::table('registrasi_perawat_cppt')
            ->where('registrasi_id', $registrasiId)
            ->where('task_id', $taskId)
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where('status', 1)
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
            ->whereIn('tipe_foto', [
                RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE,
                RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER,
            ])
            ->whereBetween('urutan', [1, self::SLOT_COUNT])
            ->get();

        return $photos->where('tipe_foto', RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE)->pluck('urutan')->unique()->count() >= self::SLOT_COUNT
            && $photos->where('tipe_foto', RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER)->pluck('urutan')->unique()->count() >= self::SLOT_COUNT;
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

    private function buildResponseData(
        RegistrasiKunjungan $registrasi,
        RegistrasiTask $task,
    ): array {
        $photos = RegistrasiPerawatBeforeAfterFoto::query()
            ->active()
            ->where('registrasi_id', $registrasi->id)
            ->where('task_id', $task->id)
            ->whereIn('tipe_foto', [
                RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE,
                RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER,
            ])
            ->whereBetween('urutan', [1, self::SLOT_COUNT])
            ->orderBy('tipe_foto')
            ->orderBy('urutan')
            ->get();

        $before = $photos
            ->where('tipe_foto', RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE)
            ->sortBy('urutan')
            ->values()
            ->map(fn ($photo) => $this->serializePhoto($registrasi->id, $photo))
            ->all();

        $after = $photos
            ->where('tipe_foto', RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER)
            ->sortBy('urutan')
            ->values()
            ->map(fn ($photo) => $this->serializePhoto($registrasi->id, $photo))
            ->all();

        $isComplete = count($before) === self::SLOT_COUNT
            && count($after) === self::SLOT_COUNT;

        return [
            'registrasi_id' => (int) $registrasi->id,
            'task_id' => (int) $task->id,
            'task_status' => (int) $task->status,
            'editable' => (int) $registrasi->current_task === RegistrasiTask::TYPE_TINDAKAN_PERAWAT
                && (int) $task->status === RegistrasiTask::STATUS_PROSES,
            'required_per_type' => self::SLOT_COUNT,
            'required_total' => self::SLOT_COUNT * 2,
            'uploaded_count' => count($before) + count($after),
            'is_complete' => $isComplete,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function serializePhoto(int $registrasiId, RegistrasiPerawatBeforeAfterFoto $photo): array
    {
        $downloadUrl = sprintf(
            '/api/pelayanan-medis/antrian-perawat/%d/before-after/photo/%d',
            $registrasiId,
            $photo->id,
        );

        return [
            'id' => (int) $photo->id,
            'registrasi_id' => (int) $photo->registrasi_id,
            'task_id' => (int) $photo->task_id,
            'treatment_detail_id' => $photo->treatment_detail_id
                ? (int) $photo->treatment_detail_id
                : null,
            'tipe_foto' => $photo->tipe_foto,
            'urutan' => (int) $photo->urutan,
            'file_name' => $photo->file_name,
            'file_url' => $downloadUrl,
            'tanggal_upload' => optional($photo->tanggal_upload)->toISOString(),
            'uploaded_by' => $photo->uploaded_by ? (int) $photo->uploaded_by : null,
        ];
    }

    private function uploadRules(): array
    {
        $rules = [];

        foreach ($this->slotDefinitions() as $slot) {
            $rules[$slot['input_key']] = [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_FILE_KB,
            ];
        }

        return $rules;
    }

    private function uploadMessages(): array
    {
        $messages = [];

        foreach ($this->slotDefinitions() as $slot) {
            $label = sprintf('%s %d', ucfirst($slot['type']), $slot['order']);
            $inputKey = $slot['input_key'];

            $messages["{$inputKey}.image"] = "Foto {$label} harus berupa gambar.";
            $messages["{$inputKey}.mimes"] = "Foto {$label} harus berformat JPG, JPEG, PNG, atau WEBP.";
            $messages["{$inputKey}.max"] = "Ukuran foto {$label} maksimal 5 MB.";
        }

        return $messages;
    }

    private function slotDefinitions(): array
    {
        $slots = [];

        foreach ([
            RegistrasiPerawatBeforeAfterFoto::TIPE_BEFORE,
            RegistrasiPerawatBeforeAfterFoto::TIPE_AFTER,
        ] as $type) {
            for ($order = 1; $order <= self::SLOT_COUNT; $order++) {
                $slots[] = [
                    'type' => $type,
                    'order' => $order,
                    'input_key' => "{$type}_{$order}",
                ];
            }
        }

        return $slots;
    }

    private function slotKey(string $type, int $order): string
    {
        return "{$type}-{$order}";
    }

    private function detectDisk(string $path): ?string
    {
        foreach ([self::STORAGE_DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    private function deleteFiles(array $files, array $exceptFiles = []): void
    {
        $exceptions = collect($exceptFiles)
            ->map(fn ($file) => ($file['disk'] ?? '') . ':' . ($file['path'] ?? ''))
            ->filter()
            ->all();

        foreach ($files as $file) {
            $disk = $file['disk'] ?? null;
            $path = $file['path'] ?? null;
            $key = "{$disk}:{$path}";

            if (!$disk || !$path || in_array($key, $exceptions, true)) {
                continue;
            }

            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function authenticatedUsername(): string
    {
        $user = auth()->user();

        return Str::limit((string) ($user?->username ?? $user?->name ?? 'system'), 100, '');
    }

    private function authenticatedUserId(): ?int
    {
        $id = auth()->id();

        return $id ? (int) $id : null;
    }
}
