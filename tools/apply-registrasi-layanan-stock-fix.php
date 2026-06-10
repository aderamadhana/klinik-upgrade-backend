<?php

declare(strict_types=1);

/**
 * Apply perubahan store RegistrasiLayananController agar validasi dan
 * normalisasi stok produk memakai StockTransactionService.
 *
 * Pemakaian dari root project Laravel:
 * php apply-registrasi-layanan-stock-fix.php
 *
 * Atau berikan path controller secara eksplisit:
 * php apply-registrasi-layanan-stock-fix.php /path/RegistrasiLayananController.php
 */

$defaultTarget = getcwd()
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Http'
    . DIRECTORY_SEPARATOR . 'Controllers'
    . DIRECTORY_SEPARATOR . 'Api'
    . DIRECTORY_SEPARATOR . 'Registrasi'
    . DIRECTORY_SEPARATOR . 'RegistrasiLayananController.php';

$target = $argv[1] ?? $defaultTarget;

if (!is_file($target)) {
    fwrite(STDERR, "Controller tidak ditemukan: {$target}" . PHP_EOL);
    exit(1);
}

$content = file_get_contents($target);
if ($content === false) {
    fwrite(STDERR, "Gagal membaca controller: {$target}" . PHP_EOL);
    exit(1);
}

if (str_contains($content, '->prepareRegistrasiPenjualanItems(')) {
    fwrite(STDOUT, "Perubahan sudah terpasang. Tidak ada file yang diubah." . PHP_EOL);
    exit(0);
}

$pattern = <<<'REGEX'
~\$penjualanItems\s*=\s*\$this->ensureAndValidatePenjualanStockFromMaster\(\s*\$penjualanItems\s*,\s*\$tokoId\s*\);~m
REGEX;

$replacement = <<<'PHP_CODE'
$penjualanItems = $this->stockTransactionService
                    ->prepareRegistrasiPenjualanItems(
                        $penjualanItems,
                        $tokoId,
                        $this->username()
                    );
PHP_CODE;

$updated = preg_replace($pattern, $replacement, $content, 1, $count);

if ($updated === null) {
    fwrite(STDERR, "Regex patch gagal diproses." . PHP_EOL);
    exit(1);
}

if ($count !== 1) {
    fwrite(
        STDERR,
        "Blok store yang diharapkan tidak ditemukan tepat satu kali. "
        . "File tidak diubah agar source terbaru tidak rusak." . PHP_EOL
    );
    exit(1);
}

$timestamp = date('Ymd-His');
$backup = $target . '.bak-' . $timestamp;

if (!copy($target, $backup)) {
    fwrite(STDERR, "Gagal membuat backup: {$backup}" . PHP_EOL);
    exit(1);
}

if (file_put_contents($target, $updated, LOCK_EX) === false) {
    fwrite(STDERR, "Gagal menulis perubahan. Backup tersedia di: {$backup}" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Berhasil memperbaiki store RegistrasiLayananController." . PHP_EOL);
fwrite(STDOUT, "Backup: {$backup}" . PHP_EOL);
fwrite(STDOUT, "Target: {$target}" . PHP_EOL);
