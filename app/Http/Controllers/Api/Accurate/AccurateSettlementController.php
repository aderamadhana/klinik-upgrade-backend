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
    public function __construct(private readonly AccurateSettlementService $settlementService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->settlementService->listUmum($request->only([
            'date',
            'start_date',
            'end_date',
            'toko_id',
            'search',
            'page',
            'per_page',
        ]));

        return response()->json([
            'status' => true,
            'message' => 'Data settlement Accurate umum berhasil diambil.',
            'data' => $data,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tanggal_faktur' => ['required', 'date'],
            'toko_id' => ['required', 'integer', 'exists:master_toko,id'],
        ], [
            'tanggal_faktur.required' => 'Tanggal faktur wajib diisi.',
            'toko_id.required' => 'Cabang wajib diisi.',
            'toko_id.exists' => 'Cabang tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi upload faktur Accurate gagal.',
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
                'message' => 'Upload faktur umum Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }
}
