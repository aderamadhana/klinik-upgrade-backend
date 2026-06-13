<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanProdukObatExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanProdukObatController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly LaporanProdukObatExportService $exportService
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
        $salesRows = $this->getSalesRows($filters);
        $jenisOptions = $this->getJenisTransaksiOptions();
        $jenisLabels = $jenisOptions->pluck('nama_jenis_transaksi', 'id');

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan laporan obat/produk berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_item' => $salesRows->count(),
                'total_invoice' => $salesRows->pluck('pembayaran_id')->filter()->unique()->count(),
                'total_pasien' => $salesRows->pluck('pasien_id')->filter()->unique()->count(),
                'total_qty' => (float) $salesRows->sum('qty'),
                'total_gross' => (float) $salesRows->sum('gross_amount'),
                'total_diskon' => (float) $salesRows->sum('total_diskon'),
                'total_net' => (float) $salesRows->sum('subtotal'),
                'total_hpp' => (float) $salesRows->sum('hpp_amount'),
                'estimasi_margin' => (float) $salesRows->sum('estimasi_margin'),
                'by_jenis_transaksi' => collect(self::ALLOWED_JENIS_TRANSAKSI)
                    ->map(function (int $id) use ($salesRows, $jenisLabels): array {
                        $items = $salesRows->where('jenis_transaksi_id', $id);

                        return [
                            'id' => $id,
                            'nama' => $jenisLabels[$id] ?? $this->defaultJenisTransaksiLabel($id),
                            'total_item' => $items->count(),
                            'total_invoice' => $items->pluck('pembayaran_id')->filter()->unique()->count(),
                            'total_pasien' => $items->pluck('pasien_id')->filter()->unique()->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_gross' => (float) $items->sum('gross_amount'),
                            'total_diskon' => (float) $items->sum('total_diskon'),
                            'total_net' => (float) $items->sum('subtotal'),
                            'total_hpp' => (float) $items->sum('hpp_amount'),
                            'estimasi_margin' => (float) $items->sum('estimasi_margin'),
                        ];
                    })
                    ->values(),
                'by_kategori_produk' => $salesRows
                    ->groupBy('kategori_produk')
                    ->map(function (Collection $items, string $kategori): array {
                        return [
                            'kategori_produk' => $kategori !== '' ? $kategori : '-',
                            'total_item' => $items->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_net' => (float) $items->sum('subtotal'),
                            'estimasi_margin' => (float) $items->sum('estimasi_margin'),
                        ];
                    })
                    ->sortByDesc('total_net')
                    ->values(),
                'top_produk' => $salesRows
                    ->groupBy('produk_key')
                    ->map(function (Collection $items): array {
                        $first = $items->first();

                        return [
                            'produk_id' => $first['produk_id'],
                            'nama_produk' => $first['nama_produk'],
                            'kategori_produk' => $first['kategori_produk'],
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
        $salesRows = $this->getSalesRows($filters);
        $stockInRows = $this->getStockInRows($filters);
        $report = $this->buildReport($salesRows, $stockInRows, $filters);

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
            'tanggal_berdasarkan' => 'Tanggal lunas invoice dan tanggal mutasi stok',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getSalesRows(array $filters): Collection
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';
        $isPremierSql = Schema::hasColumn('pembayaran_invoice', 'is_premier')
            ? 'COALESCE(pi.is_premier, 0)'
            : '0';

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_produk as mp', 'mp.id', '=', 'pii.produk_id')
            ->leftJoin('master_produk_toko as mpt', 'mpt.id', '=', 'pii.produk_toko_id')
            ->leftJoin('master_kategori_produk as mkp', 'mkp.id', '=', 'mp.kategori_produk_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 3)
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
                'pi.toko_id',
                'pi.no_invoice',
                'pii.produk_id',
                'pii.produk_toko_id',
                'pii.nama_item',
                'pii.qty',
                'pii.harga',
                'pii.diskon_amount',
                'pii.diskon_referral',
                'pii.diskon_subtotal_amount',
                'pii.subtotal',
                'pii.jenis_transaksi',
                'pii.kode_accurate_snapshot',
                'mp.kode_accurate as master_kode_accurate',
                'mp.nama as master_nama_produk',
                'mkp.nama_kategori_produk',
                'mpt.harga_jual as harga_jual_master',
                'mpt.harga_beli as harga_beli_master',
                DB::raw("DATE({$tanggalSql}) as tanggal_raw"),
                DB::raw("{$isPremierSql} as is_premier"),
            ])
            ->map(function (object $row): array {
                $qty = (float) $row->qty;
                $harga = (float) $row->harga;
                $hargaBeli = (float) $row->harga_beli_master;
                $namaProduk = trim((string) ($row->master_nama_produk ?: $row->nama_item));
                $kodeAccurate = trim((string) ($row->kode_accurate_snapshot ?: $row->master_kode_accurate));
                $kategori = trim((string) $row->nama_kategori_produk);
                $totalDiskon = (float) $row->diskon_amount
                    + (float) $row->diskon_referral
                    + (float) $row->diskon_subtotal_amount;

                return [
                    'id' => (int) $row->id,
                    'pembayaran_id' => (int) $row->pembayaran_id,
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'toko_id' => (int) $row->toko_id,
                    'tanggal_raw' => $row->tanggal_raw,
                    'produk_id' => $row->produk_id ? (int) $row->produk_id : null,
                    'produk_toko_id' => $row->produk_toko_id ? (int) $row->produk_toko_id : null,
                    'produk_key' => $this->productKey(
                        $row->produk_toko_id,
                        $row->produk_id,
                        $row->toko_id,
                        $kodeAccurate,
                        $namaProduk
                    ),
                    'nama_produk' => $namaProduk !== '' ? $namaProduk : '-',
                    'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                    'kategori_produk' => $kategori !== '' ? $kategori : '-',
                    'jenis_transaksi_id' => (int) $row->jenis_transaksi,
                    'is_premier' => (int) $row->is_premier === 1,
                    'qty' => $qty,
                    'harga' => $harga,
                    'harga_jual_master' => (float) $row->harga_jual_master,
                    'harga_beli_master' => $hargaBeli,
                    'gross_amount' => $qty * $harga,
                    'total_diskon' => $totalDiskon,
                    'subtotal' => (float) $row->subtotal,
                    'hpp_amount' => $qty * $hargaBeli,
                    'estimasi_margin' => (float) $row->subtotal - ($qty * $hargaBeli),
                ];
            })
            ->values();
    }

    private function getStockInRows(array $filters): Collection
    {
        $query = DB::table('stock_mutasi_produk as smp')
            ->leftJoin('master_produk as mp', 'mp.id', '=', 'smp.produk_id')
            ->leftJoin('master_produk_toko as mpt', 'mpt.id', '=', 'smp.produk_toko_id')
            ->leftJoin('master_kategori_produk as mkp', 'mkp.id', '=', 'mp.kategori_produk_id')
            ->where('smp.is_void', 0)
            ->where('smp.qty_masuk', '>', 0)
            ->where('smp.tipe_mutasi', '!=', 'STOK_AWAL')
            ->whereRaw('DATE(smp.tanggal) BETWEEN ? AND ?', [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('smp.toko_id', (int) $filters['toko_id']);
        }

        return $query
            ->get([
                'smp.produk_toko_id',
                'smp.produk_id',
                'smp.toko_id',
                'smp.qty_masuk',
                'mp.kode_accurate',
                'mp.nama as nama_produk',
                'mkp.nama_kategori_produk',
                'mpt.harga_jual',
            ])
            ->map(function (object $row): array {
                $namaProduk = trim((string) $row->nama_produk);
                $kodeAccurate = trim((string) $row->kode_accurate);

                return [
                    'produk_toko_id' => $row->produk_toko_id ? (int) $row->produk_toko_id : null,
                    'produk_id' => $row->produk_id ? (int) $row->produk_id : null,
                    'toko_id' => (int) $row->toko_id,
                    'produk_key' => $this->productKey(
                        $row->produk_toko_id,
                        $row->produk_id,
                        $row->toko_id,
                        $kodeAccurate,
                        $namaProduk
                    ),
                    'nama_produk' => $namaProduk !== '' ? $namaProduk : '-',
                    'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                    'kategori_produk' => $row->nama_kategori_produk ?: '-',
                    'harga' => (float) $row->harga_jual,
                    'qty_masuk' => (float) $row->qty_masuk,
                ];
            })
            ->values();
    }

    private function getMasterRows(array $filters): Collection
    {
        $query = DB::table('master_produk_toko as mpt')
            ->join('master_produk as mp', 'mp.id', '=', 'mpt.produk_id')
            ->leftJoin('master_kategori_produk as mkp', 'mkp.id', '=', 'mp.kategori_produk_id')
            ->where('mpt.is_delete', 0)
            ->where('mp.is_delete', 0);

        if (! empty($filters['toko_id'])) {
            $query->where('mpt.toko_id', (int) $filters['toko_id']);
        }

        return $query
            ->orderBy('mkp.nama_kategori_produk')
            ->orderBy('mp.nama')
            ->get([
                'mpt.id as produk_toko_id',
                'mpt.produk_id',
                'mpt.toko_id',
                'mpt.harga_jual',
                'mp.kode_accurate',
                'mp.nama as nama_produk',
                'mkp.nama_kategori_produk',
            ])
            ->map(function (object $row): array {
                $namaProduk = trim((string) $row->nama_produk);
                $kodeAccurate = trim((string) $row->kode_accurate);

                return [
                    'produk_toko_id' => (int) $row->produk_toko_id,
                    'produk_id' => (int) $row->produk_id,
                    'toko_id' => (int) $row->toko_id,
                    'produk_key' => $this->productKey(
                        $row->produk_toko_id,
                        $row->produk_id,
                        $row->toko_id,
                        $kodeAccurate,
                        $namaProduk
                    ),
                    'kategori_produk' => $row->nama_kategori_produk ?: '-',
                    'nama_produk' => $namaProduk !== '' ? $namaProduk : '-',
                    'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                    'harga' => (float) $row->harga_jual,
                ];
            })
            ->values();
    }

    private function buildReport(
        Collection $salesRows,
        Collection $stockInRows,
        array $filters
    ): array {
        $masterRows = $this->getMasterRows($filters);
        $salesByProduct = $salesRows->groupBy('produk_key');
        $stockInByProduct = $stockInRows->groupBy('produk_key');
        $knownKeys = $masterRows->pluck('produk_key')->flip();

        $baseRows = $masterRows->concat(
            $salesRows
                ->reject(fn (array $row): bool => $knownKeys->has($row['produk_key']))
                ->groupBy('produk_key')
                ->map(function (Collection $items): array {
                    $first = $items->first();

                    return [
                        'produk_toko_id' => $first['produk_toko_id'],
                        'produk_id' => $first['produk_id'],
                        'toko_id' => $first['toko_id'],
                        'produk_key' => $first['produk_key'],
                        'kategori_produk' => $first['kategori_produk'],
                        'nama_produk' => $first['nama_produk'],
                        'kode_accurate' => $first['kode_accurate'],
                        'harga' => (float) ($first['harga_jual_master'] ?: $first['harga']),
                    ];
                })
                ->values()
        );

        $rows = $baseRows
            ->unique('produk_key')
            ->map(function (array $master) use ($salesByProduct, $stockInByProduct): array {
                $sales = $salesByProduct->get($master['produk_key'], collect());
                $stockIn = $stockInByProduct->get($master['produk_key'], collect());
                $stokKeluarBiasa = (float) $sales
                    ->where('is_premier', false)
                    ->sum('qty');
                $stokKeluarPremiere = (float) $sales
                    ->where('is_premier', true)
                    ->sum('qty');
                $stokKeluarTotal = $stokKeluarBiasa + $stokKeluarPremiere;
                $harga = (float) $master['harga'];

                if ($harga <= 0 && $sales->isNotEmpty()) {
                    $priced = $sales->filter(
                        fn (array $item): bool => (float) $item['qty'] > 0
                            && (float) $item['harga'] > 0
                    );
                    $priceQty = (float) $priced->sum('qty');
                    $harga = $priceQty > 0
                        ? (float) $priced->sum(
                            fn (array $item): float => (float) $item['qty'] * (float) $item['harga']
                        ) / $priceQty
                        : 0.0;
                }

                return [
                    'produk_toko_id' => $master['produk_toko_id'],
                    'produk_id' => $master['produk_id'],
                    'kategori_produk' => $master['kategori_produk'],
                    'nama_produk' => $master['nama_produk'],
                    'kode_accurate' => $master['kode_accurate'],
                    'harga' => round($harga, 2),
                    'stok_masuk' => (float) $stockIn->sum('qty_masuk'),
                    'stok_keluar_biasa' => $stokKeluarBiasa,
                    'stok_keluar_premiere' => $stokKeluarPremiere,
                    'stok_keluar_total' => $stokKeluarTotal,
                    'akumulasi_diskon' => (float) $sales->sum('total_diskon'),
                    'harga_total' => (float) $sales->sum('subtotal'),
                ];
            })
            ->sortBy([
                ['kategori_produk', 'asc'],
                ['nama_produk', 'asc'],
            ])
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
            'data-laporan-obat-produk',
            $filters['jenis_transaksi'] === null
                ? 'semua-jenis-transaksi'
                : $this->slug($publicFilters['jenis_transaksi_label']),
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]);

        return [
            'title' => 'DATA LAPORAN OBAT / PRODUK',
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
                'stok_masuk' => (float) $rows->sum('stok_masuk'),
                'stok_keluar_biasa' => (float) $rows->sum('stok_keluar_biasa'),
                'stok_keluar_premiere' => (float) $rows->sum('stok_keluar_premiere'),
                'stok_keluar_total' => (float) $rows->sum('stok_keluar_total'),
                'akumulasi_diskon' => (float) $rows->sum('akumulasi_diskon'),
                'harga_total' => (float) $rows->sum('harga_total'),
            ],
        ];
    }

    private function productKey(
        $produkTokoId,
        $produkId,
        $tokoId,
        ?string $kodeAccurate,
        string $namaProduk
    ): string {
        if (! empty($produkTokoId)) {
            return 'PT-' . (int) $produkTokoId;
        }

        if (! empty($produkId)) {
            return 'P-' . (int) $produkId . '-T-' . (int) $tokoId;
        }

        $kode = mb_strtoupper(trim((string) $kodeAccurate));
        if ($kode !== '' && $kode !== '-') {
            return 'K-' . $kode . '-T-' . (int) $tokoId;
        }

        return 'N-' . mb_strtoupper(trim($namaProduk)) . '-T-' . (int) $tokoId;
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
            4 => 'Deposit',
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
