<?php

namespace App\Models\Pembayaran;

use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterSatuan;
use App\Models\Master\MasterToko;
use App\Models\Master\MasterTreatment;
use App\Models\Master\MasterTreatmentBahan;
use App\Models\Master\MasterTreatmentToko;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use App\Models\Stock\StockMutasiProduk;

class PembayaranInvoiceTreatmentBahan extends BasePembayaranModel
{
    const STATUS_BELUM_VALIDASI = 0;
    const STATUS_SUDAH_VALIDASI = 1;
    const STATUS_STOK_TERPOSTING = 2;
    const STATUS_BATAL = 9;

    protected $table = 'pembayaran_invoice_treatment_bahan';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'pembayaran_id' => 'integer',
        'pembayaran_item_id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'master_treatment_bahan_id' => 'integer',
        'treatment_id' => 'integer',
        'treatment_toko_id' => 'integer',
        'produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'qty_default' => 'decimal:4',
        'qty_treatment' => 'decimal:4',
        'qty_rencana' => 'decimal:4',
        'qty_tervalidasi' => 'decimal:4',
        'satuan_id' => 'integer',
        'tanggal_transaksi' => 'datetime',
        'toko_id' => 'integer',
        'perawat_id' => 'integer',
        'validated_by' => 'integer',
        'validated_at' => 'datetime',
        'status' => 'integer',
        'is_stock_posted' => 'boolean',
        'stock_mutasi_id' => 'integer',
        'is_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id', 'id');
    }

    public function invoiceItem()
    {
        return $this->belongsTo(PembayaranInvoiceItem::class, 'pembayaran_item_id', 'id');
    }

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id', 'id');
    }

    public function task()
    {
        return $this->belongsTo(RegistrasiTask::class, 'task_id', 'id');
    }

    public function treatmentDetail()
    {
        return $this->belongsTo(RegistrasiTreatmentDetail::class, 'treatment_detail_id', 'id');
    }

    public function treatmentBahan()
    {
        return $this->belongsTo(MasterTreatmentBahan::class, 'master_treatment_bahan_id', 'id');
    }

    public function treatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'treatment_id', 'id');
    }

    public function treatmentToko()
    {
        return $this->belongsTo(MasterTreatmentToko::class, 'treatment_toko_id', 'id');
    }

    public function produk()
    {
        return $this->belongsTo(MasterProduk::class, 'produk_id', 'id');
    }

    public function produkToko()
    {
        return $this->belongsTo(MasterProdukToko::class, 'produk_toko_id', 'id');
    }

    public function satuan()
    {
        return $this->belongsTo(MasterSatuan::class, 'satuan_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function perawat()
    {
        return $this->belongsTo(MasterKaryawan::class, 'perawat_id', 'id');
    }

    public function validator()
    {
        return $this->belongsTo(MasterKaryawan::class, 'validated_by', 'id');
    }

    public function stockMutasi()
    {
        return $this->belongsTo(StockMutasiProduk::class, 'stock_mutasi_id', 'id');
    }

    public function scopeByInvoice($query, $pembayaranId)
    {
        return $query->where('pembayaran_id', $pembayaranId);
    }

    public function scopeByInvoiceItem($query, $pembayaranItemId)
    {
        return $query->where('pembayaran_item_id', $pembayaranItemId);
    }

    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeBelumValidasi($query)
    {
        return $query->where('status', self::STATUS_BELUM_VALIDASI);
    }

    public function scopeSudahValidasi($query)
    {
        return $query->where('status', self::STATUS_SUDAH_VALIDASI);
    }

    public function scopeStockPosted($query)
    {
        return $query
            ->where('status', self::STATUS_STOK_TERPOSTING)
            ->where('is_stock_posted', 1);
    }

    public function markValidated($validatedBy = null)
    {
        $this->status = self::STATUS_SUDAH_VALIDASI;
        $this->validated_at = now();

        if ($validatedBy !== null) {
            $this->validated_by = $validatedBy;
            $this->updated_by = $validatedBy;
        }

        return $this->save();
    }

    public function markStockPosted($stockMutasiId = null, $updatedBy = null)
    {
        $this->status = self::STATUS_STOK_TERPOSTING;
        $this->is_stock_posted = 1;

        if ($stockMutasiId !== null) {
            $this->stock_mutasi_id = $stockMutasiId;
        }

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }

    public function markCancelled($updatedBy = null)
    {
        $this->status = self::STATUS_BATAL;

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }
}