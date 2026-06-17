<?php

declare(strict_types=1);

/**
 * Memperbaiki sinkronisasi pelaksana treatment ke pembayaran_invoice_item.
 *
 * Jalankan dari root backend:
 * php tools/apply-payment-treatment-perawat-fix.php
 */

$projectRoot = dirname(__DIR__);
$targetFile = $projectRoot . '/app/Services/Pembayaran/PaymentInvoiceItemSyncService.php';

if (!is_file($targetFile)) {
    fwrite(STDERR, "File tidak ditemukan: {$targetFile}" . PHP_EOL);
    exit(1);
}

$content = file_get_contents($targetFile);
if ($content === false) {
    fwrite(STDERR, "Gagal membaca file: {$targetFile}" . PHP_EOL);
    exit(1);
}

$alreadyFixedPattern = <<<'REGEX'
~'dokter_id'\s*=>\s*\$registrasi->dokter_awal_id\s*\?\?\s*null,\s*\R\s*'perawat_id'\s*=>\s*\$detail->perawat_id\s*\?\?\s*null,\s*\R\s*'is_saran_dokter'~
REGEX;

if (preg_match($alreadyFixedPattern, $content) === 1) {
    fwrite(STDOUT, "Perbaikan sudah terpasang. Tidak ada file yang diubah." . PHP_EOL);
    exit(0);
}

$pattern = <<<'REGEX'
~(?<doctor>[ \t]*'dokter_id'\s*=>\s*\$registrasi->dokter_awal_id\s*\?\?\s*null,\s*\R)(?<indent>[ \t]*)'perawat_id'\s*=>\s*\$registrasi->perawat_awal_id\s*\?\?\s*null,(?<after>\s*\R[ \t]*'is_saran_dokter'\s*=>\s*\(int\)\s*\(\$detail->is_saran_dokter\s*\?\?\s*0\),)~
REGEX;

$count = 0;

$updated = preg_replace_callback(
    $pattern,
    static function (array $matches): string {
        return $matches['doctor']
            . $matches['indent']
            . "'perawat_id' => \$detail->perawat_id ?? null,"
            . $matches['after'];
    },
    $content,
    1,
    $count,
);

if (!is_string($updated) || $count !== 1) {
    fwrite(
        STDERR,
        "Blok sinkronisasi treatment tidak ditemukan secara unik. File tidak diubah." . PHP_EOL
        . "Pastikan PaymentInvoiceItemSyncService.php masih memiliki blok dokter_id, perawat_id, dan is_saran_dokter pada sync treatment." . PHP_EOL,
    );
    exit(1);
}

$backupFile = $targetFile . '.bak-' . date('Ymd-His');
if (!copy($targetFile, $backupFile)) {
    fwrite(STDERR, "Gagal membuat backup: {$backupFile}" . PHP_EOL);
    exit(1);
}

$tempFile = $targetFile . '.tmp-' . bin2hex(random_bytes(4));
if (file_put_contents($tempFile, $updated, LOCK_EX) === false) {
    @unlink($tempFile);
    fwrite(STDERR, "Gagal menulis file sementara." . PHP_EOL);
    exit(1);
}

if (!rename($tempFile, $targetFile)) {
    @unlink($tempFile);
    fwrite(STDERR, "Gagal mengganti file target." . PHP_EOL);
    exit(1);
}

$command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($targetFile);
$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);

if ($exitCode !== 0) {
    copy($backupFile, $targetFile);
    fwrite(STDERR, "Validasi PHP gagal. File lama sudah dipulihkan." . PHP_EOL);
    fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Berhasil memperbaiki PaymentInvoiceItemSyncService.php" . PHP_EOL);
fwrite(STDOUT, "Backup: {$backupFile}" . PHP_EOL);
fwrite(STDOUT, implode(PHP_EOL, $output) . PHP_EOL);
