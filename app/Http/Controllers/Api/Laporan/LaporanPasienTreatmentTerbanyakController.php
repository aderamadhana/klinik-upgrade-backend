<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanPasienTreatmentTerbanyakExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanPasienTreatmentTerbanyakController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly LaporanPasienTreatmentTerbanyakExportService $exportService
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
        $rows = $this->getRows($filters);
        $jenisOptions = $this->getJenisTransaksiOptions();

        return response()->json([
            'status' => true,
            'message' => 'Data pasien treatment terbanyak berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_pasien' => $rows->count(),
                'total_invoice' => (int) $rows->sum('total_invoice'),
                'total_hari_transaksi' => (int) $rows->sum('total_hari_transaksi'),
                'total_qty_treatment' => (float) $rows->sum('total_qty_treatment'),
                'total_nilai_treatment' => (float) $rows->sum('total_nilai_treatment'),
                'rata_qty_per_pasien' => $rows->count() > 0
                    ? round((float) $rows->sum('total_qty_treatment') / $rows->count(), 2)
                    : 0,
                'rows' => $rows->values(),
                'top_pasien' => $rows->first(),
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
        $rows = $this->getRows($filters);
        $report = $this->buildReport($rows, $filters);

        return $format === 'excel'
            ? $this->exportService->excel($report)
            : $this->exportService->pdf($report);
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($tokoId) ? (int) $tokoId : null;

        $peringkat = $request->query('peringkat', $request->query('limit'));
        if ($peringkat === null || $peringkat === '') {
            $peringkat = 10;
        } elseif (is_numeric($peringkat)) {
            $peringkat = (int) $peringkat;
        }

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
            'peringkat' => $peringkat,
            'toko_id' => $tokoId,
            'jenis_transaksi' => $jenisTransaksi,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'peringkat' => ['required', 'integer', 'min:1', 'max:1000'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            'jenis_transaksi' => ['nullable', 'integer', 'in:0,1,2,3,4'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'peringkat.min' => 'Peringkat minimal 1.',
            'peringkat.max' => 'Peringkat maksimal 1000 agar laporan tetap ringan.',
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
            'peringkat' => (int) $filters['peringkat'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getRows(array $filters): Collection
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $details = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'pii.treatment_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pii.jenis_transaksi')
            ->leftJoin('master_karyawan as dokter', function ($join): void {
                $join->on(
                    'dokter.id',
                    '=',
                    DB::raw('COALESCE(pii.dokter_id, pi.dokter_id, pi.referensi_dokter_id)')
                );
            })
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'pii.perawat_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 2)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereIn('pii.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->when(! empty($filters['toko_id']), function ($query) use ($filters): void {
                $query->where('pi.toko_id', (int) $filters['toko_id']);
            })
            ->when($filters['jenis_transaksi'] !== null, function ($query) use ($filters): void {
                $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
            })
            ->selectRaw("
                pi.id as pembayaran_id,
                pi.no_invoice,
                pi.kode_registrasi,
                pi.toko_id,
                toko.nama_toko,
                pi.pasien_id,
                pasien.no_rm,
                pasien.nama as nama_pasien,
                pasien.no_hp,
                pasien.no_wa,
                DATE({$tanggalSql}) as tanggal_transaksi,
                pii.id as item_id,
                pii.treatment_id,
                COALESCE(treatment.nama, pii.nama_item) as nama_treatment,
                pii.nama_item,
                pii.qty,
                pii.harga,
                pii.diskon_amount,
                pii.diskon_referral,
                pii.diskon_subtotal_amount,
                pii.subtotal,
                pii.jenis_transaksi as jenis_transaksi_id,
                COALESCE(jt.nama_jenis_transaksi, CONCAT('Jenis ', pii.jenis_transaksi)) as jenis_transaksi_nama,
                dokter.nama as nama_dokter,
                perawat.nama as nama_perawat
            ")
            ->orderBy('pasien.nama')
            ->orderBy('pi.id')
            ->get();

        $rows = $details
            ->groupBy('pasien_id')
            ->map(function (Collection $items): array {
                $first = $items->first();

                $treatments = $items
                    ->groupBy(function (object $row): string {
                        return ($row->treatment_id ?: 'item-' . $row->nama_treatment)
                            . '|'
                            . $row->nama_treatment;
                    })
                    ->map(function (Collection $treatmentItems): array {
                        $firstTreatment = $treatmentItems->first();

                        return [
                            'treatment_id' => $firstTreatment->treatment_id,
                            'nama_treatment' => $firstTreatment->nama_treatment
                                ?: $firstTreatment->nama_item,
                            'total_qty' => (float) $treatmentItems->sum('qty'),
                            'total_net' => (float) $treatmentItems->sum('subtotal'),
                        ];
                    })
                    ->sortByDesc('total_qty')
                    ->values();

                $topTreatment = $treatments->first();
                $cabangs = $items->pluck('nama_toko')->filter()->unique()->values();
                $jenisTransaksi = $items->pluck('jenis_transaksi_nama')->filter()->unique()->values();
                $dokters = $items->pluck('nama_dokter')->filter()->unique()->values();
                $perawats = $items->pluck('nama_perawat')->filter()->unique()->values();

                return [
                    'peringkat' => 0,
                    'pasien_id' => $first->pasien_id,
                    'no_rm' => $first->no_rm ?: '-',
                    'nama_pasien' => $first->nama_pasien ?: '-',
                    'no_hp' => $this->normalizePhone($first->no_wa ?: $first->no_hp),
                    'cabang' => $cabangs->isNotEmpty() ? $cabangs->implode(', ') : '-',
                    'total_invoice' => $items->pluck('pembayaran_id')->unique()->count(),
                    'total_hari_transaksi' => $items->pluck('tanggal_transaksi')->unique()->count(),
                    'total_item_treatment' => $items->count(),
                    'total_qty_treatment' => (float) $items->sum('qty'),
                    'total_jenis_treatment' => $treatments->count(),
                    'total_nilai_treatment' => (float) $items->sum('subtotal'),
                    'rata_nilai_per_qty' => (float) $items->sum('qty') > 0
                        ? round((float) $items->sum('subtotal') / (float) $items->sum('qty'), 2)
                        : 0,
                    'treatment_terbanyak' => $topTreatment['nama_treatment'] ?? '-',
                    'qty_treatment_terbanyak' => (float) ($topTreatment['total_qty'] ?? 0),
                    'nilai_treatment_terbanyak' => (float) ($topTreatment['total_net'] ?? 0),
                    'jenis_transaksi' => $jenisTransaksi->isNotEmpty()
                        ? $jenisTransaksi->implode(', ')
                        : '-',
                    'dokter' => $dokters->isNotEmpty() ? $dokters->implode(', ') : '-',
                    'perawat' => $perawats->isNotEmpty() ? $perawats->implode(', ') : '-',
                    'invoice_terkait' => $items
                        ->pluck('no_invoice')
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', '),
                    'treatment_detail' => $treatments
                        ->take(5)
                        ->map(function (array $item): string {
                            return $item['nama_treatment']
                                . ' ('
                                . $this->formatNumber($item['total_qty'])
                                . 'x)';
                        })
                        ->implode(', '),
                ];
            })
            ->sort(function (array $a, array $b): int {
                $qtyCompare = $b['total_qty_treatment'] <=> $a['total_qty_treatment'];

                if ($qtyCompare !== 0) {
                    return $qtyCompare;
                }

                $valueCompare = $b['total_nilai_treatment'] <=> $a['total_nilai_treatment'];

                if ($valueCompare !== 0) {
                    return $valueCompare;
                }

                return strcmp($a['nama_pasien'], $b['nama_pasien']);
            })
            ->values()
            ->take((int) $filters['peringkat'])
            ->values();

        return $rows->map(function (array $row, int $index): array {
            $row['peringkat'] = $index + 1;

            return $row;
        });
    }

    private function buildReport(Collection $rows, array $filters): array
    {
        $publicFilters = $this->publicFilters($filters);
        $branchName = trim((string) ($publicFilters['toko_nama'] ?? ''));

        return [
            'title' => 'DATA PASIEN TREATMENT TERBANYAK',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $branchName !== ''
                ? 'MS GLOW AESTHETIC ' . mb_strtoupper($branchName)
                : 'SEMUA CABANG',
            'period_label' => $this->formatDateLong($filters['tanggal_awal'])
                . ' s/d '
                . $this->formatDateLong($filters['tanggal_akhir']),
            'jenis_transaksi_label' => $publicFilters['jenis_transaksi_label'],
            'peringkat_label' => 'Top ' . (int) $filters['peringkat'],
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-pasien-treatment-terbanyak-%s-sd-%s-top-%d',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
                (int) $filters['peringkat']
            ),
            'rows' => $rows
                ->map(function (array $row): array {
                    return [
                        'no' => (int) $row['peringkat'],
                        'nama_pasien' => $row['nama_pasien'],
                        'jumlah_transaksi' => (int) $row['total_invoice'],
                        'total_nominal' => (float) $row['total_nilai_treatment'],
                    ];
                })
                ->values()
                ->all(),
            'totals' => [
                'jumlah_transaksi' => (int) $rows->sum('total_invoice'),
                'total_nominal' => (float) $rows->sum('total_nilai_treatment'),
            ],
        ];
    }

    private function getJenisTransaksiOptions(): Collection
    {
        $rows = DB::table('master_jenis_transaksi')
            ->whereIn('id', self::ALLOWED_JENIS_TRANSAKSI)
            ->where('is_active', 1)
            ->where('is_delete', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'kode_jenis_transaksi', 'nama_jenis_transaksi']);

        if ($rows->count() === count(self::ALLOWED_JENIS_TRANSAKSI)) {
            return $rows;
        }

        return collect(self::ALLOWED_JENIS_TRANSAKSI)->map(function (int $id) use ($rows): object {
            $row = $rows->firstWhere('id', $id);

            return (object) [
                'id' => $id,
                'kode_jenis_transaksi' => $row->kode_jenis_transaksi
                    ?? $this->defaultJenisTransaksiCode($id),
                'nama_jenis_transaksi' => $row->nama_jenis_transaksi
                    ?? $this->defaultJenisTransaksiLabel($id),
            ];
        });
    }

    private function jenisTransaksiLabel($id): string
    {
        if ($id === null || $id === '' || $id === 'all') {
            return 'Semua jenis transaksi';
        }

        $row = DB::table('master_jenis_transaksi')
            ->where('id', (int) $id)
            ->where('is_delete', 0)
            ->first(['nama_jenis_transaksi']);

        return $row->nama_jenis_transaksi
            ?? $this->defaultJenisTransaksiLabel((int) $id);
    }

    private function defaultJenisTransaksiCode(int $id): string
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

    private function defaultJenisTransaksiLabel(int $id): string
    {
        return match ($id) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Jenis ' . $id,
        };
    }

    private function normalizePhone(?string $value): string
    {
        $phone = preg_replace('/\s+/', '', (string) $value);

        if ($phone === '') {
            return '-';
        }

        if (str_starts_with($phone, '8')) {
            return '62' . $phone;
        }

        if (str_starts_with($phone, '0')) {
            return '62' . substr($phone, 1);
        }

        return $phone;
    }

    private function formatDateLong(string $value): string
    {
        return Carbon::parse($value)
            ->locale('id')
            ->translatedFormat('j F Y');
    }

    private function formatNumber($value): string
    {
        $number = (float) $value;
        $decimals = floor($number) === $number ? 0 : 2;

        return number_format($number, $decimals, ',', '.');
    }
}
