<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanTindakanTerlarisController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function summary(Request $request)
    {
        $filters = $this->normalizeFilters($request);

        if ($filters['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $filters['validator']->errors(),
            ], 422);
        }

        $filters = $filters['data'];
        $rows = $this->getRows($filters);
        $jenisOptions = $this->getJenisTransaksiOptions();

        return response()->json([
            'status' => true,
            'message' => 'Data tindakan terlaris berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_tindakan' => $rows->count(),
                'total_invoice' => (int) $rows->sum('total_invoice'),
                'total_pasien' => (int) $rows->sum('total_pasien'),
                'total_item' => (int) $rows->sum('total_item'),
                'total_qty' => (float) $rows->sum('total_qty'),
                'total_gross' => (float) $rows->sum('total_gross'),
                'total_diskon' => (float) $rows->sum('total_diskon'),
                'total_net' => (float) $rows->sum('total_net'),
                'rata_net_per_qty' => (float) $rows->sum('total_qty') > 0
                    ? round((float) $rows->sum('total_net') / (float) $rows->sum('total_qty'), 2)
                    : 0,
                'top_tindakan' => $rows->first(),
                'rows' => $rows->take(50)->values(),
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Format laporan harus pdf atau excel.',
            ], 422);
        }

        $filters = $this->normalizeFilters($request);

        if ($filters['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $filters['validator']->errors(),
            ], 422);
        }

        $filters = $filters['data'];
        $rows = $this->getRows($filters);
        $columns = $this->columns();
        $title = 'DATA LAPORAN TINDAKAN TERLARIS';
        $filename = $this->filename($format, $filters);
        $html = $this->buildHtml($title, $columns, $rows, $filters, $format === 'pdf');

        if ($format === 'excel') {
            return response($html, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
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
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
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
            $toko = DB::table('master_toko')->where('id', $filters['toko_id'])->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getRows(array $filters)
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';
        $treatmentIdSql = 'COALESCE(pii.treatment_id, treatment_toko.treatment_id, 0)';
        $namaTindakanSql = "COALESCE(treatment.nama, pii.nama_item)";
        $kodeAccurateSql = "COALESCE(treatment.kode_accurate, pii.kode_accurate_snapshot, '-')";
        $kategoriSalesSql = "COALESCE(treatment.kategori_sales, '-')";

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('master_treatment_toko as treatment_toko', 'treatment_toko.id', '=', 'pii.treatment_toko_id')
            ->leftJoin('master_treatment as treatment', function ($join) {
                $join->on('treatment.id', '=', DB::raw('COALESCE(pii.treatment_id, treatment_toko.treatment_id)'));
            })
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pii.jenis_transaksi')
            ->leftJoin('master_karyawan as dokter', function ($join) {
                $join->on('dokter.id', '=', DB::raw('COALESCE(pii.dokter_id, pi.dokter_id, pi.referensi_dokter_id)'));
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
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        if ($filters['jenis_transaksi'] !== null) {
            $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
        }

        $rows = $query
            ->selectRaw("
                {$treatmentIdSql} as treatment_id,
                {$kodeAccurateSql} as kode_accurate,
                {$namaTindakanSql} as nama_tindakan,
                {$kategoriSalesSql} as kategori_sales,
                GROUP_CONCAT(DISTINCT toko.nama_toko ORDER BY toko.nama_toko SEPARATOR ', ') as cabang,
                COUNT(DISTINCT pi.id) as total_invoice,
                COUNT(DISTINCT pi.pasien_id) as total_pasien,
                COUNT(pii.id) as total_item,
                SUM(COALESCE(pii.qty, 0)) as total_qty,
                SUM(COALESCE(pii.qty, 0) * COALESCE(pii.harga, 0)) as total_gross,
                SUM(COALESCE(pii.diskon_amount, 0) + COALESCE(pii.diskon_referral, 0) + COALESCE(pii.diskon_subtotal_amount, 0)) as total_diskon,
                SUM(COALESCE(pii.subtotal, 0)) as total_net,
                MIN(DATE({$tanggalSql})) as tanggal_pertama,
                MAX(DATE({$tanggalSql})) as tanggal_terakhir,
                GROUP_CONCAT(DISTINCT COALESCE(jt.nama_jenis_transaksi, CONCAT('Jenis ', pii.jenis_transaksi)) ORDER BY pii.jenis_transaksi SEPARATOR ', ') as jenis_transaksi,
                GROUP_CONCAT(DISTINCT dokter.nama ORDER BY dokter.nama SEPARATOR ', ') as dokter_terkait,
                GROUP_CONCAT(DISTINCT perawat.nama ORDER BY perawat.nama SEPARATOR ', ') as perawat_terkait,
                GROUP_CONCAT(DISTINCT pi.no_invoice ORDER BY pi.no_invoice SEPARATOR ', ') as invoice_terkait
            ")
            ->groupByRaw("{$treatmentIdSql}, {$kodeAccurateSql}, {$namaTindakanSql}, {$kategoriSalesSql}")
            ->orderByRaw('total_qty DESC')
            ->orderByRaw('total_net DESC')
            ->orderByRaw('nama_tindakan ASC')
            ->get();

        return $rows->map(function ($row, $index) {
            $totalQty = (float) $row->total_qty;
            $totalNet = (float) $row->total_net;

            return [
                'peringkat' => $index + 1,
                'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
                'kode_accurate' => $row->kode_accurate ?: '-',
                'nama_tindakan' => $row->nama_tindakan ?: '-',
                'kategori_sales' => $row->kategori_sales ?: '-',
                'cabang' => $row->cabang ?: '-',
                'total_invoice' => (int) $row->total_invoice,
                'total_pasien' => (int) $row->total_pasien,
                'total_item' => (int) $row->total_item,
                'total_qty' => $totalQty,
                'total_gross' => (float) $row->total_gross,
                'total_diskon' => (float) $row->total_diskon,
                'total_net' => $totalNet,
                'rata_net_per_qty' => $totalQty > 0 ? round($totalNet / $totalQty, 2) : 0,
                'tanggal_pertama' => $row->tanggal_pertama,
                'tanggal_terakhir' => $row->tanggal_terakhir,
                'jenis_transaksi' => $row->jenis_transaksi ?: '-',
                'dokter_terkait' => $row->dokter_terkait ?: '-',
                'perawat_terkait' => $row->perawat_terkait ?: '-',
                'invoice_terkait' => $row->invoice_terkait ?: '-',
            ];
        });
    }

    private function columns(): array
    {
        return [
            ['key' => 'peringkat', 'label' => 'Peringkat', 'type' => 'number'],
            ['key' => 'kode_accurate', 'label' => 'Kode Accurate'],
            ['key' => 'nama_tindakan', 'label' => 'Nama Tindakan'],
            ['key' => 'kategori_sales', 'label' => 'Kategori Sales'],
            ['key' => 'cabang', 'label' => 'Cabang'],
            ['key' => 'total_invoice', 'label' => 'Jumlah Invoice', 'type' => 'number'],
            ['key' => 'total_pasien', 'label' => 'Jumlah Pasien', 'type' => 'number'],
            ['key' => 'total_item', 'label' => 'Jumlah Item', 'type' => 'number'],
            ['key' => 'total_qty', 'label' => 'Total Qty', 'type' => 'number'],
            ['key' => 'total_gross', 'label' => 'Gross', 'type' => 'currency'],
            ['key' => 'total_diskon', 'label' => 'Diskon', 'type' => 'currency'],
            ['key' => 'total_net', 'label' => 'Net', 'type' => 'currency'],
            ['key' => 'rata_net_per_qty', 'label' => 'Rata-rata Net / Qty', 'type' => 'currency'],
            ['key' => 'tanggal_pertama', 'label' => 'Tanggal Pertama'],
            ['key' => 'tanggal_terakhir', 'label' => 'Tanggal Terakhir'],
            ['key' => 'jenis_transaksi', 'label' => 'Jenis Transaksi'],
            ['key' => 'dokter_terkait', 'label' => 'Dokter Terkait'],
            ['key' => 'perawat_terkait', 'label' => 'Nurse/Beautician Terkait'],
            ['key' => 'invoice_terkait', 'label' => 'Invoice Terkait'],
        ];
    }

    private function buildHtml(string $title, array $columns, $rows, array $filters, bool $autoPrint = false): string
    {
        $filterRows = [
            ['Tanggal', $this->formatDate($filters['tanggal_awal']) . ' s/d ' . $this->formatDate($filters['tanggal_akhir'])],
            ['Cabang', $this->publicFilters($filters)['toko_nama'] ?: 'Semua cabang'],
            ['Jenis Transaksi', $this->jenisTransaksiLabel($filters['jenis_transaksi'])],
            ['Tanggal Berdasarkan', 'Tanggal lunas invoice'],
        ];

        $head = collect($columns)->map(function ($column) {
            return '<th>' . e($column['label']) . '</th>';
        })->implode('');

        $body = $rows->map(function ($row) use ($columns) {
            $cells = collect($columns)->map(function ($column) use ($row) {
                $value = $row[$column['key']] ?? null;
                $type = $column['type'] ?? 'text';
                $class = in_array($type, ['currency', 'number'], true) ? ' class="num"' : '';

                if ($type === 'currency') {
                    $value = $this->formatCurrency($value);
                } elseif ($type === 'number') {
                    $value = $this->formatNumber($value);
                }

                return '<td' . $class . '>' . e((string) ($value ?? '-')) . '</td>';
            })->implode('');

            return '<tr>' . $cells . '</tr>';
        })->implode('');

        if ($rows->isEmpty()) {
            $body = '<tr><td colspan="' . count($columns) . '" style="text-align:center;color:#777;padding:18px;">Tidak ada data pada periode ini.</td></tr>';
        }

        $filterHtml = collect($filterRows)->map(function ($item) {
            return '<tr><td style="width:160px;font-weight:bold;">' . e($item[0]) . '</td><td>' . e($item[1]) . '</td></tr>';
        })->implode('');

        $printedAt = Carbon::now()->format('d/m/Y H:i:s');
        $autoPrintScript = $autoPrint ? '<script>window.addEventListener("load", function(){ window.print(); });</script>' : '';

        return '<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<title>' . e($title) . '</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:18px;}
h1{font-size:18px;margin:0 0 10px;}
.meta{margin-bottom:14px;border-collapse:collapse;}
.meta td{padding:4px 8px;border:1px solid #ddd;}
table.report{border-collapse:collapse;width:100%;}
.report th{background:#f3f4f6;font-weight:bold;}
.report th,.report td{border:1px solid #d7d7d7;padding:6px;vertical-align:top;}
.num{text-align:right;white-space:nowrap;}
.footer{margin-top:12px;font-size:11px;color:#666;}
@media print{body{margin:10mm;} .no-print{display:none;}}
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:12px;"><button onclick="window.print()">Print / Save PDF</button></div>
<h1>' . e($title) . '</h1>
<table class="meta">' . $filterHtml . '</table>
<table class="report"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>
<div class="footer">Dicetak: ' . e($printedAt) . '</div>
' . $autoPrintScript . '
</body>
</html>';
    }

    private function getJenisTransaksiOptions()
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

        return collect(self::ALLOWED_JENIS_TRANSAKSI)->map(function ($id) use ($rows) {
            $row = $rows->firstWhere('id', $id);

            return (object) [
                'id' => $id,
                'kode_jenis_transaksi' => $row->kode_jenis_transaksi ?? $this->defaultJenisTransaksiCode($id),
                'nama_jenis_transaksi' => $row->nama_jenis_transaksi ?? $this->defaultJenisTransaksiLabel($id),
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

        return $row->nama_jenis_transaksi ?? $this->defaultJenisTransaksiLabel((int) $id);
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

    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';

        return sprintf(
            'data-laporan-tindakan-terlaris-%s-sd-%s.%s',
            $filters['tanggal_awal'],
            $filters['tanggal_akhir'],
            $extension
        );
    }

    private function formatDate(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        return Carbon::parse($value)->format('d/m/Y');
    }

    private function formatCurrency($value): string
    {
        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }

    private function formatNumber($value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
