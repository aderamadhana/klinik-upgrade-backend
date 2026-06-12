<?php

namespace App\Services\Accurate;

use App\Models\Accurate\AccurateDepositSettlementLog;
use App\Models\Pembayaran\PembayaranInvoice;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AccurateDepositSettlementService
{
    private const JENIS_TRANSAKSI_DEPOSIT = 4;

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
                'total_deposit' => round((float) $rows->sum('total_deposit'), 2),
                'success_count' => $rows
                    ->where('status_code', AccurateDepositSettlementLog::STATUS_SUCCESS)
                    ->count(),
                'pending_count' => $rows
                    ->where('status_code', AccurateDepositSettlementLog::STATUS_PENDING)
                    ->count(),
                'failed_count' => $rows
                    ->where('status_code', AccurateDepositSettlementLog::STATUS_FAILED)
                    ->count(),
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'toko_id' => $tokoId,
            ],
        ];
    }

    public function upload(int $pembayaranId, ?object $user = null): array
    {
        $userName = $this->userName($user);
        $data = $this->buildUploadData($pembayaranId);

        $log = DB::transaction(function () use ($pembayaranId, $data, $userName) {
            $existingLog = AccurateDepositSettlementLog::query()
                ->where('pembayaran_id', $pembayaranId)
                ->lockForUpdate()
                ->first();

            if ($existingLog && $existingLog->isSuccess()) {
                throw new RuntimeException('Faktur deposit ini sudah berhasil dikirim ke Accurate.');
            }

            $log = $existingLog ?: new AccurateDepositSettlementLog([
                'pembayaran_id' => $data['invoice']->id,
                'toko_id' => $data['invoice']->toko_id,
                'pasien_id' => $data['invoice']->pasien_id,
                'tanggal_faktur' => $data['tanggal_faktur'],
                'no_invoice' => $data['invoice']->no_invoice,
                'nama_pasien' => $data['invoice']->nama_pasien,
                'created_by' => $userName,
            ]);

            $log->forceFill([
                'toko_id' => $data['invoice']->toko_id,
                'pasien_id' => $data['invoice']->pasien_id,
                'tanggal_faktur' => $data['tanggal_faktur'],
                'no_invoice' => $data['invoice']->no_invoice,
                'nama_pasien' => $data['invoice']->nama_pasien,
                'total_deposit' => $data['total_deposit'],
                'keterangan' => $data['description'],
                'updated_by' => $userName,
            ])->save();

            $log->markProcessing(
                $data['payload'],
                $data['total_deposit'],
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
                $data['invoice']->no_invoice
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
            $this->rowFromInvoice($data['invoice'], $log->fresh())
        );
    }

    private function queryRows(
        string $startDate,
        string $endDate,
        ?int $tokoId,
        string $search
    ): Collection {
        $dateExpression = 'DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice))';

        $rows = DB::table('pembayaran_invoice as pi')
            ->join('pasien as p', 'p.id', '=', 'pi.pasien_id')
            ->join('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoin('accurate_deposit_settlement_log as log', 'log.pembayaran_id', '=', 'pi.id')
            ->where('pi.status', PembayaranInvoice::STATUS_LUNAS)
            ->where('pi.is_delete', 0)
            ->where('pi.jenis_transaksi', self::JENIS_TRANSAKSI_DEPOSIT)
            ->whereBetween(DB::raw($dateExpression), [$startDate, $endDate])
            ->when($tokoId, fn ($query) => $query->where('pi.toko_id', $tokoId))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $like = '%' . $search . '%';

                    $subQuery
                        ->where('pi.no_invoice', 'like', $like)
                        ->orWhere('p.nama', 'like', $like)
                        ->orWhere('p.no_rm', 'like', $like)
                        ->orWhere('mt.nama_toko', 'like', $like)
                        ->orWhere('log.faktur_accurate', 'like', $like)
                        ->orWhere('log.keterangan', 'like', $like)
                        ->orWhere('log.uploaded_by', 'like', $like);
                });
            })
            ->select([
                'pi.id',
                'pi.no_invoice',
                'pi.toko_id',
                'pi.pasien_id',
                'pi.grand_total',
                'p.nama as nama_pasien',
                'p.no_rm',
                'mt.nama_toko',
                'log.id as log_id',
                'log.faktur_accurate',
                'log.total_deposit as log_total_deposit',
                'log.status as log_status',
                'log.keterangan',
                'log.error_message',
                'log.uploaded_by',
                'log.uploaded_at',
            ])
            ->selectRaw("{$dateExpression} as tanggal_faktur")
            ->orderByDesc(DB::raw($dateExpression))
            ->orderByDesc('pi.id')
            ->get();

        return $rows->map(fn ($row) => $this->serializeRow($row));
    }

    private function buildUploadData(int $pembayaranId): array
    {
        $dateExpression = 'DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice))';

        $invoice = DB::table('pembayaran_invoice as pi')
            ->join('pasien as p', 'p.id', '=', 'pi.pasien_id')
            ->join('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->where('pi.id', $pembayaranId)
            ->where('pi.status', PembayaranInvoice::STATUS_LUNAS)
            ->where('pi.is_delete', 0)
            ->where('pi.jenis_transaksi', self::JENIS_TRANSAKSI_DEPOSIT)
            ->select([
                'pi.id',
                'pi.no_invoice',
                'pi.toko_id',
                'pi.pasien_id',
                'pi.grand_total',
                'pi.tanggal_invoice',
                'pi.tanggal_lunas',
                'p.nama as nama_pasien',
                'p.no_rm',
                'mt.nama_toko',
            ])
            ->selectRaw("{$dateExpression} as tanggal_faktur")
            ->first();

        if (! $invoice) {
            throw new RuntimeException('Invoice deposit lunas tidak ditemukan.');
        }

        $totalDeposit = round((float) $invoice->grand_total, 2);

        if ($totalDeposit <= 0) {
            throw new RuntimeException('Total deposit 0. Faktur deposit tidak perlu dikirim ke Accurate.');
        }

        $lines = $this->accurateLines($invoice->id);

        $totalLines = round((float) array_sum(array_column($lines, 'unitPrice')), 2);
        $difference = round($totalDeposit - $totalLines, 2);

        if (abs($difference) > 5) {
            throw new RuntimeException(sprintf(
                'Total item Accurate (%s) tidak sama dengan total deposit (%s). Cek item deposit sebelum upload.',
                number_format($totalLines, 2, '.', ''),
                number_format($totalDeposit, 2, '.', '')
            ));
        }

        if (abs($difference) > 0 && count($lines) > 0) {
            $lines[0]['unitPrice'] = round($lines[0]['unitPrice'] + $difference, 2);
        }

        $description = $this->description($invoice);
        $payload = [
            'transDate' => Carbon::parse($invoice->tanggal_faktur)->format('d/m/Y'),
            'customerNo' => $this->customerNo(),
            'description' => $description,
            'branchName' => (string) env('ACCURATE_BRANCH_NAME', $invoice->nama_toko),
            'detailItem' => $lines,
        ];

        $salesmanNo = $this->salesmanNo();

        if ($salesmanNo !== '') {
            $payload['salesmanNo'] = $salesmanNo;
        }

        return [
            'invoice' => $invoice,
            'tanggal_faktur' => $invoice->tanggal_faktur,
            'total_deposit' => $totalDeposit,
            'description' => $description,
            'payload' => $payload,
        ];
    }

    private function accurateLines(int $pembayaranId): array
    {
        $items = DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $pembayaranId)
            ->where('is_delete', 0)
            ->where('status', 1)
            ->where('is_send_to_accurate', 1)
            ->orderBy('item_type')
            ->orderBy('id')
            ->get([
                'item_type',
                'nama_item',
                'kode_accurate_snapshot',
                'nama_accurate_snapshot',
                'accurate_source_code',
                'subtotal',
                'subtotal_after_diskon_subtotal',
                'send_when_zero',
            ]);

        if ($items->isEmpty()) {
            return $this->fallbackDepositLines($pembayaranId);
        }

        $groups = [];

        foreach ($items as $item) {
            $amount = $item->subtotal_after_diskon_subtotal !== null
                ? (float) $item->subtotal_after_diskon_subtotal
                : (float) $item->subtotal;

            if ($amount <= 0 && ! (int) $item->send_when_zero) {
                continue;
            }

            $itemNo = $this->accurateItemNo($item);
            $itemName = $this->accurateItemName($item);

            if ($itemNo === '') {
                throw new RuntimeException(
                    'Kode Accurate belum lengkap untuk item deposit: ' . ($item->nama_item ?: '-')
                );
            }

            $key = $itemNo . '|' . $itemName;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'itemNo' => $itemNo,
                    'quantity' => 1,
                    'unitPrice' => 0,
                    'detailName' => $itemName,
                ];
            }

            $groups[$key]['unitPrice'] = round($groups[$key]['unitPrice'] + $amount, 2);
        }

        $lines = array_values($groups);

        if (count($lines) === 0) {
            return $this->fallbackDepositLines($pembayaranId);
        }

        return $lines;
    }

    private function fallbackDepositLines(int $pembayaranId): array
    {
        $itemNo = (string) env('ACCURATE_ITEM_DEPOSIT_NO', '');

        if ($itemNo === '') {
            throw new RuntimeException('Kode Accurate deposit belum diset. Isi ACCURATE_ITEM_DEPOSIT_NO di .env.');
        }

        $rows = DB::table('pembayaran_deposit_treatment')
            ->where('pembayaran_id', $pembayaranId)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->get([
                'nama_treatment',
                'total_nilai',
            ]);

        if ($rows->isEmpty()) {
            throw new RuntimeException('Tidak ada item deposit yang bisa dikirim ke Accurate.');
        }

        return $rows
            ->map(function ($row) use ($itemNo) {
                return [
                    'itemNo' => $itemNo,
                    'quantity' => 1,
                    'unitPrice' => round((float) $row->total_nilai, 2),
                    'detailName' => 'Deposit - ' . ($row->nama_treatment ?: 'Treatment'),
                ];
            })
            ->values()
            ->all();
    }

    private function accurateItemNo(object $item): string
    {
        $snapshot = trim((string) ($item->kode_accurate_snapshot ?? ''));

        if ($snapshot !== '') {
            return $snapshot;
        }

        $source = trim((string) ($item->accurate_source_code ?? ''));

        if ($source !== '') {
            return $source;
        }

        return match ((int) $item->item_type) {
            2 => (string) env('ACCURATE_ITEM_TREATMENT_NO', ''),
            4 => (string) env('ACCURATE_ITEM_DEPOSIT_NO', ''),
            5 => (string) env('ACCURATE_ITEM_DEPOSIT_NO', ''),
            default => (string) env('ACCURATE_ITEM_DEPOSIT_NO', ''),
        };
    }

    private function accurateItemName(object $item): string
    {
        $snapshot = trim((string) ($item->nama_accurate_snapshot ?? ''));

        if ($snapshot !== '') {
            return $snapshot;
        }

        return match ((int) $item->item_type) {
            2 => $item->nama_item ?: 'Treatment Deposit',
            4 => $item->nama_item ?: 'Deposit Treatment',
            5 => $item->nama_item ?: 'Deposit Treatment',
            default => $item->nama_item ?: 'Deposit Treatment',
        };
    }

    private function serializeRow(object $row): array
    {
        $statusCode = $row->log_status === null
            ? AccurateDepositSettlementLog::STATUS_PENDING
            : (int) $row->log_status;

        $status = $this->statusMeta($statusCode, $row->error_message ?? null);
        $totalDeposit = $row->log_total_deposit !== null
            ? (float) $row->log_total_deposit
            : (float) $row->grand_total;

        return [
            'id' => (int) $row->id,
            'pembayaran_id' => (int) $row->id,
            'tanggal_faktur' => (string) $row->tanggal_faktur,
            'nama_pasien' => (string) ($row->nama_pasien ?: '-'),
            'no_rm' => (string) ($row->no_rm ?: '-'),
            'no_invoice' => (string) $row->no_invoice,
            'toko_id' => (int) $row->toko_id,
            'toko_nama' => (string) ($row->nama_toko ?: '-'),
            'faktur' => $row->faktur_accurate ?: '-',
            'total_deposit' => round($totalDeposit, 2),
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
            'can_upload' => $statusCode !== AccurateDepositSettlementLog::STATUS_SUCCESS
                && round($totalDeposit, 2) > 0,
        ];
    }

    private function rowFromInvoice(object $invoice, AccurateDepositSettlementLog $log): object
    {
        return (object) [
            'id' => $invoice->id,
            'no_invoice' => $invoice->no_invoice,
            'toko_id' => $invoice->toko_id,
            'pasien_id' => $invoice->pasien_id,
            'grand_total' => $invoice->grand_total,
            'nama_pasien' => $invoice->nama_pasien,
            'no_rm' => $invoice->no_rm,
            'nama_toko' => $invoice->nama_toko,
            'tanggal_faktur' => $invoice->tanggal_faktur,
            'log_id' => $log->id,
            'faktur_accurate' => $log->faktur_accurate,
            'log_total_deposit' => $log->total_deposit,
            'log_status' => $log->status,
            'keterangan' => $log->keterangan,
            'error_message' => $log->error_message,
            'uploaded_by' => $log->uploaded_by,
            'uploaded_at' => $log->uploaded_at,
        ];
    }

    private function statusMeta(int $status, ?string $errorMessage = null): array
    {
        return match ($status) {
            AccurateDepositSettlementLog::STATUS_SUCCESS => [
                'label' => 'Terkirim',
                'color' => 'success',
                'text' => 'Faktur deposit berhasil dikirim',
            ],
            AccurateDepositSettlementLog::STATUS_FAILED => [
                'label' => 'Gagal',
                'color' => 'error',
                'text' => $errorMessage ?: 'Upload gagal',
            ],
            AccurateDepositSettlementLog::STATUS_PROCESSING => [
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

    private function description(object $invoice): string
    {
        return sprintf(
            'DEPOSIT TREATMENT %s %s %s %s',
            strtoupper((string) $invoice->nama_toko),
            strtoupper((string) $invoice->nama_pasien),
            $invoice->no_invoice,
            Carbon::parse($invoice->tanggal_faktur)->format('d/m/Y')
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
            'ACCURATE_DEPOSIT_CUSTOMER_NO',
            env('ACCURATE_UMUM_CUSTOMER_NO', 'UMUM')
        );
    }

    private function salesmanNo(): string
    {
        return (string) env(
            'ACCURATE_DEPOSIT_SALESMAN_NO',
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