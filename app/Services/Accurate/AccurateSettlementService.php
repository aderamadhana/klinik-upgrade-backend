<?php

namespace App\Services\Accurate;

use App\Models\Accurate\AccurateSettlementLog;
use App\Models\Pembayaran\PembayaranInvoice;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AccurateSettlementService
{
    private const JENIS_TRANSAKSI_UMUM = 0;

    public function __construct(private readonly AccurateSalesInvoiceClient $client)
    {
    }

    public function listUmum(array $filters = []): array
    {
        [$startDate, $endDate] = $this->resolveRange($filters);
        $tokoId = $this->nullableInt($filters['toko_id'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));

        $systemRows = $this->systemRows($startDate, $endDate, $tokoId);
        $logs = $this->logs($startDate, $endDate, $tokoId);

        $rows = $this->mergeRows($systemRows, $logs)
            ->sortByDesc(fn (array $row) => $row['tanggal_faktur'] . '-' . str_pad((string) $row['toko_id'], 8, '0', STR_PAD_LEFT))
            ->values();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower((string) $row['tanggal_faktur']), $needle)
                    || str_contains(mb_strtolower((string) $row['faktur']), $needle)
                    || str_contains(mb_strtolower((string) $row['deskripsi_data']), $needle)
                    || str_contains(mb_strtolower((string) $row['nama_pengirim']), $needle)
                    || str_contains(mb_strtolower((string) $row['toko_nama']), $needle);
            })->values();
        }

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
                'total_system' => round((float) $rows->sum('total_system'), 2),
                'total_amount' => round((float) $rows->sum('total_amount'), 2),
                'success_count' => $rows->where('status_code', AccurateSettlementLog::STATUS_SUCCESS)->count(),
                'pending_count' => $rows->where('status_code', AccurateSettlementLog::STATUS_NO_DATA)->count(),
                'failed_count' => $rows->where('status_code', AccurateSettlementLog::STATUS_FAILED)->count(),
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'toko_id' => $tokoId,
            ],
        ];
    }

    public function uploadUmum(string $tanggalFaktur, int $tokoId, ?object $user = null): array
    {
        $tanggalFaktur = Carbon::parse($tanggalFaktur)->toDateString();
        $userName = $this->userName($user);

        $data = $this->buildUploadData($tanggalFaktur, $tokoId, self::JENIS_TRANSAKSI_UMUM);

        $log = DB::transaction(function () use ($tanggalFaktur, $tokoId, $userName, $data) {
            $log = AccurateSettlementLog::findOrCreateDailyLog(
                $tokoId,
                $tanggalFaktur,
                self::JENIS_TRANSAKSI_UMUM,
                $data['deskripsi_data'],
                $userName
            );

            if ($log->isSuccess()) {
                throw new RuntimeException('Faktur umum tanggal ini sudah berhasil diupload ke Accurate.');
            }

            $log->forceFill([
                'deskripsi_data' => $data['deskripsi_data'],
                'updated_by' => $userName,
            ])->save();

            $log->markProcessing($data['payload'], $data['total_system'], $data['total_amount'], $userName);

            return $log;
        });

        try {
            $response = $this->client->uploadSalesInvoice($data['payload']);
            $log->markSuccess($response['number'], $response['payload'], $userName);
        } catch (Throwable $exception) {
            $log->markFailed($exception->getMessage());
            throw $exception;
        }

        return $this->serializeRowFromLog($log->fresh(), $data['toko']);
    }

    private function resolveRange(array $filters): array
    {
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        $date = $filters['date'] ?? null;

        if ($date && ! $start && ! $end) {
            $end = Carbon::parse($date)->toDateString();
            $start = Carbon::parse($date)->subDays(31)->toDateString();
        }

        $endDate = $end ? Carbon::parse($end)->toDateString() : Carbon::today()->toDateString();
        $startDate = $start ? Carbon::parse($start)->toDateString() : Carbon::parse($endDate)->subDays(31)->toDateString();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [$startDate, $endDate];
    }

    private function systemRows(string $startDate, string $endDate, ?int $tokoId): Collection
    {
        $dateExpression = 'DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice))';

        return DB::table('pembayaran_invoice as pi')
            ->join('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->where('pi.status', PembayaranInvoice::STATUS_LUNAS)
            ->where('pi.is_delete', 0)
            ->where('pi.jenis_transaksi', self::JENIS_TRANSAKSI_UMUM)
            ->whereBetween(DB::raw($dateExpression), [$startDate, $endDate])
            ->when($tokoId, fn ($query) => $query->where('pi.toko_id', $tokoId))
            ->selectRaw("{$dateExpression} as tanggal_faktur")
            ->selectRaw('pi.toko_id, mt.kode_toko, mt.nama_toko')
            ->selectRaw('SUM(pi.grand_total) as total_system')
            ->selectRaw('COUNT(pi.id) as total_invoice')
            ->groupBy(DB::raw($dateExpression), 'pi.toko_id', 'mt.kode_toko', 'mt.nama_toko')
            ->get()
            ->keyBy(fn ($row) => $this->rowKey($row->tanggal_faktur, (int) $row->toko_id));
    }

    private function logs(string $startDate, string $endDate, ?int $tokoId): Collection
    {
        return AccurateSettlementLog::query()
            ->umum()
            ->betweenTanggal($startDate, $endDate)
            ->when($tokoId, fn ($query) => $query->where('toko_id', $tokoId))
            ->get()
            ->keyBy(fn (AccurateSettlementLog $log) => $this->rowKey($log->tanggal_faktur->toDateString(), (int) $log->toko_id));
    }

    private function mergeRows(Collection $systemRows, Collection $logs): Collection
    {
        $keys = $systemRows->keys()->merge($logs->keys())->unique();

        return $keys->map(function (string $key) use ($systemRows, $logs): array {
            $system = $systemRows->get($key);
            /** @var AccurateSettlementLog|null $log */
            $log = $logs->get($key);

            $tanggal = $system?->tanggal_faktur ?: $log?->tanggal_faktur?->toDateString();
            $tokoId = (int) ($system?->toko_id ?: $log?->toko_id);
            $toko = $this->toko($tokoId);

            if ($log) {
                return $this->serializeRowFromLog($log, $toko, $system);
            }

            $totalSystem = round((float) ($system?->total_system ?? 0), 2);
            $description = $this->description($tanggal, $toko);

            return [
                'id' => null,
                'tanggal_faktur' => $tanggal,
                'toko_id' => $tokoId,
                'toko_nama' => $toko['nama_toko'],
                'faktur' => '-',
                'total_system' => $totalSystem,
                'total_amount' => 0,
                'tanggal_upload' => '-',
                'status_code' => AccurateSettlementLog::STATUS_NO_DATA,
                'status_label' => $totalSystem > 0 ? 'No data available' : 'Tidak ada transaksi',
                'status_color' => $totalSystem > 0 ? 'blue-grey' : 'grey',
                'status_text' => $totalSystem > 0 ? 'No data available' : 'Tidak ada transaksi',
                'deskripsi_data' => $description,
                'nama_pengirim' => '-',
                'error_message' => null,
                'can_upload' => $totalSystem > 0,
                'total_invoice' => (int) ($system?->total_invoice ?? 0),
            ];
        });
    }

    private function buildUploadData(string $tanggalFaktur, int $tokoId, int $jenisTransaksi): array
    {
        $dateExpression = 'DATE(COALESCE(tanggal_lunas, tanggal_invoice))';

        $invoices = DB::table('pembayaran_invoice')
            ->where('status', PembayaranInvoice::STATUS_LUNAS)
            ->where('is_delete', 0)
            ->where('jenis_transaksi', $jenisTransaksi)
            ->where('toko_id', $tokoId)
            ->whereRaw("{$dateExpression} = ?", [$tanggalFaktur])
            ->get(['id', 'no_invoice', 'grand_total']);

        if ($invoices->isEmpty()) {
            throw new RuntimeException('Tidak ada invoice umum lunas untuk tanggal dan cabang ini.');
        }

        $toko = $this->toko($tokoId);
        $totalSystem = round((float) $invoices->sum('grand_total'), 2);

        if ($totalSystem <= 0) {
            throw new RuntimeException('Total system 0. Faktur umum tidak perlu diupload ke Accurate.');
        }

        $invoiceIds = $invoices->pluck('id')->all();
        $lines = $this->accurateLines($invoiceIds);
        $totalAmount = round((float) array_sum(array_column($lines, 'unitPrice')), 2);
        $difference = round($totalSystem - $totalAmount, 2);

        if (abs($difference) > 5) {
            throw new RuntimeException(sprintf(
                'Total item Accurate (%s) tidak sama dengan total system (%s). Cek diskon subtotal/item invoice sebelum upload.',
                number_format($totalAmount, 2, '.', ''),
                number_format($totalSystem, 2, '.', '')
            ));
        }

        if (abs($difference) > 0 && count($lines) > 0) {
            $lines[0]['unitPrice'] = round($lines[0]['unitPrice'] + $difference, 2);
            $totalAmount = round((float) array_sum(array_column($lines, 'unitPrice')), 2);
        }

        $description = $this->description($tanggalFaktur, $toko);
        $customerNo = (string) env('ACCURATE_UMUM_CUSTOMER_NO', 'UMUM');
        $branchName = (string) env('ACCURATE_BRANCH_NAME', $toko['nama_toko']);

        $payload = [
            'transDate' => Carbon::parse($tanggalFaktur)->format('d/m/Y'),
            'customerNo' => $customerNo,
            'description' => $description,
            'branchName' => $branchName,
            'detailItem' => $lines,
        ];

        $salesmanNo = (string) env('ACCURATE_UMUM_SALESMAN_NO', '');
        if ($salesmanNo !== '') {
            $payload['salesmanNo'] = $salesmanNo;
        }

        return [
            'payload' => $payload,
            'total_system' => $totalSystem,
            'total_amount' => $totalAmount,
            'deskripsi_data' => $description,
            'toko' => $toko,
        ];
    }

    private function accurateLines(array $invoiceIds): array
    {
        $items = DB::table('pembayaran_invoice_item')
            ->whereIn('pembayaran_id', $invoiceIds)
            ->where('is_delete', 0)
            ->where('status', 1)
            ->where('is_send_to_accurate', 1)
            ->orderBy('item_type')
            ->orderBy('id')
            ->get([
                'item_type',
                'item_name',
                'kode_accurate_snapshot',
                'nama_accurate_snapshot',
                'accurate_source_code',
                'subtotal',
                'subtotal_after_diskon_subtotal',
                'send_when_zero',
            ]);

        if ($items->isEmpty()) {
            throw new RuntimeException('Tidak ada item invoice yang ditandai untuk dikirim ke Accurate.');
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
                throw new RuntimeException('Kode Accurate belum lengkap untuk item: ' . ($item->item_name ?: '-'));
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
            throw new RuntimeException('Semua item Accurate bernilai 0 dan tidak ada flag send_when_zero.');
        }

        return $lines;
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
            1 => (string) env('ACCURATE_ITEM_KONSULTASI_NO', ''),
            2 => (string) env('ACCURATE_ITEM_TREATMENT_NO', ''),
            3 => (string) env('ACCURATE_ITEM_PRODUK_NO', ''),
            4 => (string) env('ACCURATE_ITEM_DEPOSIT_NO', ''),
            default => '',
        };
    }

    private function accurateItemName(object $item): string
    {
        $snapshot = trim((string) ($item->nama_accurate_snapshot ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }

        return match ((int) $item->item_type) {
            1 => 'Konsultasi',
            2 => 'Treatment',
            3 => 'Produk',
            4 => 'Deposit',
            default => $item->item_name ?: 'Item Accurate',
        };
    }

    private function serializeRowFromLog(AccurateSettlementLog $log, array $toko, ?object $system = null): array
    {
        $tanggal = $log->tanggal_faktur->toDateString();
        $status = $this->statusMeta((int) $log->status, $log->error_message);

        return [
            'id' => $log->id,
            'tanggal_faktur' => $tanggal,
            'toko_id' => (int) $log->toko_id,
            'toko_nama' => $toko['nama_toko'],
            'faktur' => $log->faktur_accurate ?: '-',
            'total_system' => round((float) ($system?->total_system ?? $log->total_system), 2),
            'total_amount' => round((float) $log->total_amount, 2),
            'tanggal_upload' => $log->uploaded_at ? $log->uploaded_at->format('Y-m-d H:i:s') : '-',
            'status_code' => (int) $log->status,
            'status_label' => $status['label'],
            'status_color' => $status['color'],
            'status_text' => $status['text'],
            'deskripsi_data' => $log->deskripsi_data,
            'nama_pengirim' => $log->uploaded_by ?: '-',
            'error_message' => $log->error_message,
            'can_upload' => (int) $log->status !== AccurateSettlementLog::STATUS_SUCCESS
                && round((float) ($system?->total_system ?? $log->total_system), 2) > 0,
            'total_invoice' => (int) ($system?->total_invoice ?? 0),
        ];
    }

    private function statusMeta(int $status, ?string $errorMessage = null): array
    {
        return match ($status) {
            AccurateSettlementLog::STATUS_SUCCESS => [
                'label' => 'success',
                'color' => 'success',
                'text' => 'Faktur Penjualan berhasil disimpan',
            ],
            AccurateSettlementLog::STATUS_FAILED => [
                'label' => 'failed',
                'color' => 'error',
                'text' => $errorMessage ?: 'Upload gagal',
            ],
            AccurateSettlementLog::STATUS_PROCESSING => [
                'label' => 'processing',
                'color' => 'warning',
                'text' => 'Sedang proses upload',
            ],
            default => [
                'label' => 'no data',
                'color' => 'blue-grey',
                'text' => 'No data available',
            ],
        };
    }

    private function toko(int $tokoId): array
    {
        $toko = DB::table('master_toko')
            ->where('id', $tokoId)
            ->first(['id', 'kode_toko', 'nama_toko']);

        if (! $toko) {
            throw new RuntimeException('Cabang/toko tidak ditemukan.');
        }

        return [
            'id' => (int) $toko->id,
            'kode_toko' => (string) ($toko->kode_toko ?? ''),
            'nama_toko' => (string) ($toko->nama_toko ?? 'CABANG'),
        ];
    }

    private function description(string $tanggal, array $toko): string
    {
        $branch = strtoupper(trim((string) ($toko['nama_toko'] ?? 'CABANG')));

        return sprintf('TREATMENT & PRODUK %s %s', $branch, Carbon::parse($tanggal)->format('d/m/Y'));
    }

    private function rowKey(string $tanggal, int $tokoId): string
    {
        return $tanggal . '#' . $tokoId;
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
