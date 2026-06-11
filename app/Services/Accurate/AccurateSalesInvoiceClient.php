<?php

namespace App\Services\Accurate;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AccurateSalesInvoiceClient
{
    public function uploadSalesInvoice(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.accurate.base_url', env('ACCURATE_BASE_URL', '')), '/');
        $endpoint = '/' . ltrim((string) config(
            'services.accurate.sales_invoice_endpoint',
            env('ACCURATE_SALES_INVOICE_ENDPOINT', '/api/sales-invoice/save.do')
        ), '/');
        $token = (string) config('services.accurate.token', env('ACCURATE_TOKEN', ''));
        $sessionId = (string) config('services.accurate.session_id', env('ACCURATE_SESSION_ID', ''));
        $timeout = (int) config('services.accurate.timeout', env('ACCURATE_TIMEOUT', 60));

        if ($baseUrl === '') {
            throw new RuntimeException('ACCURATE_BASE_URL belum diset. Upload faktur Accurate tidak dijalankan.');
        }

        if ($token === '' && $sessionId === '') {
            throw new RuntimeException('ACCURATE_TOKEN atau ACCURATE_SESSION_ID belum diset. Upload faktur Accurate tidak dijalankan.');
        }

        $headers = [
            'Accept' => 'application/json',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if ($sessionId !== '') {
            $headers['X-Session-ID'] = $sessionId;
        }

        $response = Http::withHeaders($headers)
            ->timeout($timeout)
            ->asForm()
            ->post($baseUrl . $endpoint, $payload);

        $json = $response->json();
        $responsePayload = is_array($json) ? $json : ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Upload Accurate gagal. HTTP %s: %s',
                $response->status(),
                $this->extractMessage($responsePayload) ?: $response->body()
            ));
        }

        if (array_key_exists('s', $responsePayload) && ! (bool) $responsePayload['s']) {
            throw new RuntimeException($this->extractMessage($responsePayload) ?: 'Upload Accurate ditolak oleh Accurate.');
        }

        if (array_key_exists('success', $responsePayload) && ! (bool) $responsePayload['success']) {
            throw new RuntimeException($this->extractMessage($responsePayload) ?: 'Upload Accurate ditolak oleh Accurate.');
        }

        return [
            'number' => $this->extractInvoiceNumber($responsePayload),
            'payload' => $responsePayload,
            'raw' => $response->body(),
        ];
    }

    private function extractInvoiceNumber(array $payload): ?string
    {
        $candidates = [
            'r.d.number',
            'r.d.invoiceNo',
            'r.d.no',
            'd.number',
            'd.invoiceNo',
            'd.no',
            'data.number',
            'data.invoiceNo',
            'data.no',
            'number',
            'invoiceNo',
            'no',
        ];

        foreach ($candidates as $candidate) {
            $value = Arr::get($payload, $candidate);
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function extractMessage(array $payload): ?string
    {
        $value = Arr::get($payload, 'd')
            ?: Arr::get($payload, 'message')
            ?: Arr::get($payload, 'error')
            ?: Arr::get($payload, 'errors.0');

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value ? trim((string) $value) : null;
    }
}
