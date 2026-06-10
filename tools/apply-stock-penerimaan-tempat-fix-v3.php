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

function findMatchingBrace(string $code, int $openPos): int
{
    $len = strlen($code);
    $level = 0;
    for ($i = $openPos; $i < $len; $i++) {
        $ch = $code[$i];
        if ($ch === '{') {
            $level++;
        } elseif ($ch === '}') {
            $level--;
            if ($level === 0) {
                return $i;
            }
        }
    }
    return -1;
}

function replaceMethod(string $code, string $methodNeedle, string $newMethod): string
{
    $start = strpos($code, $methodNeedle);
    if ($start === false) {
        return $code;
    }

    $open = strpos($code, '{', $start);
    if ($open === false) {
        return $code;
    }

    $close = findMatchingBrace($code, $open);
    if ($close === -1) {
        return $code;
    }

    return substr($code, 0, $start) . $newMethod . substr($code, $close + 1);
}

function methodSegment(string $code, string $startNeedle, ?string $nextNeedle): ?array
{
    $start = strpos($code, $startNeedle);
    if ($start === false) {
        return null;
    }

    $end = $nextNeedle ? strpos($code, $nextNeedle, $start + strlen($startNeedle)) : false;
    if ($end === false) {
        $open = strpos($code, '{', $start);
        if ($open === false) {
            return null;
        }
        $close = findMatchingBrace($code, $open);
        if ($close === -1) {
            return null;
        }
        $end = $close + 1;
    }

    return [$start, $end, substr($code, $start, $end - $start)];
}

function patchTempatInMethod(string $code, string $startNeedle, ?string $nextNeedle): string
{
    $seg = methodSegment($code, $startNeedle, $nextNeedle);
    if (!$seg) {
        return $code;
    }

    [$start, $end, $method] = $seg;

    $method = str_replace("'tempat_produk_id' => 'required|integer'", "'tempat_produk_id' => 'nullable|integer'", $method);
    $method = str_replace('"tempat_produk_id" => "required|integer"', '"tempat_produk_id" => "nullable|integer"', $method);

    if (strpos($method, '$tempatProdukId = $this->resolveTempatProdukId($request);') === false) {
        $method = preg_replace(
            '/(\$user\s*=\s*\$this->userName\(\$request\);)/',
            "$1\n            \$tempatProdukId = \$this->resolveTempatProdukId(\$request);",
            $method,
            1
        );
    }

    $method = str_replace("'tempat_produk_id' => \$request->tempat_produk_id,", "'tempat_produk_id' => \$tempatProdukId,", $method);
    $method = str_replace('"tempat_produk_id" => $request->tempat_produk_id,', '"tempat_produk_id" => $tempatProdukId,', $method);

    return substr($code, 0, $start) . $method . substr($code, $end);
}

$code = patchTempatInMethod($code, 'public function store(Request $request)', 'public function update(Request $request, $id)');
$code = patchTempatInMethod($code, 'public function update(Request $request, $id)', 'public function post(Request $request, $id)');

$helper = <<<'PHP_HELPER'
    protected function resolveTempatProdukId(Request $request): int
    {
        if ($request->filled('tempat_produk_id')) {
            return (int) $request->tempat_produk_id;
        }

        $produkId = collect($request->input('details', []))
            ->pluck('produk_id')
            ->filter()
            ->first();

        if ($produkId) {
            $tempatProdukId = DB::table('master_produk')
                ->where('id', $produkId)
                ->where(function ($query) {
                    $query->where('is_delete', 0)
                        ->orWhereNull('is_delete');
                })
                ->value('tempat_produk_id');

            if ($tempatProdukId) {
                return (int) $tempatProdukId;
            }
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

if (strpos($code, 'function resolveTempatProdukId') !== false) {
    $code = replaceMethod($code, '    protected function resolveTempatProdukId', $helper);
} else {
    if (strpos($code, '    protected function generateKodePenerimaan') !== false) {
        $code = str_replace('    protected function generateKodePenerimaan', $helper . "\n\n    protected function generateKodePenerimaan", $code);
    } else {
        $pos = strrpos($code, "\n}");
        if ($pos === false) {
            fwrite(STDERR, "Gagal memasang helper: penutup class tidak ditemukan.\n");
            exit(1);
        }
        $code = substr($code, 0, $pos) . "\n" . $helper . substr($code, $pos);
    }
}

if ($code === $original) {
    fwrite(STDERR, "Tidak ada perubahan. Kemungkinan file sudah sesuai atau struktur berbeda.\n");
    exit(1);
}

$backup = $path . '.bak-' . date('Ymd-His');
if (!copy($path, $backup)) {
    fwrite(STDERR, "Gagal membuat backup: {$backup}\n");
    exit(1);
}

file_put_contents($path, $code);

echo "OK: StockPenerimaanController.php diperbaiki. Backup: {$backup}\n";
echo "Default tempat_produk_id sekarang: request -> master_produk.tempat_produk_id -> Apotek -> tempat aktif pertama.\n";
echo "Lanjutkan: php artisan optimize:clear\n";
