<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Services\PasienPoinRedeemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasienPoinController extends Controller
{
    public function __construct(
        private readonly PasienPoinRedeemService $service
    ) {
    }

    public function merchandise(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Data reward poin berhasil diambil.',
                'data' => $this->service->merchandise($request->all()),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Gagal mengambil data reward poin.', $e);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Saldo poin pasien berhasil diambil.',
                'data' => $this->service->show($id, $request->all()),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorResponse('Gagal mengambil saldo poin pasien.', $e);
        }
    }

    public function redeem(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate([
            'tanggal' => ['nullable', 'date'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.merchandise_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return response()->json([
                'status' => true,
                'message' => 'Penukaran poin berhasil disimpan.',
                'data' => $this->service->redeem($id, $payload, $request->user()),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorResponse('Gagal menyimpan penukaran poin.', $e);
        }
    }

    public function voidRedeem(Request $request, int $id, int $ledgerId): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            return response()->json([
                'status' => true,
                'message' => 'Penukaran poin berhasil dibatalkan.',
                'data' => $this->service->voidRedeem($id, $ledgerId, $payload, $request->user()),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorResponse('Gagal membatalkan penukaran poin.', $e);
        }
    }

    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }
}