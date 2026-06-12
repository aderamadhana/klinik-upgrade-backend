<?php

namespace App\Services\Accurate;

use App\Models\Accurate\AccurateDepositRealizationSettlementLog;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AccurateDepositRealizationSettlementService
{
    public function __construct(private readonly AccurateSalesInvoiceClient $client)
    {
    }

    public function list(array $filters = []): array
    {
        [$startDate, $endDate] = $this->resolveRange($filters);

        $tokoId = $this->nullableInt($filters['toko_id'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));

        $rows = $this->queryRows($startDate, $endDate, $tokoId, $search);

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page
        );

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'total_realisasi' => round((float) $rows->sum('total_realisasi'), 2),
                'success_count' => $rows
                    ->where('status_code', AccurateDepositRealizationSettlementLog::STATUS_SUCCESS)
                    ->count(),
                'pending_count' => $rows
                    ->where('status_code', AccurateDepositRealizationSettlementLog::STATUS_PENDING)
                    ->count(),
                'failed_count' => $rows
                    ->where('status_code', AccurateDepositRealizationSettlementLog::STATUS_FAILED)
                    ->count(),
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'toko_id' => $tokoId,
            ],
        ];
    }

    public function upload(int $depositClaimId, ?object $user = null): array
    {
        $userName = $this->userName($user);
        $data = $this->buildUploadData($depositClaimId);

        $log = DB::transaction(function () use ($depositClaimId, $data, $userName) {
            $existingLog = AccurateDepositRealizationSettlementLog::query()
                ->where('deposit_claim_id', $depositClaimId)
                ->lockForUpdate()
                ->first();

            if ($existingLog && $existingLog->isSuccess()) {
                throw new RuntimeException('Faktur realisasi deposit ini sudah berhasil dikirim ke Accurate.');
            }

            $log = $existingLog ?: new AccurateDepositRealizationSettlementLog([
                'deposit_claim_id' => $data['claim']->deposit_claim_id,
                'deposit_treatment_id' => $data['claim']->deposit_treatment_id,
                'pembayaran_deposit_id' => $data['claim']->pembayaran_deposit_id,
                'pembayaran_realisasi_id' => $data['claim']->pembayaran_realisasi_id,
                'pasien_id' => $data['claim']->pasien_id,
                'toko_id' => $data['claim']->toko_id,
                'tanggal_faktur' => $data['claim']->tanggal_faktur,
                'no_faktur_deposit' => $data['claim']->no_faktur_deposit,
                'no_faktur_realisasi' => $data['claim']->no_faktur_realisasi,
                'nama_pasien' => $data['claim']->nama_pasien,
                'nama_treatment' => $data['claim']->nama_treatment,
                'created_by' => $userName,
            ]);

            $log->forceFill([
                'deposit_treatment_id' => $data['claim']->deposit_treatment_id,
                'pembayaran_deposit_id' => $data['claim']->pembayaran_deposit_id,
                'pembayaran_realisasi_id' => $data['claim']->pembayaran_realisasi_id,
                'pasien_id' => $data['claim']->pasien_id,
                'toko_id' => $data['claim']->toko_id,
                'tanggal_faktur' => $data['claim']->tanggal_faktur,
                'no_faktur_deposit' => $data['claim']->no_faktur_deposit,
                'no_faktur_realisasi' => $data['claim']->no_faktur_realisasi,
                'nama_pasien' => $data['claim']->nama_pasien,
                'nama_treatment' => $data['claim']->nama_treatment,
                'total_realisasi' => $data['total_realisasi'],
                'keterangan' => $data['description'],
                'updated_by' => $userName,
            ])->save();

            $log->markProcessing(
                $data['payload'],
                $data['total_realisasi'],
                $userName
            );

            return $log;
        });

        try {
            $response = $this->client->uploadSalesInvoice($data['payload']);
            $accurateNumber = $response['number'] ?? null;

            $message = sprintf(
                'Faktur Penjualan "%s [%s]" berhasil disimpan',
                $accurateNumber ?: '-',
                $data['claim']->no_faktur_realisasi
            );

            $log->markSuccess(
                $accurateNumber,
                $response['payload'] ?? $response,
                $message,
                $userName
            );
        } catch (Throwable $exception) {
            $log->markFailed($exception->getMessage());
            throw $exception;
        }

        return $this->serializeRow(
            $this->rowFromClaim($data['claim'], $log->fresh())
        );
    }

    private function queryRows(
        string $startDate,
        string $endDate,
        ?int $tokoId,
        string $search
    ): Collection {
        $dateExpression = 'DATE(claim.claimed_at)';

        $rows = DB::table('pembayaran_deposit_treatment_claim as claim')
            ->join('pembayaran_deposit_treatment as deposit', 'deposit.id', '=', 'claim.deposit_treatment_id')
            ->leftJoin('pembayaran_invoice as deposit_invoice', 'deposit_invoice.id', '=', 'deposit.pembayaran_id')
            ->leftJoin('pembayaran_invoice as realisasi_invoice', 'realisasi_invoice.id', '=', 'claim.pembayaran_id')
            ->join('pasien as p', 'p.id', '=', 'deposit.pasien_id')
            ->join('master_toko as mt', 'mt.id', '=', 'claim.toko_claim_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'deposit.treatment_id')
            ->leftJoin(
                'accurate_deposit_realization_settlement_log as log',
                'log.deposit_claim_id',
                '=',
                'claim.id'
            )
            ->where('claim.is_delete', 0)
            ->where('claim.status', 1)
            ->where('deposit.is_delete', 0)
            ->where('deposit.status', '!=', 9)
            ->whereBetween(DB::raw($dateExpression), [$startDate, $endDate])
            ->when($tokoId, fn ($query) => $query->where('claim.toko_claim_id', $tokoId))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';

                $query->where(function ($subQuery) use ($like) {
                    $subQuery
                        ->where('p.nama', 'like', $like)
                        ->orWhere('p.no_rm', 'like', $like)
                        ->orWhere('deposit_invoice.no_invoice', 'like', $like)
                        ->orWhere('realisasi_invoice.no_invoice', 'like', $like)
                        ->orWhere('deposit.nama_treatment', 'like', $like)
                        ->orWhere('mt.nama_toko', 'like', $like)
                        ->orWhere('log.faktur_accurate', 'like', $like)
                        ->orWhere('log.keterangan', 'like', $like)
                        ->orWhere('log.uploaded_by', 'like', $like);
                });
            })
            ->select([
                'claim.id as deposit_claim_id',
                'claim.deposit_treatment_id',
                'claim.pembayaran_id as pembayaran_realisasi_id',
                'claim.pembayaran_item_id',
                'claim.toko_claim_id as toko_id',
                'claim.qty_claim',
                'claim.nilai_realisasi',
                'deposit.pembayaran_id as pembayaran_deposit_id',
                'deposit.pasien_id',
                'deposit.treatment_id',
                'deposit.nama_treatment',
                'deposit_invoice.no_invoice as no_faktur_deposit',
                'realisasi_invoice.no_invoice as no_faktur_realisasi_invoice',
                'p.nama as nama_pasien',
                'p.no_rm',
                'mt.nama_toko',
                'treatment.kode_accurate as kode_accurate_treatment',
                'log.id as log_id',
                'log.faktur_accurate',
                'log.total_realisasi as log_total_realisasi',
                'log.status as log_status',
                'log.keterangan',
                'log.error_message',
                'log.uploaded_by',
                'log.uploaded_at',
            ])
            ->selectRaw("{$dateExpression} as tanggal_faktur")
            ->orderByDesc(DB::raw($dateExpression))
            ->orderByDesc('claim.id')
            ->get()
            ->map(function ($row) {
                $row->no_faktur_realisasi = $this->resolveNoFakturRealisasi($row);
                return $row;
            });

        return $rows->map(fn ($row) => $this->serializeRow($row));
    }

    private function buildUploadData(int $depositClaimId): array
    {
        $dateExpression = 'DATE(claim.claimed_at)';

        $claim = DB::table('pembayaran_deposit_treatment_claim as claim')
            ->join('pembayaran_deposit_treatment as deposit', 'deposit.id', '=', 'claim.deposit_treatment_id')
            ->leftJoin('pembayaran_invoice as deposit_invoice', 'deposit_invoice.id', '=', 'deposit.pembayaran_id')
            ->leftJoin('pembayaran_invoice as realisasi_invoice', 'realisasi_invoice.id', '=', 'claim.pembayaran_id')
            ->join('pasien as p', 'p.id', '=', 'deposit.pasien_id')
            ->join('master_toko as mt', 'mt.id', '=', 'claim.toko_claim_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'deposit.treatment_id')
            ->where('claim.id', $depositClaimId)
            ->where('claim.is_delete', 0)
            ->where('claim.status', 1)
            ->where('deposit.is_delete', 0)
            ->where('deposit.status', '!=', 9)
            ->select([
                'claim.id as deposit_claim_id',
                'claim.deposit_treatment_id',
                'claim.pembayaran_id as pembayaran_realisasi_id',
                'claim.pembayaran_item_id',
                'claim.toko_claim_id as toko_id',
                'claim.qty_claim',
                'claim.nilai_realisasi',
                'deposit.pembayaran_id as pembayaran_deposit_id',
                'deposit.pasien_id',
                'deposit.treatment_id',
                'deposit.nama_treatment',
                'deposit_invoice.no_invoice as no_faktur_deposit',
                'realisasi_invoice.no_invoice as no_faktur_realisasi_invoice',
                'p.nama as nama_pasien',
                'p.no_rm',
                'mt.nama_toko',
                'treatment.kode_accurate as kode_accurate_treatment',
            ])
            ->selectRaw("{$dateExpression} as tanggal_faktur")
            ->first();

        if (! $claim) {
            throw new RuntimeException('Data realisasi deposit tidak ditemukan.');
        }

        $claim->no_faktur_realisasi = $this->resolveNoFakturRealisasi($claim);

        $totalRealisasi = round((float) $claim->nilai_realisasi, 2);

        if ($totalRealisasi <= 0) {
            throw new RuntimeException('Total realisasi deposit 0. Faktur tidak perlu dikirim ke Accurate.');
        }

        $line = $this->accurateLine($claim, $totalRealisasi);
        $description = $this->description($claim);

        $payload = [
            'transDate' => Carbon::parse($claim->tanggal_faktur)->format('d/m/Y'),
            'customerNo' => $this->customerNo(),
            'description' => $description,
            'branchName' => (string) env('ACCURATE_BRANCH_NAME', $claim->nama_toko),
            'detailItem' => [$line],
        ];

        $salesmanNo = $this->salesmanNo();

        if ($salesmanNo !== '') {
            $payload['salesmanNo'] = $salesmanNo;
        }

        return [
            'claim' => $claim,
            'total_realisasi' => $totalRealisasi,
            'description' => $description,
            'payload' => $payload,
        ];
    }

    private function accurateLine(object $claim, float $totalRealisasi): array
    {
        $itemNo = trim((string) ($claim->kode_accurate_treatment ?? ''));

        if ($itemNo === '') {
            $itemNo = (string) env(
                'ACCURATE_ITEM_DEPOSIT_REALISASI_NO',
                env('ACCURATE_ITEM_TREATMENT_NO', '')
            );
        }

        if ($itemNo === '') {
            throw new RuntimeException(
                'Kode Accurate treatment/realisasi deposit belum tersedia untuk: ' .
                ($claim->nama_treatment ?: '-')
            );
        }

        return [
            'itemNo' => $itemNo,
            'quantity' => 1,
            'unitPrice' => round($totalRealisasi, 2),
            'detailName' => $claim->nama_treatment ?: 'Realisasi Deposit Treatment',
        ];
    }

    private function serializeRow(object $row): array
    {
        $statusCode = $row->log_status === null
            ? AccurateDepositRealizationSettlementLog::STATUS_PENDING
            : (int) $row->log_status;

        $status = $this->statusMeta($statusCode, $row->error_message ?? null);

        $totalRealisasi = $row->log_total_realisasi !== null
            ? (float) $row->log_total_realisasi
            : (float) $row->nilai_realisasi;

        return [
            'id' => (int) $row->deposit_claim_id,
            'deposit_claim_id' => (int) $row->deposit_claim_id,
            'deposit_treatment_id' => (int) $row->deposit_treatment_id,
            'pembayaran_deposit_id' => (int) $row->pembayaran_deposit_id,
            'pembayaran_realisasi_id' => $row->pembayaran_realisasi_id ? (int) $row->pembayaran_realisasi_id : null,
            'tanggal_faktur' => (string) $row->tanggal_faktur,
            'nama_pasien' => (string) ($row->nama_pasien ?: '-'),
            'no_rm' => (string) ($row->no_rm ?: '-'),
            'no_faktur_deposit' => (string) ($row->no_faktur_deposit ?: '-'),
            'no_faktur_realisasi' => (string) ($row->no_faktur_realisasi ?: '-'),
            'nama_treatment' => (string) ($row->nama_treatment ?: '-'),
            'toko_id' => (int) $row->toko_id,
            'toko_nama' => (string) ($row->nama_toko ?: '-'),
            'faktur' => $row->faktur_accurate ?: '-',
            'total_realisasi' => round($totalRealisasi, 2),
            'status_code' => $statusCode,
            'status_label' => $status['label'],
            'status_color' => $status['color'],
            'status_text' => $status['text'],
            'tanggal_kirim' => $row->uploaded_at
                ? Carbon::parse($row->uploaded_at)->format('Y-m-d H:i:s')
                : '-',
            'nama_pengirim' => $row->uploaded_by ?: '-',
            'keterangan' => $row->keterangan ?: ($row->error_message ?: '-'),
            'error_message' => $row->error_message,
            'can_upload' => $statusCode !== AccurateDepositRealizationSettlementLog::STATUS_SUCCESS
                && round($totalRealisasi, 2) > 0,
        ];
    }

    private function rowFromClaim(
        object $claim,
        AccurateDepositRealizationSettlementLog $log
    ): object {
        return (object) [
            'deposit_claim_id' => $claim->deposit_claim_id,
            'deposit_treatment_id' => $claim->deposit_treatment_id,
            'pembayaran_deposit_id' => $claim->pembayaran_deposit_id,
            'pembayaran_realisasi_id' => $claim->pembayaran_realisasi_id,
            'pasien_id' => $claim->pasien_id,
            'toko_id' => $claim->toko_id,
            'tanggal_faktur' => $claim->tanggal_faktur,
            'no_faktur_deposit' => $claim->no_faktur_deposit,
            'no_faktur_realisasi' => $claim->no_faktur_realisasi,
            'nama_pasien' => $claim->nama_pasien,
            'no_rm' => $claim->no_rm,
            'nama_toko' => $claim->nama_toko,
            'nama_treatment' => $claim->nama_treatment,
            'nilai_realisasi' => $claim->nilai_realisasi,
            'log_id' => $log->id,
            'faktur_accurate' => $log->faktur_accurate,
            'log_total_realisasi' => $log->total_realisasi,
            'log_status' => $log->status,
            'keterangan' => $log->keterangan,
            'error_message' => $log->error_message,
            'uploaded_by' => $log->uploaded_by,
            'uploaded_at' => $log->uploaded_at,
        ];
    }

    private function resolveNoFakturRealisasi(object $row): string
    {
        $invoiceNo = trim((string) ($row->no_faktur_realisasi_invoice ?? ''));

        if ($invoiceNo !== '') {
            return $invoiceNo;
        }

        return trim((string) $row->no_faktur_deposit) . '-' . (int) $row->deposit_claim_id;
    }

    private function statusMeta(int $status, ?string $errorMessage = null): array
    {
        return match ($status) {
            AccurateDepositRealizationSettlementLog::STATUS_SUCCESS => [
                'label' => 'Terkirim',
                'color' => 'success',
                'text' => 'Faktur realisasi deposit berhasil dikirim',
            ],
            AccurateDepositRealizationSettlementLog::STATUS_FAILED => [
                'label' => 'Gagal',
                'color' => 'error',
                'text' => $errorMessage ?: 'Upload gagal',
            ],
            AccurateDepositRealizationSettlementLog::STATUS_PROCESSING => [
                'label' => 'Proses',
                'color' => 'warning',
                'text' => 'Sedang proses upload',
            ],
            default => [
                'label' => 'Belum dikirim',
                'color' => 'blue-grey',
                'text' => 'Belum dikirim ke Accurate',
            ],
        };
    }

    private function description(object $claim): string
    {
        return sprintf(
            'REALISASI DEPOSIT %s %s %s %s',
            strtoupper((string) $claim->nama_toko),
            strtoupper((string) $claim->nama_pasien),
            $claim->no_faktur_realisasi,
            Carbon::parse($claim->tanggal_faktur)->format('d/m/Y')
        );
    }

    private function resolveRange(array $filters): array
    {
        $minimumDate = Carbon::today()->subDays(7)->toDateString();

        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        $date = $filters['date'] ?? null;

        if ($date && ! $start && ! $end) {
            $start = Carbon::parse($date)->toDateString();
            $end = Carbon::parse($date)->toDateString();
        }

        $endDate = $end
            ? Carbon::parse($end)->toDateString()
            : Carbon::today()->toDateString();

        $startDate = $start
            ? Carbon::parse($start)->toDateString()
            : $minimumDate;

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        if ($startDate < $minimumDate) {
            $startDate = $minimumDate;
        }

        if ($endDate < $minimumDate) {
            $endDate = $minimumDate;
        }

        return [$startDate, $endDate];
    }

    private function customerNo(): string
    {
        return (string) env(
            'ACCURATE_DEPOSIT_REALISASI_CUSTOMER_NO',
            env('ACCURATE_UMUM_CUSTOMER_NO', 'UMUM')
        );
    }

    private function salesmanNo(): string
    {
        return (string) env(
            'ACCURATE_DEPOSIT_REALISASI_SALESMAN_NO',
            env('ACCURATE_UMUM_SALESMAN_NO', '')
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return (int) $value;
    }

    private function userName(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        foreach (['display_name', 'nama', 'name', 'username', 'email'] as $field) {
            if (isset($user->{$field}) && trim((string) $user->{$field}) !== '') {
                return trim((string) $user->{$field});
            }
        }

        return null;
    }
}