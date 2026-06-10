<?php

$path = getcwd() . '/app/Http/Controllers/Api/Stock/StockPenerimaanController.php';
if (!file_exists($path)) {
    $alt = __DIR__ . '/../app/Http/Controllers/Api/Stock/StockPenerimaanController.php';
    if (file_exists($alt)) {
        $path = $alt;
    }
}

if (!file_exists($path)) {
    fwrite(STDERR, "StockPenerimaanController.php tidak ditemukan. Jalankan dari root backend.\n");
    exit(1);
}

$code = file_get_contents($path);
$original = $code;

$code = str_replace("'tempat_produk_id' => 'required|integer',", "'tempat_produk_id' => 'nullable|integer',", $code);
$code = str_replace('"tempat_produk_id" => "required|integer",', '"tempat_produk_id" => "nullable|integer",', $code);

$searchStore = <<<'TXT'
            $user = $this->userName($request);
            $kode = $request->kode_penerimaan ?? $this->generateKodePenerimaan($request->toko_id);
TXT;
$replaceStore = <<<'TXT'
            $user = $this->userName($request);
            $tempatProdukId = $this->resolveTempatProdukId($request);
            $kode = $request->kode_penerimaan ?? $this->generateKodePenerimaan($request->toko_id);
TXT;
$code = str_replace($searchStore, $replaceStore, $code);

$code = str_replace(
    "'tempat_produk_id' => $" . "request->tempat_produk_id,",
    "'tempat_produk_id' => $" . "tempatProdukId,",
    $code
);
$code = str_replace(
    '"tempat_produk_id" => $' . 'request->tempat_produk_id,',
    '"tempat_produk_id" => $' . 'tempatProdukId,',
    $code
);

$code = preg_replace_callback(
    '/(public function update\(Request \\$request, \\$id\).*?try \{\s*\n\s*\$user = \$this->userName\(\$request\);)(?!\s*\n\s*\$tempatProdukId)/s',
    function ($matches) {
        return $matches[1] . "\n            \$tempatProdukId = \$this->resolveTempatProdukId(\$request);";
    },
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
    if (strpos($code, '    protected function generateKodePenerimaan') !== false) {
        $code = str_replace("    protected function generateKodePenerimaan", $helper . "\n\n    protected function generateKodePenerimaan", $code);
    } else {
        $pos = strrpos($code, "\n}");
        if ($pos === false) {
            fwrite(STDERR, "Gagal memasang helper: penutup class tidak ditemukan.\n");
            exit(1);
        }
        $code = substr($code, 0, $pos) . $helper . substr($code, $pos);
    }
}

if ($code === $original) {
    fwrite(STDERR, "Tidak ada perubahan. Kemungkinan file sudah terpatch atau struktur berbeda.\n");
    exit(1);
}

$backup = $path . '.bak-' . date('Ymd-His');
if (!copy($path, $backup)) {
    fwrite(STDERR, "Gagal membuat backup: {$backup}\n");
    exit(1);
}

file_put_contents($path, $code);

echo "OK: StockPenerimaanController.php diperbaiki. Backup: {$backup}\n";
echo "Lanjutkan: php artisan optimize:clear\n";
