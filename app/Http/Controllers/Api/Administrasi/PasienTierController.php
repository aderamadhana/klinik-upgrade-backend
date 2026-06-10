<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Services\Administrasi\PasienTierService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class PasienTierController extends Controller
{
    public function __construct(
        protected PasienTierService $pasienTierService,
    ) {
    }

    public function show(int $id)
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Data tier pasien berhasil diambil.',
                'data' => $this->pasienTierService->detail($id),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => false,
                'message' => 'Pasien tidak ditemukan.',
            ], 404);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data tier pasien.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function upgrade(Request $request, int $id)
    {
        return $this->processChange($request, $id, 'upgrade');
    }

    public function downgrade(Request $request, int $id)
    {
        return $this->processChange($request, $id, 'downgrade');
    }

    public function automatic(Request $request, int $id)
    {
        return $this->processChange($request, $id, 'automatic');
    }

    protected function processChange(Request $request, int $id, string $action)
    {
        $validator = Validator::make($request->all(), [
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'alasan.required' => 'Alasan perubahan tier wajib diisi.',
            'alasan.min' => 'Alasan perubahan tier minimal 5 karakter.',
            'alasan.max' => 'Alasan perubahan tier maksimal 500 karakter.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi perubahan tier gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $username = $this->resolveUsername($request);
            $reason = trim((string) $request->input('alasan'));

            $data = match ($action) {
                'upgrade' => $this->pasienTierService->upgrade($id, $reason, $username),
                'downgrade' => $this->pasienTierService->downgrade($id, $reason, $username),
                'automatic' => $this->pasienTierService->resetAutomatic($id, $reason, $username),
                default => throw new DomainException('Aksi perubahan tier tidak valid.'),
            };

            return response()->json([
                'status' => true,
                'message' => match ($action) {
                    'upgrade' => 'Tier pasien berhasil di-upgrade.',
                    'downgrade' => 'Tier pasien berhasil di-downgrade.',
                    default => 'Tier pasien berhasil dikembalikan ke mode otomatis.',
                },
                'data' => $data,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => false,
                'message' => 'Pasien tidak ditemukan.',
            ], 404);
        } catch (DomainException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
                'errors' => ['tier' => [$exception->getMessage()]],
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses perubahan tier pasien.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    protected function resolveUsername(Request $request): string
    {
        $user = $request->user();

        return (string) (
            $user?->username
            ?? $user?->name
            ?? $user?->email
            ?? $user?->id
            ?? 'system'
        );
    }
}
