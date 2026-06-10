<?php

$path = __DIR__ . '/../app/Http/Controllers/Api/Stock/StockPenerimaanController.php';
if (!file_exists($path)) {
    $path = getcwd() . '/app/Http/Controllers/Api/Stock/StockPenerimaanController.php';
}
if (!file_exists($path)) {
    fwrite(STDERR, "StockPenerimaanController.php tidak ditemukan. Jalankan dari root backend.\n");
    exit(1);
}

$code = file_get_contents($path);
$original = $code;

$code = str_replace("'tempat_produk_id' => 'required|integer',", "'tempat_produk_id' => 'nullable|integer',", $code);

$code = str_replace(
"            $user = $this->userName($request);\n            $kode = $request->kode_penerimaan ?? $this->generateKodePenerimaan($request->toko_id);",
"            $user = $this->userName($request);\n            $tempatProdukId = $this->resolveTempatProdukId($request);\n            $kode = $request->kode_penerimaan ?? $this->generateKodePenerimaan($request->toko_id);",
$code
);

$code = str_replace(
"                'tempat_produk_id' => $request->tempat_produk_id,",
"                'tempat_produk_id' => $tempatProdukId,",
$code
);

$code = str_replace(
"                    'tempat_produk_id' => $request->tempat_produk_id,",
"                    'tempat_produk_id' => $tempatProdukId,",
$code
);

// update() already has $user assignment too; if replacement did not hit both store/update, add resolver after remaining assignment.
$code = preg_replace(
"/(public function update\(Request \\$request, \\$id\).*?try \{\s*\n\s*\$user = \$this->userName\(\$request\);)(?!\s*\n\s*\$tempatProdukId)/s",
"$1\n            $tempatProdukId = $this->resolveTempatProdukId($request);",
$code,
1
);

$helper = <<<'PHP_HELPER'

    protected function resolveTempatProdukId(Request $request): int
    {
        if ($request->filled('tempat_produk_id')) {
            return (int) $request->tempat_produk_id;
        }

        $apotekId = DB::table('master_tempat_produk')
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->whereRaw('LOWER(nama_tempat_produk) = ?', ['apotek'])
            ->value('id');

        if ($apotekId) {
            return (int) $apotekId;
        }

        $firstId = DB::table('master_tempat_produk')
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderBy('id')
            ->value('id');

        if ($firstId) {
            return (int) $firstId;
        }

        abort(422, 'Master tempat produk belum tersedia.');
    }
PHP_HELPER;

if (strpos($code, 'function resolveTempatProdukId') === false) {
    $code = str_replace("    protected function generateKodePenerimaan", $helper . "\n\n    protected function generateKodePenerimaan", $code);
}

if ($code === $original) {
    fwrite(STDERR, "Tidak ada perubahan. Struktur file mungkin sudah berbeda.\n");
    exit(1);
}

$backup = $path . '.bak-' . date('Ymd-His');
copy($path, $backup);
file_put_contents($path, $code);

echo "OK: StockPenerimaanController.php diperbaiki. Backup: {$backup}\n";
