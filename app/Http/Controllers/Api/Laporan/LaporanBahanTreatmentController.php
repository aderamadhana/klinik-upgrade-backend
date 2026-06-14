<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanBahanTreatmentExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanBahanTreatmentController extends Controller
{
    private const STATUS_BAHAN_SUDAH_DIISI = 1;
    private const STATUS_REGISTRASI_BATAL = 9;
    private const STATUS_TREATMENT_BATAL = 9;
    private const STATUS_INVOICE_LUNAS = 3;
    private const STATUS_ITEM_AKTIF = 1;

    public function __construct(
        private readonly LaporanBahanTreatmentExportService $exportService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $normalized = $this->normalizeFilters($request);

            if ($normalized['validator']->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Filter laporan bahan treatment tidak valid.',
                    'errors' => $normalized['validator']->errors(),
                ], 422);
            }

            $filters = $normalized['data'];
            $detailRows = $this->getDetailRows($filters);
            $payload = $filters['jenis'] === 'rekap'
                ? $this->buildRecapPayload($detailRows)
                : $this->buildDetailPayload($detailRows);

            return response()->json([
                'status' => true,
                'message' => 'Laporan bahan treatment berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'jenis' => $filters['jenis'],
                    ...$payload,
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan bahan treatment.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request, string $jenis, string $format)
    {
        try {
            $request->merge([
                'jenis' => strtolower($jenis),
            ]);

            $format = strtolower($format);

            if (! in_array($format, ['pdf', 'excel'], true)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Format export harus pdf atau excel.',
                ], 422);
            }

            $normalized = $this->normalizeFilters($request);

            if ($normalized['validator']->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Filter laporan bahan treatment tidak valid.',
                    'errors' => $normalized['validator']->errors(),
                ], 422);
            }

            $filters = $normalized['data'];
            $detailRows = $this->getDetailRows($filters);
            $report = $this->buildReport($filters, $detailRows);

            return $format === 'pdf'
                ? $this->exportService->pdf($report)
                : $this->exportService->excel($report);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mencetak laporan bahan treatment.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $rawTokoId = $request->input('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($rawTokoId) && (int) $rawTokoId > 0
            ? (int) $rawTokoId
            : null;

        $data = [
            'jenis' => strtolower((string) $request->input('jenis', 'detail')),
            'tanggal_awal' => (string) $request->input('tanggal_awal', $monthStart),
            'tanggal_akhir' => (string) $request->input('tanggal_akhir', $today),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'jenis' => ['required', 'in:detail,rekap'],
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'jenis.in' => 'Jenis laporan harus detail atau rekap.',
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
        ]);

        return compact('validator', 'data');
    }

    private function getDetailRows(array $filters): Collection
    {
        $startAt = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->startOfDay();
        $endAt = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->endOfDay();

        $invoiceByTreatment = DB::table('pembayaran_invoice_item as invoice_item')
            ->join('pembayaran_invoice as invoice', 'invoice.id', '=', 'invoice_item.pembayaran_id')
            ->where('invoice.status', self::STATUS_INVOICE_LUNAS)
            ->where('invoice.is_delete', 0)
            ->where('invoice_item.status', self::STATUS_ITEM_AKTIF)
            ->where('invoice_item.is_delete', 0)
            ->whereIn('invoice_item.item_type', [2, 4])
            ->whereNotNull('invoice_item.source_detail_id')
            ->groupBy('invoice_item.registrasi_id', 'invoice_item.source_detail_id')
            ->selectRaw('invoice_item.registrasi_id')
            ->selectRaw('invoice_item.source_detail_id')
            ->selectRaw(
                "GROUP_CONCAT(DISTINCT invoice.no_invoice ORDER BY invoice.no_invoice SEPARATOR ', ') as no_invoice"
            );

        $invoiceByRegistration = DB::table('pembayaran_invoice as invoice')
            ->where('invoice.status', self::STATUS_INVOICE_LUNAS)
            ->where('invoice.is_delete', 0)
            ->groupBy('invoice.registrasi_id')
            ->selectRaw('invoice.registrasi_id')
            ->selectRaw(
                "GROUP_CONCAT(DISTINCT invoice.no_invoice ORDER BY invoice.no_invoice SEPARATOR ', ') as no_invoice"
            );

        return DB::table('registrasi_perawat_bahan_treatment_detail as bahan')
            ->join('registrasi_kunjungan as registrasi', 'registrasi.id', '=', 'bahan.registrasi_id')
            ->join('registrasi_treatment_detail as treatment_detail', 'treatment_detail.id', '=', 'bahan.treatment_detail_id')
            ->join('pasien as pasien', 'pasien.id', '=', 'registrasi.pasien_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'bahan.treatment_id')
            ->leftJoin('master_perawat_bahan as bahan_master', 'bahan_master.id', '=', 'bahan.perawat_bahan_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'bahan.toko_id')
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'bahan.perawat_id')
            ->leftJoinSub($invoiceByTreatment, 'invoice_treatment', function ($join): void {
                $join->on('invoice_treatment.registrasi_id', '=', 'bahan.registrasi_id')
                    ->on('invoice_treatment.source_detail_id', '=', 'bahan.treatment_detail_id');
            })
            ->leftJoinSub($invoiceByRegistration, 'invoice_registrasi', function ($join): void {
                $join->on('invoice_registrasi.registrasi_id', '=', 'bahan.registrasi_id');
            })
            ->where('bahan.status', self::STATUS_BAHAN_SUDAH_DIISI)
            ->where('bahan.is_delete', 0)
            ->where('bahan.jumlah_terpakai', '>', 0)
            ->whereNotNull('bahan.tanggal_pengisian')
            ->whereBetween('bahan.tanggal_pengisian', [$startAt, $endAt])
            ->where('registrasi.is_delete', 0)
            ->where('registrasi.status', '<>', self::STATUS_REGISTRASI_BATAL)
            ->where('treatment_detail.is_delete', 0)
            ->where('treatment_detail.status', '<>', self::STATUS_TREATMENT_BATAL)
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('bahan.toko_id', $filters['toko_id'])
            )
            ->orderBy('bahan.tanggal_pengisian')
            ->orderBy('bahan.registrasi_id')
            ->orderBy('bahan.treatment_detail_id')
            ->orderByRaw("COALESCE(NULLIF(bahan_master.kode_accurate_obat_bahan, ''), 'ZZZZZZ')")
            ->orderBy('bahan.nama_bahan')
            ->get([
                'bahan.id',
                'bahan.registrasi_id',
                'bahan.treatment_detail_id',
                'bahan.treatment_id',
                'bahan.perawat_bahan_id',
                'bahan.jumlah_terpakai',
                'bahan.satuan as satuan_snapshot',
                'bahan.nama_bahan as nama_bahan_snapshot',
                'bahan.tanggal_pengisian',
                'bahan.toko_id',
                'registrasi.kode_registrasi',
                'registrasi.tanggal_kunjungan',
                'registrasi.pasien_id',
                'pasien.no_rm',
                'pasien.nama as nama_pasien',
                'treatment_detail.nama_treatment as nama_treatment_snapshot',
                'treatment.kode_accurate as kode_treatment',
                'treatment.nama as nama_treatment_master',
                'bahan_master.kode_accurate_obat_bahan as kode_bahan',
                'bahan_master.nama_bahan as nama_bahan_master',
                'bahan_master.satuan as satuan_master',
                'toko.nama_toko',
                'perawat.nama as nama_perawat',
                'invoice_treatment.no_invoice as no_invoice_treatment',
                'invoice_registrasi.no_invoice as no_invoice_registrasi',
            ])
            ->map(function (object $row): array {
                $treatmentCode = trim((string) ($row->kode_treatment ?? ''));
                $treatmentNameSnapshot = trim((string) ($row->nama_treatment_snapshot ?? ''));
                $treatmentNameMaster = trim((string) ($row->nama_treatment_master ?? ''));
                $materialCode = trim((string) ($row->kode_bahan ?? ''));
                $materialNameSnapshot = trim((string) ($row->nama_bahan_snapshot ?? ''));
                $materialNameMaster = trim((string) ($row->nama_bahan_master ?? ''));
                $unitSnapshot = trim((string) ($row->satuan_snapshot ?? ''));
                $unitMaster = trim((string) ($row->satuan_master ?? ''));
                $invoiceTreatment = trim((string) ($row->no_invoice_treatment ?? ''));
                $invoiceRegistration = trim((string) ($row->no_invoice_registrasi ?? ''));

                return [
                    'id' => (int) $row->id,
                    'registrasi_id' => (int) $row->registrasi_id,
                    'pasien_id' => (int) $row->pasien_id,
                    'treatment_detail_id' => (int) $row->treatment_detail_id,
                    'treatment_id' => (int) $row->treatment_id,
                    'perawat_bahan_id' => (int) $row->perawat_bahan_id,
                    'no_invoice' => $invoiceTreatment !== ''
                        ? $invoiceTreatment
                        : ($invoiceRegistration !== ''
                            ? $invoiceRegistration
                            : ($row->kode_registrasi ?: '-')),
                    'kode_registrasi' => $row->kode_registrasi ?: '-',
                    'tanggal' => Carbon::parse($row->tanggal_pengisian)->toDateString(),
                    'tanggal_kunjungan' => $row->tanggal_kunjungan
                        ? Carbon::parse($row->tanggal_kunjungan)->toDateString()
                        : null,
                    'no_rm' => $row->no_rm ?: '-',
                    'nama_pasien' => $row->nama_pasien ?: '-',
                    'kode_treatment' => $treatmentCode !== '' ? $treatmentCode : '-',
                    'nama_treatment' => $treatmentNameSnapshot !== ''
                        ? $treatmentNameSnapshot
                        : ($treatmentNameMaster !== '' ? $treatmentNameMaster : '-'),
                    'kode_bahan' => $materialCode !== '' ? $materialCode : '-',
                    'nama_bahan' => $materialNameSnapshot !== ''
                        ? $materialNameSnapshot
                        : ($materialNameMaster !== '' ? $materialNameMaster : '-'),
                    'satuan' => $unitSnapshot !== ''
                        ? $unitSnapshot
                        : ($unitMaster !== '' ? $unitMaster : '-'),
                    'jumlah' => (float) $row->jumlah_terpakai,
                    'toko_id' => (int) $row->toko_id,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'perawat' => $row->nama_perawat ?: '-',
                ];
            })
            ->values();
    }

    private function buildDetailPayload(Collection $detailRows): array
    {
        $rows = $detailRows
            ->values()
            ->map(function (array $row, int $index): array {
                return [
                    'no' => $index + 1,
                    ...$row,
                ];
            });

        return [
            'totals' => [
                'total_registrasi' => $detailRows->pluck('registrasi_id')->unique()->count(),
                'total_pasien' => $detailRows->pluck('pasien_id')->filter()->unique()->count(),
                'total_treatment' => $detailRows->pluck('treatment_detail_id')->unique()->count(),
                'total_bahan' => $detailRows->count(),
                'total_jumlah' => (float) $detailRows->sum('jumlah'),
            ],
            'groups' => $this->buildDetailGroups($detailRows),
            'rows' => $rows,
        ];
    }

    private function buildRecapPayload(Collection $detailRows): array
    {
        $groups = $this->buildRecapGroups($detailRows);
        $flatRows = collect($groups)
            ->flatMap(function (array $group): Collection {
                return collect($group['items'])->map(function (array $item) use ($group): array {
                    return [
                        'treatment_id' => $group['treatment_id'],
                        'kode_treatment' => $group['kode_treatment'],
                        'nama_treatment' => $group['nama_treatment'],
                        ...$item,
                    ];
                });
            })
            ->values()
            ->map(function (array $row, int $index): array {
                return [
                    'no_global' => $index + 1,
                    ...$row,
                ];
            });

        return [
            'totals' => [
                'total_registrasi' => $detailRows->pluck('registrasi_id')->unique()->count(),
                'total_pasien' => $detailRows->pluck('pasien_id')->filter()->unique()->count(),
                'total_treatment' => count($groups),
                'total_bahan' => $flatRows->count(),
                'total_jumlah' => (float) $flatRows->sum('total_jumlah'),
                'total_frekuensi' => (int) $flatRows->sum('frekuensi'),
            ],
            'groups' => $groups,
            'rows' => $flatRows,
        ];
    }

    private function buildDetailGroups(Collection $detailRows): array
    {
        return $detailRows
            ->groupBy(function (array $row): string {
                return $row['registrasi_id'] . '|' . $row['no_invoice'];
            })
            ->map(function (Collection $registrationRows): array {
                $first = $registrationRows->first();
                $treatments = $registrationRows
                    ->groupBy('treatment_detail_id')
                    ->map(function (Collection $treatmentRows): array {
                        $treatmentFirst = $treatmentRows->first();
                        $items = $treatmentRows
                            ->values()
                            ->map(function (array $row, int $index): array {
                                return [
                                    'no' => $index + 1,
                                    'id' => $row['id'],
                                    'perawat_bahan_id' => $row['perawat_bahan_id'],
                                    'kode_bahan' => $row['kode_bahan'],
                                    'nama_bahan' => $row['nama_bahan'],
                                    'satuan' => $row['satuan'],
                                    'jumlah' => $row['jumlah'],
                                ];
                            })
                            ->all();

                        return [
                            'treatment_detail_id' => $treatmentFirst['treatment_detail_id'],
                            'treatment_id' => $treatmentFirst['treatment_id'],
                            'kode_treatment' => $treatmentFirst['kode_treatment'],
                            'nama_treatment' => $treatmentFirst['nama_treatment'],
                            'total_bahan' => count($items),
                            'total_jumlah' => (float) collect($items)->sum('jumlah'),
                            'items' => $items,
                        ];
                    })
                    ->sortBy(function (array $item): string {
                        return mb_strtolower($item['kode_treatment'] . ' ' . $item['nama_treatment']);
                    })
                    ->values()
                    ->all();

                return [
                    'registrasi_id' => $first['registrasi_id'],
                    'no_invoice' => $first['no_invoice'],
                    'kode_registrasi' => $first['kode_registrasi'],
                    'tanggal' => $first['tanggal'],
                    'no_rm' => $first['no_rm'],
                    'nama_pasien' => $first['nama_pasien'],
                    'perawat' => $first['perawat'],
                    'toko_id' => $first['toko_id'],
                    'toko_nama' => $first['toko_nama'],
                    'total_treatment' => count($treatments),
                    'total_bahan' => $registrationRows->count(),
                    'treatments' => $treatments,
                ];
            })
            ->sortBy(function (array $item): string {
                return $item['tanggal'] . ' ' . $item['no_invoice'] . ' ' . $item['nama_pasien'];
            })
            ->values()
            ->all();
    }

    private function buildRecapGroups(Collection $detailRows): array
    {
        return $detailRows
            ->groupBy(function (array $row): string {
                return $row['treatment_id'] . '|' . $row['kode_treatment'] . '|' . $row['nama_treatment'];
            })
            ->map(function (Collection $treatmentRows): array {
                $first = $treatmentRows->first();
                $items = $treatmentRows
                    ->groupBy(function (array $row): string {
                        return $row['perawat_bahan_id'] . '|' . $row['kode_bahan'] . '|' . $row['satuan'];
                    })
                    ->map(function (Collection $materialRows): array {
                        $materialFirst = $materialRows->first();

                        return [
                            'perawat_bahan_id' => $materialFirst['perawat_bahan_id'],
                            'kode_bahan' => $materialFirst['kode_bahan'],
                            'nama_bahan' => $materialFirst['nama_bahan'],
                            'satuan' => $materialFirst['satuan'],
                            'total_jumlah' => (float) $materialRows->sum('jumlah'),
                            'frekuensi' => $materialRows->pluck('treatment_detail_id')->unique()->count(),
                        ];
                    })
                    ->sortBy(function (array $item): string {
                        return mb_strtolower($item['kode_bahan'] . ' ' . $item['nama_bahan']);
                    })
                    ->values()
                    ->map(function (array $item, int $index): array {
                        return [
                            'no' => $index + 1,
                            ...$item,
                        ];
                    })
                    ->all();

                return [
                    'treatment_id' => $first['treatment_id'],
                    'kode_treatment' => $first['kode_treatment'],
                    'nama_treatment' => $first['nama_treatment'],
                    'total_bahan' => count($items),
                    'total_jumlah' => (float) collect($items)->sum('total_jumlah'),
                    'total_frekuensi' => (int) collect($items)->sum('frekuensi'),
                    'items' => $items,
                ];
            })
            ->sortBy(function (array $item): string {
                return mb_strtolower($item['kode_treatment'] . ' ' . $item['nama_treatment']);
            })
            ->values()
            ->all();
    }

    private function buildReport(array $filters, Collection $detailRows): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');
        $isRecap = $filters['jenis'] === 'rekap';
        $payload = $isRecap
            ? $this->buildRecapPayload($detailRows)
            : $this->buildDetailPayload($detailRows);

        return [
            'jenis' => $filters['jenis'],
            'title' => $isRecap
                ? 'LAPORAN REKAP BAHAN TREATMENT'
                : 'LAPORAN DETAIL BAHAN TREATMENT',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $this->branchLabel($filters['toko_id']),
            'period_label' => sprintf(
                '%s s/d %s',
                $start->translatedFormat('d/m/Y'),
                $end->translatedFormat('d/m/Y')
            ),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-%s-bahan-treatment-%s-sd-%s',
                $filters['jenis'],
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
            ...$payload,
        ];
    }

    private function publicFilters(array $filters): array
    {
        return [
            'jenis' => $filters['jenis'],
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'tanggal_berdasarkan' => 'Tanggal pengisian bahan treatment',
            'data_berdasarkan' => 'Bahan berstatus sudah diisi dengan jumlah terpakai lebih dari nol',
        ];
    }

    private function branchLabel(?int $tokoId): string
    {
        if (! $tokoId) {
            return 'SEMUA CABANG';
        }

        $branchName = DB::table('master_toko')
            ->where('id', $tokoId)
            ->value('nama_toko');

        return $branchName
            ? mb_strtoupper((string) $branchName)
            : 'CABANG TIDAK DITEMUKAN';
    }
}
