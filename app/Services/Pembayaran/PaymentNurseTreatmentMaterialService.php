<?php

namespace App\Services\Pembayaran;

use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentNurseTreatmentMaterialService
{
    protected array $columnCache = [];

    public function syncFromInvoice(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi,
        ?string $username = null
    ): void {
        if (!$this->isSchemaReady()) {
            return;
        }

        $username = $username ?: 'system';
        $invoice->loadMissing('items');

        $items = $invoice->items
            ->filter(fn ($item) => $this->isActiveTreatmentItem($item))
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $taskId = $this->resolvePerawatTaskId($registrasi);

        foreach ($items as $item) {
            $treatmentId = (int) ($item->treatment_id ?? 0);
            $templates = $this->getActiveTemplates($treatmentId);

            if ($templates->isEmpty()) {
                continue;
            }

            $treatmentDetailId = $this->resolveTreatmentDetailId(
                $invoice,
                $registrasi,
                $item,
                $taskId,
                $username
            );

            if (!$treatmentDetailId) {
                continue;
            }

            $this->clearPendingGeneratedMaterials($registrasi, $treatmentDetailId, $username);

            $qty = max(1, (int) ceil((float) ($item->qty ?? 1)));

            for ($unit = 1; $unit <= $qty; $unit++) {
                foreach ($templates as $template) {
                    $this->insertGeneratedMaterial(
                        $invoice,
                        $registrasi,
                        $item,
                        $template,
                        $taskId,
                        $treatmentDetailId,
                        $username
                    );
                }
            }
        }
    }

    protected function isSchemaReady(): bool
    {
        return Schema::hasTable('master_treatment_perawat_bahan')
            && Schema::hasTable('master_perawat_bahan')
            && Schema::hasTable('registrasi_perawat_bahan_treatment_detail')
            && Schema::hasTable('registrasi_treatment_detail');
    }

    protected function isActiveTreatmentItem(PembayaranInvoiceItem $item): bool
    {
        $itemType = (int) ($item->item_type ?? 0);
        $treatmentId = (int) ($item->treatment_id ?? 0);
        $isDelete = (int) ($item->is_delete ?? 0);
        $qty = (float) ($item->qty ?? 0);

        return $itemType === 2
            && $treatmentId > 0
            && $qty > 0
            && $isDelete === 0;
    }

    protected function getActiveTemplates(int $treatmentId): Collection
    {
        if ($treatmentId <= 0) {
            return collect();
        }

        return DB::table('master_treatment_perawat_bahan as mtpb')
            ->join('master_perawat_bahan as mpb', 'mpb.id', '=', 'mtpb.perawat_bahan_id')
            ->select([
                'mtpb.id',
                'mtpb.treatment_id',
                'mtpb.perawat_bahan_id',
                'mtpb.jumlah_default',
                'mtpb.satuan',
                'mpb.nama_bahan',
                'mpb.satuan as bahan_satuan',
            ])
            ->where('mtpb.treatment_id', $treatmentId)
            ->where(function ($query) {
                $query->whereNull('mtpb.is_delete')->orWhere('mtpb.is_delete', 0);
            })
            ->where(function ($query) {
                $query->whereNull('mpb.is_delete')->orWhere('mpb.is_delete', 0);
            })
            ->where(function ($query) {
                $query->whereNull('mtpb.is_active')->orWhere('mtpb.is_active', 1);
            })
            ->where(function ($query) {
                $query->whereNull('mpb.is_active')->orWhere('mpb.is_active', 1);
            })
            ->orderBy('mtpb.id')
            ->get();
    }

    protected function resolvePerawatTaskId(RegistrasiKunjungan $registrasi): ?int
    {
        if (!Schema::hasTable('registrasi_task')) {
            return null;
        }

        $query = RegistrasiTask::query()
            ->where('registrasi_id', $registrasi->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });

        if (defined(RegistrasiTask::class . '::TYPE_PERAWAT')) {
            $query->where('task_type', RegistrasiTask::TYPE_PERAWAT);
        } else {
            $query->where('task_type', 3);
        }

        return optional($query->orderBy('id')->first())->id;
    }

    protected function resolveTreatmentDetailId(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi,
        PembayaranInvoiceItem $item,
        ?int $taskId,
        string $username
    ): ?int {
        $sourceDetailId = (int) ($item->source_detail_id ?? 0);

        if ($sourceDetailId > 0 && $this->treatmentDetailExists($sourceDetailId)) {
            return $sourceDetailId;
        }

        $detail = $this->createTreatmentDetailFromInvoiceItem(
            $invoice,
            $registrasi,
            $item,
            $taskId,
            $username
        );

        if (!$detail) {
            return null;
        }

        $item->forceFill($this->onlyExistingModelAttributes($item, [
            'source_type' => 1,
            'source_detail_id' => $detail->id,
            'updated_by' => $username,
            'updated_at' => now(),
        ]))->save();

        return (int) $detail->id;
    }

    protected function treatmentDetailExists(int $id): bool
    {
        return RegistrasiTreatmentDetail::query()
            ->where('id', $id)
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->exists();
    }

    protected function createTreatmentDetailFromInvoiceItem(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi,
        PembayaranInvoiceItem $item,
        ?int $taskId,
        string $username
    ): ?RegistrasiTreatmentDetail {
        $payload = $this->onlyExistingTableColumns('registrasi_treatment_detail', [
            'registrasi_id' => $registrasi->id,
            'task_id' => $taskId,
            'source_task_id' => $taskId,
            'source_type' => 4,
            'treatment_id' => $item->treatment_id,
            'treatment_toko_id' => $item->treatment_toko_id ?? null,
            'nama_treatment' => $item->nama_item ?? $item->nama_treatment ?? null,
            'qty' => $item->qty ?? 1,
            'jumlah' => $item->qty ?? 1,
            'harga_satuan' => $item->harga_satuan ?? 0,
            'harga' => $item->harga_satuan ?? 0,
            'subtotal' => $item->subtotal ?? 0,
            'total' => $item->subtotal ?? 0,
            'total_harga' => $item->subtotal ?? 0,
            'dokter_id' => $invoice->dokter_id ?? $registrasi->dokter_id ?? null,
            'perawat_id' => $item->perawat_id ?? $registrasi->perawat_id ?? null,
            'toko_id' => $invoice->toko_id ?? $registrasi->toko_id ?? null,
            'route_treatment' => 'nurse_station',
            'perlu_tindakan_perawat' => 1,
            'is_tindakan_perawat' => 1,
            'input_cppt' => 0,
            'input_before_after' => 0,
            'input_bahan_treatment' => 0,
            'status' => 0,
            'is_delete' => 0,
            'created_by' => $username,
            'updated_by' => $username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (empty($payload)) {
            return null;
        }

        return RegistrasiTreatmentDetail::create($payload);
    }

    protected function clearPendingGeneratedMaterials(
        RegistrasiKunjungan $registrasi,
        int $treatmentDetailId,
        string $username
    ): void {
        $table = 'registrasi_perawat_bahan_treatment_detail';
        $query = DB::table($table)
            ->where('registrasi_id', $registrasi->id)
            ->where('treatment_detail_id', $treatmentDetailId)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 0);
            });

        if (Schema::hasColumn($table, 'is_delete')) {
            $query->update($this->onlyExistingTableColumns($table, [
                'is_delete' => 1,
                'status' => 9,
                'updated_by' => $username,
                'updated_at' => now(),
            ]));

            return;
        }

        $query->delete();
    }

    protected function insertGeneratedMaterial(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi,
        PembayaranInvoiceItem $item,
        object $template,
        ?int $taskId,
        int $treatmentDetailId,
        string $username
    ): void {
        $table = 'registrasi_perawat_bahan_treatment_detail';

        DB::table($table)->insert($this->onlyExistingTableColumns($table, [
            'registrasi_id' => $registrasi->id,
            'task_id' => $taskId,
            'treatment_detail_id' => $treatmentDetailId,
            'master_treatment_perawat_bahan_id' => $template->id,
            'treatment_id' => $item->treatment_id,
            'perawat_bahan_id' => $template->perawat_bahan_id,
            'nama_bahan' => $template->nama_bahan ?? null,
            'jumlah_default' => $template->jumlah_default ?? 0,
            'jumlah_terpakai' => null,
            'satuan' => $template->satuan ?: ($template->bahan_satuan ?? null),
            'tanggal_pengisian' => null,
            'toko_id' => $invoice->toko_id ?? $registrasi->toko_id ?? null,
            'perawat_id' => $item->perawat_id ?? $registrasi->perawat_id ?? null,
            'status' => 0,
            'is_delete' => 0,
            'created_by' => $username,
            'updated_by' => $username,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    protected function onlyExistingModelAttributes($model, array $payload): array
    {
        $table = $model->getTable();
        return $this->onlyExistingTableColumns($table, $payload);
    }

    protected function onlyExistingTableColumns(string $table, array $payload): array
    {
        $columns = $this->columns($table);

        return collect($payload)
            ->filter(fn ($value, $key) => in_array($key, $columns, true))
            ->all();
    }

    protected function columns(string $table): array
    {
        if (!isset($this->columnCache[$table])) {
            $this->columnCache[$table] = Schema::hasTable($table)
                ? Schema::getColumnListing($table)
                : [];
        }

        return $this->columnCache[$table];
    }
}
