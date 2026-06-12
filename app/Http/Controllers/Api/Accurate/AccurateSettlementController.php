<?php

namespace App\Http\Controllers\Api\Accurate;

use App\Http\Controllers\Controller;
use App\Services\Accurate\AccurateDepositRealizationSettlementService;
use App\Services\Accurate\AccurateDepositSettlementService;
use App\Services\Accurate\AccurateSettlementService;
use App\Services\Accurate\AccurateStoSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AccurateSettlementController extends Controller
{
    public function __construct(
        private readonly AccurateSettlementService $settlementService,
        private readonly AccurateDepositSettlementService $depositSettlementService,
        private readonly AccurateDepositRealizationSettlementService $depositRealizationSettlementService,
        private readonly AccurateStoSettlementService $stoSettlementService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $data = $this->settlementService->listUmum(
                $this->settlementFilters($request)
            );

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
        $validator = $this->dailyUploadValidator(
            $request,
            'Validasi upload faktur Accurate gagal.'
        );

        if ($validator instanceof JsonResponse) {
            return $validator;
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

    public function eliteGlowbalIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->settlementService->listEliteGlowbal(
                $this->settlementFilters($request)
            );

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
        $validator = $this->dailyUploadValidator(
            $request,
            'Validasi upload faktur EliteGlowbal gagal.'
        );

        if ($validator instanceof JsonResponse) {
            return $validator;
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
                'message' => 'Upload faktur EliteGlowbal Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function ownerIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->settlementService->listOwner(
                $this->settlementFilters($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Data settlement Accurate Owner berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data settlement Accurate Owner.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function ownerUpload(Request $request): JsonResponse
    {
        $validator = $this->dailyUploadValidator(
            $request,
            'Validasi upload faktur Owner gagal.'
        );

        if ($validator instanceof JsonResponse) {
            return $validator;
        }

        try {
            $row = $this->settlementService->uploadOwner(
                $request->input('tanggal_faktur'),
                (int) $request->input('toko_id'),
                $request->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Faktur Owner berhasil diupload ke Accurate.',
                'data' => $row,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Upload faktur Owner Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function depositIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->depositSettlementService->list(
                $this->settlementFilters($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Data faktur deposit Accurate berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data faktur deposit Accurate.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function depositUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pembayaran_id' => [
                'required',
                'integer',
                'exists:pembayaran_invoice,id',
            ],
        ], [
            'pembayaran_id.required' => 'Invoice deposit wajib dipilih.',
            'pembayaran_id.integer' => 'Invoice deposit tidak valid.',
            'pembayaran_id.exists' => 'Invoice deposit tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi upload faktur deposit gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = $this->depositSettlementService->upload(
                (int) $request->input('pembayaran_id'),
                $request->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Faktur deposit berhasil dikirim ke Accurate.',
                'data' => $row,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Upload faktur deposit Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function depositRealizationIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->depositRealizationSettlementService->list(
                $this->settlementFilters($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Data faktur realisasi deposit Accurate berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data faktur realisasi deposit Accurate.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function depositRealizationUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'deposit_claim_id' => [
                'required',
                'integer',
                'exists:pembayaran_deposit_treatment_claim,id',
            ],
        ], [
            'deposit_claim_id.required' => 'Data realisasi deposit wajib dipilih.',
            'deposit_claim_id.integer' => 'Data realisasi deposit tidak valid.',
            'deposit_claim_id.exists' => 'Data realisasi deposit tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi upload faktur realisasi deposit gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = $this->depositRealizationSettlementService->upload(
                (int) $request->input('deposit_claim_id'),
                $request->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Faktur realisasi deposit berhasil dikirim ke Accurate.',
                'data' => $row,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Upload faktur realisasi deposit Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function stoIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->stoSettlementService->list(
                $this->settlementFilters($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Data STO Accurate berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data STO Accurate.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function stoUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sto_invoice_id' => [
                'required',
                'integer',
                'exists:accurate_sto_invoice,id',
            ],
        ], [
            'sto_invoice_id.required' => 'Data STO wajib dipilih.',
            'sto_invoice_id.integer' => 'Data STO tidak valid.',
            'sto_invoice_id.exists' => 'Data STO tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi upload STO Accurate gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = $this->stoSettlementService->upload(
                (int) $request->input('sto_invoice_id'),
                $request->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Faktur STO berhasil dikirim ke Accurate.',
                'data' => $row,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Upload STO Accurate gagal.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    private function settlementFilters(Request $request): array
    {
        return $request->only([
            'date',
            'start_date',
            'end_date',
            'toko_id',
            'search',
            'page',
            'per_page',
        ]);
    }

    private function dailyUploadValidator(Request $request, string $message): JsonResponse|bool
    {
        $minDate = now()->subDays(7)->toDateString();
        $maxDate = now()->toDateString();

        $validator = Validator::make($request->all(), [
            'tanggal_faktur' => [
                'required',
                'date',
                'after_or_equal:' . $minDate,
                'before_or_equal:' . $maxDate,
            ],
            'toko_id' => [
                'required',
                'integer',
                'exists:master_toko,id',
            ],
        ], [
            'tanggal_faktur.required' => 'Tanggal faktur wajib diisi.',
            'tanggal_faktur.date' => 'Tanggal faktur tidak valid.',
            'tanggal_faktur.after_or_equal' => 'Tanggal faktur minimal H-7 dari hari ini.',
            'tanggal_faktur.before_or_equal' => 'Tanggal faktur tidak boleh melebihi hari ini.',
            'toko_id.required' => 'Cabang wajib diisi.',
            'toko_id.integer' => 'Cabang tidak valid.',
            'toko_id.exists' => 'Cabang tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $message,
                'errors' => $validator->errors(),
            ], 422);
        }

        return true;
    }
}