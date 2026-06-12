<?php

namespace App\Http\Controllers\Api\Accurate;

use App\Http\Controllers\Controller;
use App\Services\Accurate\AccurateSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AccurateSettlementController extends Controller
{
    public function __construct(
        private readonly AccurateSettlementService $settlementService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $data = $this->settlementService->listUmum($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Data settlement Accurate umum berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data settlement Accurate umum.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tanggal_faktur' => ['required', 'date'],
            'toko_id' => ['required', 'integer', 'exists:master_toko,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi upload faktur umum gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = $this->settlementService->uploadUmum(
                $request->input('tanggal_faktur'),
                (int) $request->input('toko_id'),
                $request->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Faktur umum berhasil diupload ke Accurate.',
                'data' => $row,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Upload faktur umum ke Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function eliteGlowbalIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->settlementService->listEliteGlowbal($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Data settlement Accurate EliteGlowbal berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data settlement Accurate EliteGlowbal.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function eliteGlowbalUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tanggal_faktur' => ['required', 'date'],
            'toko_id' => ['required', 'integer', 'exists:master_toko,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi upload faktur EliteGlowbal gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = $this->settlementService->uploadEliteGlowbal(
                $request->input('tanggal_faktur'),
                (int) $request->input('toko_id'),
                $request->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Faktur EliteGlowbal berhasil diupload ke Accurate.',
                'data' => $row,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Upload faktur EliteGlowbal ke Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
}