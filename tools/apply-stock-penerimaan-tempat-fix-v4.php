<?php

$controllerPath = __DIR__ . '/../app/Http/Controllers/Api/Stock/StockPenerimaanController.php';

if (! file_exists($controllerPath)) {
    fwrite(STDERR, "File tidak ditemukan: {$controllerPath}\n");
    exit(1);
}

$source = file_get_contents($controllerPath);
$original = $source;

// 1) tempat_produk_id tidak boleh wajib dari FE. Default diambil dari master_produk berdasarkan produk_id.
$source = str_replace(
    "'tempat_produk_id' => 'required|integer',",
    "'tempat_produk_id' => 'nullable|integer',",
    $source
);
$source = str_replace(
    '"tempat_produk_id" => "required|integer",',
    '"tempat_produk_id" => "nullable|integer",',
    $source
);

// 2) Semua insert/update header dan detail harus memakai variable hasil resolve, bukan request langsung.
$source = str_replace(
    "'tempat_produk_id' => \$request->tempat_produk_id,",
    "'tempat_produk_id' => \$tempatProdukId,",
    $source
);
$source = str_replace(
    '"tempat_produk_id" => $request->tempat_produk_id,',
    '"tempat_produk_id" => $tempatProdukId,',
    $source
);

// 3) Pastikan variable default dibuat di store().
$storePattern = '/(public\s+function\s+store\s*\(\s*Request\s+\$request\s*\).*?try\s*\{\s*\$user\s*=\s*\$this->userName\(\$request\);)(?!\s*\$tempatProdukId\s*=)/s';
$source = preg_replace(
    $storePattern,
    "$1\n            \$tempatProdukId = \$this->resolveTempatProdukIdForPenerimaan(\$request);",
    $source,
    1
);

// 4) Pastikan variable default dibuat di update().
$updatePattern = '/(public\s+function\s+update\s*\(\s*Request\s+\$request\s*,\s*\$id\s*\).*?try\s*\{\s*\$user\s*=\s*\$this->userName\(\$request\);)(?!\s*\$tempatProdukId\s*=)/s';
$source = preg_replace(
    $updatePattern,
    "$1\n            \$tempatProdukId = \$this->resolveTempatProdukIdForPenerimaan(\$request);",
    $source,
    1
);

// 5) Tambahkan helper resolver jika belum ada.
if (strpos($source, 'resolveTempatProdukIdForPenerimaan') === false) {
    $helper = <<<'PHP_HELPER'

    protected function resolveTempatProdukIdForPenerimaan(Request $request): int
    {
        $requestTempatProdukId = (int) $request->input('tempat_produk_id', 0);
        if ($requestTempatProdukId > 0) {
            return $requestTempatProdukId;
        }

        $produkId = null;
        foreach ((array) $request->input('details', []) as $detail) {
            if (! empty($detail['produk_id'])) {
                $produkId = (int) $detail['produk_id'];
                break;
            }
        }

        if ($produkId) {
            $masterTempatProdukId = DB::table('master_produk')
                ->where('id', $produkId)
                ->where(function ($query) {
                    $query->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->value('tempat_produk_id');

            if (! empty($masterTempatProdukId)) {
                return (int) $masterTempatProdukId;
            }
        }

        $apotekId = DB::table('master_tempat_produk')
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where('nama_tempat_produk', 'like', '%Apotek%')
            ->value('id');

        if (! empty($apotekId)) {
            return (int) $apotekId;
        }

        $firstTempatProdukId = DB::table('master_tempat_produk')
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->orderBy('id')
            ->value('id');

        if (! empty($firstTempatProdukId)) {
            return (int) $firstTempatProdukId;
        }

        throw new \RuntimeException('Default tempat produk tidak ditemukan. Isi master_tempat_produk terlebih dahulu.');
    }
PHP_HELPER;

    $source = preg_replace(
        '/(\n\s*protected\s+function\s+generateKodePenerimaan\s*\()/s',
        $helper . "$1",
        $source,
        1
    );
}

// Validasi hasil patch.
if (substr_count($source, '$tempatProdukId = $this->resolveTempatProdukIdForPenerimaan($request);') < 2) {
    fwrite(STDERR, "Patch gagal: variable tempatProdukId belum masuk ke store() dan update().\n");
    exit(1);
}

if (strpos($source, "'tempat_produk_id' => \$request->tempat_produk_id,") !== false) {
    fwrite(STDERR, "Patch gagal: masih ada insert/update memakai request->tempat_produk_id.\n");
    exit(1);
}

if ($source === $original) {
    echo "Tidak ada perubahan. Kemungkinan file sudah dipatch.\n";
    exit(0);
}

$backupPath = $controllerPath . '.bak-' . date('Ymd-His');
copy($controllerPath, $backupPath);
file_put_contents($controllerPath, $source);

echo "OK: StockPenerimaanController berhasil dipatch.\n";
echo "Backup: {$backupPath}\n";
echo "Lanjutkan: php artisan optimize:clear\n";
