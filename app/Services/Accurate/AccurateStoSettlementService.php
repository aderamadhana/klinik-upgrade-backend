<?php

namespace App\Services\Accurate;

use App\Models\Accurate\AccurateStoSettlementLog;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AccurateStoSettlementService
{
    public function __construct(private readonly AccurateSalesInvoiceClient $client)
    {
    }

    public function list(array $filters = []): array
    {
        [$startDate, $endDate] = $this->resolveRange($filters);

        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));

        $rows = $this->queryRows($startDate, $endDate, $search);

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
                'total_harga' => round((float) $rows->sum('total_harga'), 2),
                'success_count' => $rows
                    ->where('status_code', AccurateStoSettlementLog::STATUS_SUCCESS)
                    ->count(),
                'pending_count' => $rows
                    ->where('status_code', AccurateStoSettlementLog::STATUS_PENDING)
                    ->count(),
                'failed_count' => $rows
                    ->where('status_code', AccurateStoSettlementLog::STATUS_FAILED)
                    ->count(),
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ];
    }

    public function upload(int $stoInvoiceId, ?object $user = null): array
    {
        $userName = $this->userName($user);
        $data = $this->buildUploadData($stoInvoiceId);

        $log = DB::transaction(function () use ($stoInvoiceId, $data, $userName) {
            $existingLog = AccurateStoSettlementLog::query()
                ->where('sto_invoice_id', $stoInvoiceId)
                ->lockForUpdate()
                ->first();

            if ($existingLog && $existingLog->isSuccess()) {
                throw new RuntimeException('Faktur STO ini sudah berhasil dikirim ke Accurate.');
            }

            $log = $existingLog ?: new AccurateStoSettlementLog([
                'sto_invoice_id' => $data['invoice']->id,
                'tanggal_faktur' => $data['invoice']->tanggal_faktur,
                'no_faktur' => $data['invoice']->no_faktur,
                'nama_toko' => $data['invoice']->nama_toko,
                'created_by' => $userName,
            ]);

            $log->forceFill([
                'tanggal_faktur' => $data['invoice']->tanggal_faktur,
                'no_faktur' => $data['invoice']->no_faktur,
                'nama_toko' => $data['invoice']->nama_toko,
                'total_harga' => $data['total_harga'],
                'keterangan' => $data['description'],
                'updated_by' => $userName,
            ])->save();

            $log->markProcessing(
                $data['payload'],
                $data['total_harga'],
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
                $data['invoice']->no_faktur
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
        string $search
    ): Collection {
        $rows = DB::table('accurate_sto_invoice as sto')
            ->leftJoin('accurate_sto_settlement_log as log', 'log.sto_invoice_id', '=', 'sto.id')
            ->where('sto.is_delete', 0)
            ->whereBetween('sto.tanggal_faktur', [$startDate, $endDate])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';

                $query->where(function ($subQuery) use ($like) {
                    $subQuery
                        ->where('sto.no_faktur', 'like', $like)
                        ->orWhere('sto.status_transaksi', 'like', $like)
                        ->orWhere('sto.nama_toko', 'like', $like)
                        ->orWhere('sto.metode_pembayaran', 'like', $like)
                        ->orWhere('log.faktur_accurate', 'like', $like)
                        ->orWhere('log.keterangan', 'like', $like)
                        ->orWhere('log.uploaded_by', 'like', $like);
                });
            })
            ->select([
                'sto.id',
                'sto.tanggal_faktur',
                'sto.no_faktur',
                'sto.status_transaksi',
                'sto.nama_toko',
                'sto.metode_pembayaran',
                'sto.total_harga',
                'sto.customer_no',
                'sto.branch_name',
                'log.id as log_id',
                'log.faktur_accurate',
                'log.total_harga as log_total_harga',
                'log.status as log_status',
                'log.keterangan',
                'log.error_message',
                'log.uploaded_by',
                'log.uploaded_at',
            ])
            ->orderByDesc('sto.tanggal_faktur')
            ->orderByDesc('sto.id')
            ->get();

        return $rows->map(fn ($row) => $this->serializeRow($row));
    }

    private function buildUploadData(int $stoInvoiceId): array
    {
        $invoice = DB::table('accurate_sto_invoice')
            ->where('id', $stoInvoiceId)
            ->where('is_delete', 0)
            ->first();

        if (! $invoice) {
            throw new RuntimeException('Data STO tidak ditemukan.');
        }

        $totalHarga = round((float) $invoice->total_harga, 2);

        if ($totalHarga <= 0) {
            throw new RuntimeException('Total harga STO 0. Faktur tidak perlu dikirim ke Accurate.');
        }

        $lines = $this->accurateLines($invoice);
        $totalLines = round((float) array_sum(array_column($lines, 'unitPrice')), 2);
        $difference = round($totalHarga - $totalLines, 2);

        if (abs($difference) > 5) {
            throw new RuntimeException(sprintf(
                'Total item Accurate (%s) tidak sama dengan total STO (%s). Cek detail STO sebelum upload.',
                number_format($totalLines, 2, '.', ''),
                number_format($totalHarga, 2, '.', '')
            ));
        }

        if (abs($difference) > 0 && count($lines) > 0) {
            $lines[0]['unitPrice'] = round($lines[0]['unitPrice'] + $difference, 2);
        }

        $description = $this->description($invoice);

        $payload = [
            'transDate' => Carbon::parse($invoice->tanggal_faktur)->format('d/m/Y'),
            'customerNo' => $this->customerNo($invoice),
            'description' => $description,
            'branchName' => $this->branchName($invoice),
            'detailItem' => $lines,
        ];

        $salesmanNo = $this->salesmanNo();

        if ($salesmanNo !== '') {
            $payload['salesmanNo'] = $salesmanNo;
        }

        return [
            'invoice' => $invoice,
            'total_harga' => $totalHarga,
            'description' => $description,
            'payload' => $payload,
        ];
    }

    private function accurateLines(object $invoice): array
    {
        $items = DB::table('accurate_sto_invoice_item')
            ->where('sto_invoice_id', $invoice->id)
            ->where('is_delete', 0)
            ->orderBy('id')
            ->get([
                'item_no',
                'item_name',
                'qty',
                'unit_price',
                'total',
            ]);

        if ($items->isEmpty()) {
            return $this->fallbackLine($invoice);
        }

        $lines = [];

        foreach ($items as $item) {
            $itemNo = trim((string) ($item->item_no ?? ''));

            if ($itemNo === '') {
                $itemNo = (string) env('ACCURATE_STO_ITEM_NO', '');
            }

            if ($itemNo === '') {
                throw new RuntimeException(
                    'Kode Accurate STO belum lengkap untuk item: ' . ($item->item_name ?: '-')
                );
            }

            $qty = (float) $item->qty;
            $amount = (float) $item->total;

            if ($amount <= 0) {
                $amount = round($qty * (float) $item->unit_price, 2);
            }

            if ($qty <= 0) {
                $qty = 1;
            }

            $lines[] = [
                'itemNo' => $itemNo,
                'quantity' => $qty,
                'unitPrice' => round($amount / $qty, 2),
                'detailName' => $item->item_name ?: 'STO',
            ];
        }

        if (count($lines) === 0) {
            return $this->fallbackLine($invoice);
        }

        return $lines;
    }

    private function fallbackLine(object $invoice): array
    {
        $itemNo = (string) env('ACCURATE_STO_ITEM_NO', '');

        if ($itemNo === '') {
            throw new RuntimeException('Kode Accurate STO belum diset. Isi ACCURATE_STO_ITEM_NO di .env atau isi item_no di detail STO.');
        }

        return [[
            'itemNo' => $itemNo,
            'quantity' => 1,
            'unitPrice' => round((float) $invoice->total_harga, 2),
            'detailName' => 'STO - ' . $invoice->no_faktur,
        ]];
    }

    private function serializeRow(object $row): array
    {
        $statusCode = $row->log_status === null
            ? AccurateStoSettlementLog::STATUS_PENDING
            : (int) $row->log_status;

        $status = $this->statusMeta($statusCode, $row->error_message ?? null);

        $totalHarga = $row->log_total_harga !== null
            ? (float) $row->log_total_harga
            : (float) $row->total_harga;

        return [
            'id' => (int) $row->id,
            'sto_invoice_id' => (int) $row->id,
            'tanggal_faktur' => Carbon::parse($row->tanggal_faktur)->toDateString(),
            'no_faktur' => (string) $row->no_faktur,
            'status_transaksi' => (string) ($row->status_transaksi ?: '-'),
            'nama_toko' => (string) ($row->nama_toko ?: '-'),
            'metode_pembayaran' => (string) ($row->metode_pembayaran ?: '-'),
            'faktur' => $row->faktur_accurate ?: '-',
            'total_harga' => round($totalHarga, 2),
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
            'can_upload' => $statusCode !== AccurateStoSettlementLog::STATUS_SUCCESS
                && round($totalHarga, 2) > 0,
        ];
    }

    private function rowFromInvoice(object $invoice, AccurateStoSettlementLog $log): object
    {
        return (object) [
            'id' => $invoice->id,
            'tanggal_faktur' => $invoice->tanggal_faktur,
            'no_faktur' => $invoice->no_faktur,
            'status_transaksi' => $invoice->status_transaksi,
            'nama_toko' => $invoice->nama_toko,
            'metode_pembayaran' => $invoice->metode_pembayaran,
            'total_harga' => $invoice->total_harga,
            'faktur_accurate' => $log->faktur_accurate,
            'log_total_harga' => $log->total_harga,
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
            AccurateStoSettlementLog::STATUS_SUCCESS => [
                'label' => 'Terkirim',
                'color' => 'success',
                'text' => 'Faktur STO berhasil dikirim',
            ],
            AccurateStoSettlementLog::STATUS_FAILED => [
                'label' => 'Gagal',
                'color' => 'error',
                'text' => $errorMessage ?: 'Upload gagal',
            ],
            AccurateStoSettlementLog::STATUS_PROCESSING => [
                'label' => 'Proses',
                'color' => 'warning',
                'text' => 'Sedang proses upload',
            ],
            default => [
                'label' => 'Belum Dikirim',
                'color' => 'blue-grey',
                'text' => 'Belum dikirim ke Accurate',
            ],
        };
    }

    private function description(object $invoice): string
    {
        return sprintf(
            'STO %s %s %s',
            strtoupper((string) $invoice->no_faktur),
            strtoupper((string) $invoice->nama_toko),
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

    private function customerNo(object $invoice): string
    {
        $customerNo = trim((string) ($invoice->customer_no ?? ''));

        if ($customerNo !== '') {
            return $customerNo;
        }

        return (string) env(
            'ACCURATE_STO_CUSTOMER_NO',
            env('ACCURATE_UMUM_CUSTOMER_NO', 'UMUM')
        );
    }

    private function branchName(object $invoice): string
    {
        $branchName = trim((string) ($invoice->branch_name ?? ''));

        if ($branchName !== '') {
            return $branchName;
        }

        return (string) env('ACCURATE_BRANCH_NAME', 'CABANG');
    }

    private function salesmanNo(): string
    {
        return (string) env(
            'ACCURATE_STO_SALESMAN_NO',
            env('ACCURATE_UMUM_SALESMAN_NO', '')
        );
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