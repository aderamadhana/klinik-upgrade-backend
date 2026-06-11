<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanTopPasienNominalTerbanyakController extends Controller
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
            'message' => 'Data top pasien nominal terbanyak berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'nominal_range_options' => $this->nominalRangeOptions(),
                'total_pasien' => $rows->count(),
                'total_invoice' => (int) $rows->sum('total_invoice'),
                'total_hari_transaksi' => (int) $rows->sum('total_hari_transaksi'),
                'total_nominal' => (float) $rows->sum('total_nominal'),
                'total_treatment' => (float) $rows->sum('total_treatment'),
                'total_produk' => (float) $rows->sum('total_produk'),
                'total_konsultasi' => (float) $rows->sum('total_konsultasi'),
                'rata_nominal_per_pasien' => $rows->count() > 0
                    ? round((float) $rows->sum('total_nominal') / $rows->count(), 2)
                    : 0,
                'rows' => $rows->values(),
                'top_pasien' => $rows->first(),
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
        $title = 'DATA TOP PASIEN NOMINAL TERBANYAK';
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

        $peringkat = $request->query('peringkat', $request->query('limit'));
        if ($peringkat === null || $peringkat === '') {
            $peringkat = 10;
        } elseif (is_numeric($peringkat)) {
            $peringkat = (int) $peringkat;
        }

        $nominalMin = $request->query(
            'nominal_min',
            $request->query('min_nominal', $request->query('range_nominal_awal', 1000000))
        );
        $nominalMax = $request->query(
            'nominal_max',
            $request->query('max_nominal', $request->query('range_nominal_akhir', 5000000))
        );

        $nominalMin = $this->normalizeNullableNumber($nominalMin, 1000000);
        $nominalMax = $this->normalizeNullableNumber($nominalMax, 5000000);

        $jenisTransaksi = $request->query('jenis_transaksi');
        if ($jenisTransaksi === null || $jenisTransaksi === '' || $jenisTransaksi === 'all') {
            $jenisTransaksi = null;
        } elseif (is_numeric($jenisTransaksi)) {
            $jenisTransaksi = (int) $jenisTransaksi;
        }

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
            'nominal_min' => $nominalMin,
            'nominal_max' => $nominalMax,
            'peringkat' => $peringkat,
            'toko_id' => $tokoId,
            'jenis_transaksi' => $jenisTransaksi,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'nominal_min' => ['nullable', 'numeric', 'min:0'],
            'nominal_max' => ['nullable', 'numeric', 'min:0'],
            'peringkat' => ['required', 'integer', 'min:1', 'max:1000'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            'jenis_transaksi' => ['nullable', 'integer', 'in:0,1,2,3,4'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'nominal_min.min' => 'Nominal awal tidak boleh minus.',
            'nominal_max.min' => 'Nominal akhir tidak boleh minus.',
            'peringkat.min' => 'Peringkat minimal 1.',
            'peringkat.max' => 'Peringkat maksimal 1000 agar laporan tetap ringan.',
            'jenis_transaksi.in' => 'Jenis transaksi harus 0, 1, 2, 3, atau 4.',
        ]);

        $validator->after(function ($validator) use ($data) {
            if (
                $data['nominal_min'] !== null &&
                $data['nominal_max'] !== null &&
                (float) $data['nominal_max'] < (float) $data['nominal_min']
            ) {
                $validator->errors()->add('nominal_max', 'Nominal akhir tidak boleh lebih kecil dari nominal awal.');
            }
        });

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    private function normalizeNullableNumber($value, ?float $default = null): ?float
    {
        if ($value === null || $value === '' || $value === 'all' || $value === 'none') {
            return $default;
        }

        if (! is_numeric($value)) {
            return $default;
        }

        return (float) $value;
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
            'nominal_min' => $filters['nominal_min'],
            'nominal_max' => $filters['nominal_max'],
            'nominal_range_label' => $this->nominalRangeLabel($filters['nominal_min'], $filters['nominal_max']),
            'peringkat' => (int) $filters['peringkat'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'nominal_berdasarkan' => 'Akumulasi grand total invoice lunas per pasien',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getRows(array $filters)
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $query = DB::table('pembayaran_invoice as pi')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pi.jenis_transaksi')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->whereIn('pi.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->when(! empty($filters['toko_id']), function ($query) use ($filters) {
                $query->where('pi.toko_id', (int) $filters['toko_id']);
            })
            ->when($filters['jenis_transaksi'] !== null, function ($query) use ($filters) {
                $query->where('pi.jenis_transaksi', (int) $filters['jenis_transaksi']);
            })
            ->groupBy(
                'pi.pasien_id',
                'pasien.no_rm',
                'pasien.nama',
                'pasien.no_hp',
                'pasien.no_wa'
            )
            ->selectRaw("\n                pi.pasien_id,\n                pasien.no_rm,\n                COALESCE(pasien.nama, 'Non Pasien') as nama_pasien,\n                pasien.no_hp,\n                pasien.no_wa,\n                MIN(DATE({$tanggalSql})) as tanggal_awal_transaksi,\n                MAX(DATE({$tanggalSql})) as tanggal_akhir_transaksi,\n                COUNT(DISTINCT pi.id) as total_invoice,\n                COUNT(DISTINCT DATE({$tanggalSql})) as total_hari_transaksi,\n                COALESCE(SUM(pi.grand_total), 0) as total_nominal,\n                COALESCE(SUM(pi.subtotal_treatment), 0) as total_treatment,\n                COALESCE(SUM(pi.subtotal_produk), 0) as total_produk,\n                COALESCE(SUM(pi.subtotal_konsultasi), 0) as total_konsultasi,\n                COALESCE(SUM(pi.subtotal), 0) as total_bruto,\n                COALESCE(SUM(pi.diskon_subtotal_amount), 0) as total_diskon_subtotal,\n                COALESCE(SUM(pi.total_diskon_item), 0) as total_diskon_item,\n                COALESCE(SUM(pi.total_diskon_referral), 0) as total_diskon_referral,\n                COALESCE(SUM(pi.total_promo), 0) as total_promo,\n                COALESCE(SUM(pi.diskon_member_amount), 0) as total_diskon_member,\n                COALESCE(SUM(pi.total_bayar), 0) as total_bayar,\n                COALESCE(SUM(pi.total_kembalian), 0) as total_kembalian,\n                GROUP_CONCAT(DISTINCT COALESCE(toko.nama_toko, '-') ORDER BY toko.nama_toko SEPARATOR ', ') as cabang,\n                GROUP_CONCAT(DISTINCT COALESCE(jt.nama_jenis_transaksi, CONCAT('Jenis ', pi.jenis_transaksi)) ORDER BY pi.jenis_transaksi SEPARATOR ', ') as jenis_transaksi,\n                GROUP_CONCAT(DISTINCT pi.no_invoice ORDER BY {$tanggalSql} DESC SEPARATOR ', ') as invoice_terkait\n            ");

        if ($filters['nominal_min'] !== null) {
            $query->havingRaw('COALESCE(SUM(pi.grand_total), 0) >= ?', [(float) $filters['nominal_min']]);
        }

        if ($filters['nominal_max'] !== null) {
            $query->havingRaw('COALESCE(SUM(pi.grand_total), 0) <= ?', [(float) $filters['nominal_max']]);
        }

        $rows = $query
            ->orderByDesc('total_nominal')
            ->orderByDesc('total_invoice')
            ->orderBy('nama_pasien')
            ->limit((int) $filters['peringkat'])
            ->get();

        return $rows->values()->map(function ($row, int $index) {
            $totalInvoice = max((int) $row->total_invoice, 1);
            $totalNominal = (float) $row->total_nominal;
            $totalBruto = (float) $row->total_bruto;
            $totalDiskon = (float) $row->total_diskon_subtotal
                + (float) $row->total_diskon_item
                + (float) $row->total_diskon_referral
                + (float) $row->total_promo
                + (float) $row->total_diskon_member;

            return [
                'peringkat' => $index + 1,
                'pasien_id' => $row->pasien_id,
                'no_rm' => $row->no_rm ?: '-',
                'nama_pasien' => $row->nama_pasien ?: 'Non Pasien',
                'no_hp' => $this->normalizePhone($row->no_wa ?: $row->no_hp),
                'cabang' => $row->cabang ?: '-',
                'tanggal_awal_transaksi' => $row->tanggal_awal_transaksi,
                'tanggal_akhir_transaksi' => $row->tanggal_akhir_transaksi,
                'periode_transaksi' => $this->formatDate($row->tanggal_awal_transaksi) . ' s/d ' . $this->formatDate($row->tanggal_akhir_transaksi),
                'total_invoice' => (int) $row->total_invoice,
                'total_hari_transaksi' => (int) $row->total_hari_transaksi,
                'total_nominal' => $totalNominal,
                'total_treatment' => (float) $row->total_treatment,
                'total_produk' => (float) $row->total_produk,
                'total_konsultasi' => (float) $row->total_konsultasi,
                'total_bruto' => $totalBruto,
                'total_diskon' => $totalDiskon,
                'total_bayar' => (float) $row->total_bayar,
                'total_kembalian' => (float) $row->total_kembalian,
                'rata_nominal_per_invoice' => round($totalNominal / $totalInvoice, 2),
                'kontribusi_treatment_persen' => $totalNominal > 0
                    ? round(((float) $row->total_treatment / $totalNominal) * 100, 2)
                    : 0,
                'kontribusi_produk_persen' => $totalNominal > 0
                    ? round(((float) $row->total_produk / $totalNominal) * 100, 2)
                    : 0,
                'jenis_transaksi' => $row->jenis_transaksi ?: '-',
                'invoice_terkait' => $row->invoice_terkait ?: '-',
            ];
        });
    }

    private function columns(): array
    {
        return [
            ['key' => 'peringkat', 'label' => 'Peringkat', 'type' => 'number'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'nama_pasien', 'label' => 'Nama Pasien'],
            ['key' => 'no_hp', 'label' => 'No HP/WA'],
            ['key' => 'cabang', 'label' => 'Cabang'],
            ['key' => 'periode_transaksi', 'label' => 'Periode Transaksi'],
            ['key' => 'total_invoice', 'label' => 'Jumlah Invoice', 'type' => 'number'],
            ['key' => 'total_hari_transaksi', 'label' => 'Jumlah Hari Transaksi', 'type' => 'number'],
            ['key' => 'total_nominal', 'label' => 'Total Nominal', 'type' => 'currency'],
            ['key' => 'rata_nominal_per_invoice', 'label' => 'Rata-rata per Invoice', 'type' => 'currency'],
            ['key' => 'total_treatment', 'label' => 'Total Treatment', 'type' => 'currency'],
            ['key' => 'total_produk', 'label' => 'Total Produk/Obat', 'type' => 'currency'],
            ['key' => 'total_konsultasi', 'label' => 'Total Konsultasi', 'type' => 'currency'],
            ['key' => 'total_bruto', 'label' => 'Total Bruto', 'type' => 'currency'],
            ['key' => 'total_diskon', 'label' => 'Total Diskon', 'type' => 'currency'],
            ['key' => 'total_bayar', 'label' => 'Total Bayar', 'type' => 'currency'],
            ['key' => 'kontribusi_treatment_persen', 'label' => '% Treatment', 'type' => 'number'],
            ['key' => 'kontribusi_produk_persen', 'label' => '% Produk', 'type' => 'number'],
            ['key' => 'jenis_transaksi', 'label' => 'Jenis Transaksi'],
            ['key' => 'invoice_terkait', 'label' => 'Invoice Terkait'],
        ];
    }

    private function buildHtml(string $title, array $columns, $rows, array $filters, bool $autoPrint = false): string
    {
        $filterRows = [
            ['Tanggal', $this->formatDate($filters['tanggal_awal']) . ' s/d ' . $this->formatDate($filters['tanggal_akhir'])],
            ['Range Nominal', $this->nominalRangeLabel($filters['nominal_min'], $filters['nominal_max'])],
            ['Peringkat', 'Top ' . (int) $filters['peringkat']],
            ['Cabang', $this->publicFilters($filters)['toko_nama'] ?: 'Semua cabang'],
            ['Jenis Transaksi', $this->jenisTransaksiLabel($filters['jenis_transaksi'])],
            ['Tanggal Berdasarkan', 'Tanggal lunas invoice'],
            ['Nominal Berdasarkan', 'Akumulasi grand total invoice lunas per pasien'],
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
            $body = '<tr><td colspan="' . count($columns) . '" style="text-align:center;color:#777;padding:18px;">Tidak ada data pada periode dan range nominal ini.</td></tr>';
        }

        $filterHtml = collect($filterRows)->map(function ($item) {
            return '<tr><td style="width:180px;font-weight:bold;">' . e($item[0]) . '</td><td>' . e($item[1]) . '</td></tr>';
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

    private function nominalRangeOptions(): array
    {
        return [
            ['label' => 'Rp 0', 'value' => 0],
            ['label' => 'Rp 500.000', 'value' => 500000],
            ['label' => 'Rp 1.000.000', 'value' => 1000000],
            ['label' => 'Rp 2.000.000', 'value' => 2000000],
            ['label' => 'Rp 3.000.000', 'value' => 3000000],
            ['label' => 'Rp 5.000.000', 'value' => 5000000],
            ['label' => 'Rp 10.000.000', 'value' => 10000000],
            ['label' => 'Rp 25.000.000', 'value' => 25000000],
            ['label' => 'Rp 50.000.000', 'value' => 50000000],
            ['label' => 'Rp 100.000.000', 'value' => 100000000],
        ];
    }

    private function nominalRangeLabel(?float $min, ?float $max): string
    {
        if ($min !== null && $max !== null) {
            return $this->formatCurrency($min) . ' s/d ' . $this->formatCurrency($max);
        }

        if ($min !== null) {
            return '>= ' . $this->formatCurrency($min);
        }

        if ($max !== null) {
            return '<= ' . $this->formatCurrency($max);
        }

        return 'Semua nominal';
    }

    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';

        return sprintf(
            'data-top-pasien-nominal-terbanyak-%s-sd-%s-top-%s.%s',
            $filters['tanggal_awal'],
            $filters['tanggal_akhir'],
            (int) $filters['peringkat'],
            $extension
        );
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
