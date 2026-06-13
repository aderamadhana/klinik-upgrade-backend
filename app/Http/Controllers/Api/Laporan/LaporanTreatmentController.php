<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanTreatmentExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanTreatmentController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly LaporanTreatmentExportService $exportService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $normalized = $this->normalizeFilters($request);

        if ($normalized['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $normalized['validator']->errors(),
            ], 422);
        }

        $filters = $normalized['data'];
        $detailRows = $this->getSummaryRows($filters);
        $jenisOptions = $this->getJenisTransaksiOptions();
        $jenisLabels = $jenisOptions->pluck('nama_jenis_transaksi', 'id');

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan laporan treatment berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_item' => $detailRows->count(),
                'total_invoice' => $detailRows->pluck('invoice_key')->filter()->unique()->count(),
                'total_pasien' => $detailRows->pluck('pasien_id')->filter()->unique()->count(),
                'total_qty' => (float) $detailRows->sum('qty'),
                'total_gross' => (float) $detailRows->sum('gross_amount'),
                'total_diskon' => (float) $detailRows->sum('total_diskon'),
                'total_net' => (float) $detailRows->sum('subtotal'),
                'by_jenis_transaksi' => collect(self::ALLOWED_JENIS_TRANSAKSI)
                    ->map(function (int $id) use ($detailRows, $jenisLabels): array {
                        $items = $detailRows->where('jenis_transaksi_id', $id);

                        return [
                            'id' => $id,
                            'nama' => $jenisLabels[$id] ?? $this->defaultJenisTransaksiLabel($id),
                            'total_item' => $items->count(),
                            'total_invoice' => $items->pluck('invoice_key')->filter()->unique()->count(),
                            'total_pasien' => $items->pluck('pasien_id')->filter()->unique()->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_gross' => (float) $items->sum('gross_amount'),
                            'total_diskon' => (float) $items->sum('total_diskon'),
                            'total_net' => (float) $items->sum('subtotal'),
                        ];
                    })
                    ->values(),
                'top_treatment' => $detailRows
                    ->groupBy('treatment_key')
                    ->map(function (Collection $items): array {
                        $first = $items->first();

                        return [
                            'treatment_id' => $first['treatment_id'],
                            'nama_treatment' => $first['nama_treatment'],
                            'total_item' => $items->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_net' => (float) $items->sum('subtotal'),
                        ];
                    })
                    ->sortByDesc('total_net')
                    ->take(5)
                    ->values(),
            ],
        ]);
    }

    public function export(Request $request, string $format): Response
    {
        $format = strtolower($format);

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Format laporan harus pdf atau excel.',
            ], 422);
        }

        $normalized = $this->normalizeFilters($request);

        if ($normalized['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $normalized['validator']->errors(),
            ], 422);
        }

        $filters = $normalized['data'];
        $detailRows = $this->getDetailRows($filters);
        $report = $this->buildReport($detailRows, $filters);

        return $format === 'excel'
            ? $this->exportService->excel($report)
            : $this->exportService->pdf($report);
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($tokoId) ? (int) $tokoId : null;

        $jenisTransaksi = $request->query('jenis_transaksi');
        if ($jenisTransaksi === null || $jenisTransaksi === '' || $jenisTransaksi === 'all') {
            $jenisTransaksi = null;
        } elseif (is_numeric($jenisTransaksi)) {
            $jenisTransaksi = (int) $jenisTransaksi;
        }

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query(
                'tanggal_akhir',
                $request->query('tanggal_awal', $today)
            ),
            'toko_id' => $tokoId,
            'jenis_transaksi' => $jenisTransaksi,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            'jenis_transaksi' => ['nullable', 'integer', 'in:0,1,2,3,4'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'jenis_transaksi.in' => 'Jenis transaksi harus 0, 1, 2, 3, atau 4.',
        ]);

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    private function publicFilters(array $filters): array
    {
        $toko = null;

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')
                ->where('id', $filters['toko_id'])
                ->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice dan tanggal realisasi deposit',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getSummaryRows(array $filters): Collection
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_treatment as mt', 'mt.id', '=', 'pii.treatment_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 2)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereIn('pii.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        if ($filters['jenis_transaksi'] !== null) {
            $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
        }

        return $query
            ->orderByRaw("DATE({$tanggalSql}) asc")
            ->orderBy('pi.no_invoice')
            ->orderBy('pii.id')
            ->get([
                'pii.id',
                'pii.pembayaran_id',
                'pi.pasien_id',
                'pii.treatment_id',
                'pii.nama_item',
                'pii.qty',
                'pii.harga',
                'pii.diskon_amount',
                'pii.diskon_referral',
                'pii.diskon_subtotal_amount',
                'pii.subtotal',
                'pii.jenis_transaksi',
                'pii.kode_accurate_snapshot',
                'mt.kode_accurate as master_kode_accurate',
                'mt.nama as master_nama_treatment',
                DB::raw("DATE({$tanggalSql}) as tanggal_raw"),
            ])
            ->map(function (object $row): array {
                $qty = (float) $row->qty;
                $harga = (float) $row->harga;
                $namaTreatment = trim((string) ($row->master_nama_treatment ?: $row->nama_item));
                $kodeAccurate = trim((string) ($row->kode_accurate_snapshot ?: $row->master_kode_accurate));
                $totalDiskon = (float) $row->diskon_amount
                    + (float) $row->diskon_referral
                    + (float) $row->diskon_subtotal_amount;

                return [
                    'id' => (int) $row->id,
                    'invoice_key' => 'P-' . (int) $row->pembayaran_id,
                    'pembayaran_id' => (int) $row->pembayaran_id,
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'tanggal_raw' => $row->tanggal_raw,
                    'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
                    'treatment_key' => $this->treatmentKey(
                        $row->treatment_id,
                        $kodeAccurate,
                        $namaTreatment
                    ),
                    'nama_treatment' => $namaTreatment !== '' ? $namaTreatment : '-',
                    'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                    'jenis_transaksi_id' => (int) $row->jenis_transaksi,
                    'qty' => $qty,
                    'gross_amount' => $qty * $harga,
                    'total_diskon' => $totalDiskon,
                    'subtotal' => (float) $row->subtotal,
                ];
            })
            ->values();
    }

    private function getDetailRows(array $filters): Collection
    {
        return $this->getRegularRows($filters)
            ->concat($this->getDepositClaimRows($filters))
            ->sortBy([
                ['nama_treatment', 'asc'],
                ['tanggal_raw', 'asc'],
            ])
            ->values();
    }

    private function getRegularRows(array $filters): Collection
    {
        if ($filters['jenis_transaksi'] === 4) {
            return collect();
        }

        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';
        $isPremierSql = Schema::hasColumn('pembayaran_invoice', 'is_premier')
            ? 'COALESCE(pi.is_premier, 0)'
            : '0';

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_treatment as mt', 'mt.id', '=', 'pii.treatment_id')
            ->leftJoin('master_treatment_toko as mtt', 'mtt.id', '=', 'pii.treatment_toko_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->whereIn('pii.item_type', [1, 2])
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereNull('pii.deposit_claim_id')
            ->where('pii.jenis_transaksi', '!=', 4)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        if ($filters['jenis_transaksi'] !== null) {
            $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
        }

        return $query
            ->orderByRaw("DATE({$tanggalSql}) asc")
            ->orderBy('pi.no_invoice')
            ->orderBy('pii.id')
            ->get([
                'pii.id',
                'pii.pembayaran_id',
                'pi.pasien_id',
                'pi.no_invoice',
                'pii.treatment_id',
                'pii.nama_item',
                'pii.qty',
                'pii.harga',
                'pii.diskon_amount',
                'pii.diskon_referral',
                'pii.diskon_subtotal_amount',
                'pii.subtotal',
                'pii.jenis_transaksi',
                'pii.kode_accurate_snapshot',
                'mt.kode_accurate as master_kode_accurate',
                'mt.nama as master_nama_treatment',
                'mtt.tarif as tarif_treatment',
                DB::raw("DATE({$tanggalSql}) as tanggal_raw"),
                DB::raw("{$isPremierSql} as is_premier"),
            ])
            ->map(function (object $row): array {
                $qty = (float) $row->qty;
                $hargaItem = (float) $row->harga;
                $hargaTreatment = (float) ($row->tarif_treatment ?: $hargaItem);
                $namaTreatment = trim((string) ($row->master_nama_treatment ?: $row->nama_item));
                $kodeAccurate = trim((string) ($row->kode_accurate_snapshot ?: $row->master_kode_accurate));
                $totalDiskon = (float) $row->diskon_amount
                    + (float) $row->diskon_referral
                    + (float) $row->diskon_subtotal_amount;

                return [
                    'source' => 'invoice',
                    'id' => (int) $row->id,
                    'invoice_key' => 'P-' . (int) $row->pembayaran_id,
                    'pembayaran_id' => (int) $row->pembayaran_id,
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'tanggal_raw' => $row->tanggal_raw,
                    'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
                    'treatment_key' => $this->treatmentKey(
                        $row->treatment_id,
                        $kodeAccurate,
                        $namaTreatment
                    ),
                    'nama_treatment' => $namaTreatment !== '' ? $namaTreatment : '-',
                    'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                    'jenis_transaksi_id' => (int) $row->jenis_transaksi,
                    'is_premier' => (int) $row->is_premier === 1,
                    'is_deposit_claim' => false,
                    'qty' => $qty,
                    'harga_treatment' => $hargaTreatment,
                    'gross_amount' => $qty * $hargaItem,
                    'total_diskon' => $totalDiskon,
                    'subtotal' => (float) $row->subtotal,
                ];
            })
            ->values();
    }

    private function getDepositClaimRows(array $filters): Collection
    {
        if ($filters['jenis_transaksi'] !== null && $filters['jenis_transaksi'] !== 4) {
            return collect();
        }

        $query = DB::table('pembayaran_deposit_treatment_claim as claim')
            ->join('pembayaran_deposit_treatment as deposit', 'deposit.id', '=', 'claim.deposit_treatment_id')
            ->leftJoin('master_treatment as mt', 'mt.id', '=', 'deposit.treatment_id')
            ->leftJoin('master_treatment_toko as mtt', function ($join): void {
                $join->on('mtt.treatment_id', '=', 'deposit.treatment_id')
                    ->on('mtt.toko_id', '=', 'claim.toko_claim_id')
                    ->where('mtt.is_delete', 0);
            })
            ->where('claim.status', 1)
            ->where('claim.is_delete', 0)
            ->where('deposit.is_delete', 0)
            ->whereRaw('DATE(claim.claimed_at) BETWEEN ? AND ?', [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('claim.toko_claim_id', (int) $filters['toko_id']);
        }

        return $query
            ->orderBy('claim.claimed_at')
            ->orderBy('claim.id')
            ->get([
                'claim.id',
                'claim.pembayaran_id',
                'claim.qty_claim',
                'claim.nilai_realisasi',
                'claim.claimed_at',
                'deposit.pasien_id',
                'deposit.treatment_id',
                'deposit.nama_treatment',
                'deposit.harga_satuan',
                'mt.kode_accurate',
                'mt.nama as master_nama_treatment',
                'mtt.tarif as tarif_treatment',
            ])
            ->map(function (object $row): array {
                $qty = (float) $row->qty_claim;
                $nilaiRealisasi = (float) $row->nilai_realisasi;
                $hargaTreatment = (float) (
                    $row->tarif_treatment
                    ?: $row->harga_satuan
                    ?: ($qty > 0 ? $nilaiRealisasi / $qty : 0)
                );
                $namaTreatment = trim((string) ($row->master_nama_treatment ?: $row->nama_treatment));
                $kodeAccurate = trim((string) $row->kode_accurate);

                return [
                    'source' => 'deposit_claim',
                    'id' => (int) $row->id,
                    'invoice_key' => $row->pembayaran_id
                        ? 'P-' . (int) $row->pembayaran_id
                        : 'C-' . (int) $row->id,
                    'pembayaran_id' => $row->pembayaran_id ? (int) $row->pembayaran_id : null,
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'tanggal_raw' => Carbon::parse($row->claimed_at)->toDateString(),
                    'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
                    'treatment_key' => $this->treatmentKey(
                        $row->treatment_id,
                        $kodeAccurate,
                        $namaTreatment
                    ),
                    'nama_treatment' => $namaTreatment !== '' ? $namaTreatment : '-',
                    'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                    'jenis_transaksi_id' => 4,
                    'is_premier' => false,
                    'is_deposit_claim' => true,
                    'qty' => $qty,
                    'harga_treatment' => $hargaTreatment,
                    'gross_amount' => $qty * $hargaTreatment,
                    'total_diskon' => 0.0,
                    'subtotal' => $nilaiRealisasi,
                ];
            })
            ->values();
    }

    private function buildReport(Collection $detailRows, array $filters): array
    {
        $rows = $detailRows
            ->groupBy('treatment_key')
            ->map(function (Collection $items): array {
                $first = $items->first();
                $jumlahBiasa = (float) $items
                    ->filter(fn (array $item): bool => ! $item['is_deposit_claim'] && ! $item['is_premier'])
                    ->sum('qty');
                $jumlahPremiere = (float) $items
                    ->filter(fn (array $item): bool => ! $item['is_deposit_claim'] && $item['is_premier'])
                    ->sum('qty');
                $jumlahRealisasiDeposit = (float) $items
                    ->where('is_deposit_claim', true)
                    ->sum('qty');
                $jumlahTotal = $jumlahBiasa + $jumlahPremiere + $jumlahRealisasiDeposit;

                $pricedItems = $items->filter(
                    fn (array $item): bool => (float) $item['qty'] > 0
                        && (float) $item['harga_treatment'] > 0
                );
                $priceQty = (float) $pricedItems->sum('qty');
                $weightedPrice = $priceQty > 0
                    ? (float) $pricedItems->sum(
                        fn (array $item): float => (float) $item['qty']
                            * (float) $item['harga_treatment']
                    ) / $priceQty
                    : 0.0;

                return [
                    'treatment_id' => $first['treatment_id'],
                    'nama_treatment' => $first['nama_treatment'],
                    'kode_accurate' => $first['kode_accurate'],
                    'jumlah_biasa' => $jumlahBiasa,
                    'jumlah_premiere' => $jumlahPremiere,
                    'jumlah_realisasi_deposit' => $jumlahRealisasiDeposit,
                    'jumlah_total' => $jumlahTotal,
                    'harga_treatment' => round($weightedPrice, 2),
                    'akumulasi_diskon' => (float) $items->sum('total_diskon'),
                    'total_harga' => (float) $items->sum('subtotal'),
                ];
            })
            ->sortBy(fn (array $row): string => mb_strtoupper($row['nama_treatment']))
            ->values()
            ->map(function (array $row, int $index): array {
                $row['no'] = $index + 1;

                return $row;
            });

        $publicFilters = $this->publicFilters($filters);
        $periodLabel = Carbon::parse($filters['tanggal_awal'])
            ->locale('id')
            ->translatedFormat('j F Y')
            . ' s/d '
            . Carbon::parse($filters['tanggal_akhir'])
                ->locale('id')
                ->translatedFormat('j F Y');

        $filenameBase = implode('-', [
            'data-laporan-treatment',
            $filters['jenis_transaksi'] === null
                ? 'semua-jenis-transaksi'
                : $this->slug($publicFilters['jenis_transaksi_label']),
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]);

        return [
            'title' => 'DATA LAPORAN TREATMENT',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'period_label' => $periodLabel,
            'branch_label' => $publicFilters['toko_nama']
                ? 'MS GLOW AESTHETIC ' . mb_strtoupper($publicFilters['toko_nama'])
                : 'SEMUA CABANG / SESUAI AKSES',
            'jenis_transaksi_label' => $publicFilters['jenis_transaksi_label'],
            'generated_at' => now()->format('d/m/Y H:i'),
            'filename_base' => $filenameBase,
            'rows' => $rows->all(),
            'totals' => [
                'jumlah_biasa' => (float) $rows->sum('jumlah_biasa'),
                'jumlah_premiere' => (float) $rows->sum('jumlah_premiere'),
                'jumlah_realisasi_deposit' => (float) $rows->sum('jumlah_realisasi_deposit'),
                'jumlah_total' => (float) $rows->sum('jumlah_total'),
                'akumulasi_diskon' => (float) $rows->sum('akumulasi_diskon'),
                'total_harga' => (float) $rows->sum('total_harga'),
            ],
        ];
    }

    private function treatmentKey($treatmentId, ?string $kodeAccurate, string $namaTreatment): string
    {
        if (! empty($treatmentId)) {
            return 'T-' . (int) $treatmentId;
        }

        $kode = mb_strtoupper(trim((string) $kodeAccurate));
        if ($kode !== '' && $kode !== '-') {
            return 'K-' . $kode;
        }

        return 'N-' . mb_strtoupper(trim($namaTreatment));
    }

    private function getJenisTransaksiOptions(): Collection
    {
        $rows = DB::table('master_jenis_transaksi')
            ->whereIn('id', self::ALLOWED_JENIS_TRANSAKSI)
            ->where('is_active', 1)
            ->where('is_delete', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'kode_jenis_transaksi',
                'nama_jenis_transaksi',
                'deskripsi',
            ]);

        $existingIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $missing = collect(self::ALLOWED_JENIS_TRANSAKSI)
            ->reject(fn (int $id): bool => in_array($id, $existingIds, true))
            ->map(fn (int $id): object => (object) [
                'id' => $id,
                'kode_jenis_transaksi' => $this->defaultJenisTransaksiKode($id),
                'nama_jenis_transaksi' => $this->defaultJenisTransaksiLabel($id),
                'deskripsi' => null,
            ]);

        return $rows->concat($missing)->sortBy('id')->values();
    }

    private function jenisTransaksiLabel($jenisTransaksi): string
    {
        if ($jenisTransaksi === null || $jenisTransaksi === '') {
            return 'Semua jenis transaksi';
        }

        $row = DB::table('master_jenis_transaksi')
            ->where('id', (int) $jenisTransaksi)
            ->first(['nama_jenis_transaksi']);

        return $row->nama_jenis_transaksi
            ?? $this->defaultJenisTransaksiLabel((int) $jenisTransaksi);
    }

    private function defaultJenisTransaksiLabel(int $id): string
    {
        return match ($id) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit / Realisasi Deposit',
            default => 'Tidak diketahui',
        };
    }

    private function defaultJenisTransaksiKode(int $id): string
    {
        return match ($id) {
            0 => 'UMUM',
            1 => 'ENDORSE_FASKAR',
            2 => 'ELITEGLOWBAL',
            3 => 'OWNER',
            4 => 'DEPOSIT',
            default => 'UNKNOWN',
        };
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return $slug !== '' ? $slug : 'laporan';
    }
}
