<?php

namespace App\Services\Administrasi;

use App\Models\Pasien;
use App\Models\PasienPengkajianAwal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PasienPengkajianAwalService
{
    private const PAYLOAD_FIELDS = [
        'tanggal_pengkajian',
        's_keluhan_utama',
        's_riwayat_penyakit_sekarang',
        's_riwayat_penyakit_dahulu',
        's_riwayat_penyakit_keluarga',
        'o_keadaan_umum',
        'o_gcs',
        'o_eye_gcs',
        'o_verbal_gcs',
        'o_motor_gcs',
        'o_keadaan_tht_checklist',
        'o_keadaan_tht',
        'o_keadaan_kepala_checklist',
        'o_keadaan_kepala',
        'o_keadaan_mata_checklist',
        'o_keadaan_mata',
        'o_keadaan_leher_checklist',
        'o_keadaan_leher',
        'o_keadaan_paru_checklist',
        'o_keadaan_paru',
        'o_keadaan_jantung_checklist',
        'o_keadaan_jantung',
        'o_keadaan_abdomen_checklist',
        'o_keadaan_abdomen',
        'o_keadaan_ekstremitas_checklist',
        'o_keadaan_ekstremitas',
        'o_keadaan_kulit_checklist',
        'o_keadaan_kulit',
        'pemeriksaan_fisik_khusus',
        'pemeriksaan_penunjang',
        'a_diagnosa',
        'p_rencana_terapi',
        'rujuk_ke',
        'tanggal_kontrol',
        'info_hasil_pemeriksaan',
        'info_tindakan_pengobatan_resiko',
        'info_kemungkinan_komplikasi',
        'status_paham_pasien',
    ];

    public function index(int $pasienId): array
    {
        $pasien = $this->findPasien($pasienId);

        $records = PasienPengkajianAwal::query()
            ->active()
            ->where('pasien_id', $pasien->id)
            ->with('pemeriksa:id,kode,nama')
            ->orderByDesc('tanggal_pengkajian')
            ->orderByDesc('id')
            ->get();

        return [
            'pasien' => $this->formatPasien($pasien),
            'latest' => $records->isNotEmpty()
                ? $this->formatDetail($records->first())
                : null,
            'history' => $records
                ->map(fn (PasienPengkajianAwal $record) => $this->formatHistory($record))
                ->values()
                ->all(),
        ];
    }

    public function show(int $pasienId, int $pengkajianId): array
    {
        $pasien = $this->findPasien($pasienId);

        $record = PasienPengkajianAwal::query()
            ->active()
            ->where('pasien_id', $pasien->id)
            ->whereKey($pengkajianId)
            ->with('pemeriksa:id,kode,nama')
            ->firstOrFail();

        return [
            'pasien' => $this->formatPasien($pasien),
            'pengkajian' => $this->formatDetail($record),
        ];
    }

    public function store(int $pasienId, array $payload, array $actor): array
    {
        return DB::transaction(function () use ($pasienId, $payload, $actor) {
            $pasien = Pasien::query()
                ->active()
                ->lockForUpdate()
                ->findOrFail($pasienId);

            $data = $this->preparePayload($payload);
            $data['pasien_id'] = $pasien->id;
            $data['pemeriksa_id'] = $actor['karyawan_id'] ?? null;
            $data['nama_pemeriksa_snapshot'] = $actor['nama'] ?? null;
            $data['created_by'] = $actor['user_id'] ?? null;
            $data['updated_by'] = $actor['user_id'] ?? null;
            $data['is_delete'] = 0;

            $record = PasienPengkajianAwal::query()->create($data);
            $record->load('pemeriksa:id,kode,nama');

            return $this->formatDetail($record);
        });
    }

    public function update(
        int $pasienId,
        int $pengkajianId,
        array $payload,
        array $actor
    ): array {
        return DB::transaction(function () use (
            $pasienId,
            $pengkajianId,
            $payload,
            $actor
        ) {
            $this->findPasien($pasienId);

            $record = PasienPengkajianAwal::query()
                ->active()
                ->where('pasien_id', $pasienId)
                ->whereKey($pengkajianId)
                ->lockForUpdate()
                ->firstOrFail();

            $data = $this->preparePayload($payload);
            $data['updated_by'] = $actor['user_id'] ?? null;

            $record->fill($data);
            $record->save();
            $record->load('pemeriksa:id,kode,nama');

            return $this->formatDetail($record);
        });
    }

    private function findPasien(int $pasienId): Pasien
    {
        return Pasien::query()
            ->active()
            ->with('toko:id,kode,nama_toko')
            ->findOrFail($pasienId);
    }

    private function preparePayload(array $payload): array
    {
        $data = Arr::only($payload, self::PAYLOAD_FIELDS);

        foreach ([
            'info_hasil_pemeriksaan',
            'info_tindakan_pengobatan_resiko',
            'info_kemungkinan_komplikasi',
        ] as $booleanField) {
            $data[$booleanField] = (bool) ($data[$booleanField] ?? false);
        }

        foreach ([
            'o_keadaan_tht',
            'o_keadaan_kepala',
            'o_keadaan_mata',
            'o_keadaan_leher',
            'o_keadaan_paru',
            'o_keadaan_jantung',
            'o_keadaan_abdomen',
            'o_keadaan_ekstremitas',
            'o_keadaan_kulit',
        ] as $detailField) {
            $checklistField = $detailField.'_checklist';

            if ((int) ($data[$checklistField] ?? 1) === 1) {
                $data[$detailField] = null;
            }
        }

        if (empty($data['tanggal_kontrol'])) {
            $data['tanggal_kontrol'] = null;
        }

        return $data;
    }

    private function formatPasien(Pasien $pasien): array
    {
        return [
            'id' => (int) $pasien->id,
            'no_rm' => $pasien->no_rm,
            'nama' => $pasien->nama,
            'jenis_kelamin' => $pasien->jenis_kelamin,
            'jenis_kelamin_text' => $pasien->jenis_kelamin_text,
            'tanggal_lahir' => $this->formatDate($pasien->tanggal_lahir),
            'no_hp' => $pasien->no_hp,
            'no_wa' => $pasien->no_wa,
            'toko_id' => $pasien->toko_id ? (int) $pasien->toko_id : null,
            'toko_nama' => $pasien->toko?->nama_toko,
        ];
    }

    private function formatHistory(PasienPengkajianAwal $record): array
    {
        return [
            'id' => (int) $record->id,
            'tanggal_pengkajian' => $this->formatDateTime($record->tanggal_pengkajian),
            'tanggal_pengkajian_label' => $record->tanggal_pengkajian?->translatedFormat('d F Y H:i'),
            'nama_pemeriksa' => $record->pemeriksa?->nama
                ?: $record->nama_pemeriksa_snapshot
                ?: '-',
            'diagnosa' => $record->a_diagnosa,
            'updated_at' => $this->formatDateTime($record->updated_at),
        ];
    }

    private function formatDetail(PasienPengkajianAwal $record): array
    {
        return [
            'id' => (int) $record->id,
            'pasien_id' => (int) $record->pasien_id,
            'pemeriksa_id' => $record->pemeriksa_id
                ? (int) $record->pemeriksa_id
                : null,
            'nama_pemeriksa' => $record->pemeriksa?->nama
                ?: $record->nama_pemeriksa_snapshot
                ?: '-',
            'tanggal_pengkajian' => $record->tanggal_pengkajian?->format('Y-m-d\TH:i'),
            's_keluhan_utama' => $record->s_keluhan_utama,
            's_riwayat_penyakit_sekarang' => $record->s_riwayat_penyakit_sekarang,
            's_riwayat_penyakit_dahulu' => $record->s_riwayat_penyakit_dahulu,
            's_riwayat_penyakit_keluarga' => $record->s_riwayat_penyakit_keluarga,
            'o_keadaan_umum' => $record->o_keadaan_umum,
            'o_gcs' => $record->o_gcs,
            'o_eye_gcs' => $record->o_eye_gcs,
            'o_verbal_gcs' => $record->o_verbal_gcs,
            'o_motor_gcs' => $record->o_motor_gcs,
            'o_keadaan_tht_checklist' => (int) $record->o_keadaan_tht_checklist,
            'o_keadaan_tht' => $record->o_keadaan_tht,
            'o_keadaan_kepala_checklist' => (int) $record->o_keadaan_kepala_checklist,
            'o_keadaan_kepala' => $record->o_keadaan_kepala,
            'o_keadaan_mata_checklist' => (int) $record->o_keadaan_mata_checklist,
            'o_keadaan_mata' => $record->o_keadaan_mata,
            'o_keadaan_leher_checklist' => (int) $record->o_keadaan_leher_checklist,
            'o_keadaan_leher' => $record->o_keadaan_leher,
            'o_keadaan_paru_checklist' => (int) $record->o_keadaan_paru_checklist,
            'o_keadaan_paru' => $record->o_keadaan_paru,
            'o_keadaan_jantung_checklist' => (int) $record->o_keadaan_jantung_checklist,
            'o_keadaan_jantung' => $record->o_keadaan_jantung,
            'o_keadaan_abdomen_checklist' => (int) $record->o_keadaan_abdomen_checklist,
            'o_keadaan_abdomen' => $record->o_keadaan_abdomen,
            'o_keadaan_ekstremitas_checklist' => (int) $record->o_keadaan_ekstremitas_checklist,
            'o_keadaan_ekstremitas' => $record->o_keadaan_ekstremitas,
            'o_keadaan_kulit_checklist' => (int) $record->o_keadaan_kulit_checklist,
            'o_keadaan_kulit' => $record->o_keadaan_kulit,
            'pemeriksaan_fisik_khusus' => $record->pemeriksaan_fisik_khusus,
            'pemeriksaan_penunjang' => $record->pemeriksaan_penunjang,
            'a_diagnosa' => $record->a_diagnosa,
            'p_rencana_terapi' => $record->p_rencana_terapi,
            'rujuk_ke' => $record->rujuk_ke,
            'tanggal_kontrol' => $this->formatDate($record->tanggal_kontrol),
            'info_hasil_pemeriksaan' => (bool) $record->info_hasil_pemeriksaan,
            'info_tindakan_pengobatan_resiko' => (bool) $record->info_tindakan_pengobatan_resiko,
            'info_kemungkinan_komplikasi' => (bool) $record->info_kemungkinan_komplikasi,
            'status_paham_pasien' => (int) $record->status_paham_pasien,
            'created_at' => $this->formatDateTime($record->created_at),
            'updated_at' => $this->formatDateTime($record->updated_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
