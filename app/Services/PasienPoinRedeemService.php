<?php

namespace App\Services;

use App\Models\Pasien;
use App\Models\PasienMember;
use App\Models\Master\MasterMerchandise;
use App\Models\Poin\MemberPointLedger;
use App\Models\Poin\PasienPoinLedger;
use App\Models\Poin\PasienPoinRedeem;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasienPoinRedeemService
{
    public function merchandise(array $filters = []): array
    {
        return MasterMerchandise::query()
            ->active()
            ->search(Arr::get($filters, 'search'))
            ->orderBy('sort_order')
            ->orderBy('nama')
            ->limit((int) Arr::get($filters, 'limit', 100))
            ->get()
            ->map(fn (MasterMerchandise $item) => $this->formatMerchandise($item))
            ->values()
            ->all();
    }

    public function show(int $pasienId, array $filters = []): array
    {
        $pasien = $this->findPasien($pasienId);

        $member = PasienMember::query()
            ->where('pasien_id', $pasienId)
            ->notDeleted()
            ->orderByDesc('id')
            ->first();

        $summary = PasienPoinLedger::query()
            ->byPasien($pasienId)
            ->notVoid()
            ->selectRaw('
                COALESCE(SUM(poin_masuk), 0) as total_masuk,
                COALESCE(SUM(poin_keluar), 0) as total_keluar
            ')
            ->first();

        return [
            'pasien' => $this->formatPasien($pasien),
            'member' => $member ? $this->formatMember($member) : null,
            'saldo_poin' => $this->currentSaldo($pasienId),
            'total_poin_masuk' => (int) ($summary->total_masuk ?? 0),
            'total_poin_keluar' => (int) ($summary->total_keluar ?? 0),
            'minimal_poin_redeem' => 1,
            'riwayat' => $this->history($pasienId, (int) Arr::get($filters, 'limit', 25)),
        ];
    }

    public function redeem(int $pasienId, array $payload, $user = null): array
    {
        $tanggal = $this->parseTanggal(Arr::get($payload, 'tanggal'));
        $catatan = trim((string) Arr::get($payload, 'catatan', ''));
        $items = $this->normalizeItems((array) Arr::get($payload, 'items', []));

        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => ['Minimal pilih satu reward untuk ditukar.'],
            ]);
        }

        return DB::transaction(function () use ($pasienId, $tanggal, $catatan, $items, $user) {
            $pasien = $this->findPasienForUpdate($pasienId);
            $member = $this->findActiveMemberForUpdate($pasienId, $tanggal);

            $saldoSebelum = $this->currentSaldoForUpdate($pasienId);

            $detailRows = [];
            $totalPoin = 0;
            $totalDiskonNominal = 0;

            foreach ($items as $item) {
                $merchandise = MasterMerchandise::query()
                    ->active()
                    ->whereKey($item['merchandise_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$merchandise) {
                    throw ValidationException::withMessages([
                        'items' => ["Reward ID {$item['merchandise_id']} tidak ditemukan atau sudah tidak aktif."],
                    ]);
                }

                $qty = (int) $item['qty'];
                $hargaPoin = (int) $merchandise->harga_poin;
                $stok = (int) $merchandise->stok;

                if ($hargaPoin <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ["Reward {$merchandise->nama} belum memiliki harga poin yang valid."],
                    ]);
                }

                if ($stok < $qty) {
                    throw ValidationException::withMessages([
                        'items' => ["Stok reward {$merchandise->nama} tidak cukup. Sisa stok: {$stok}."],
                    ]);
                }

                $subtotalPoin = $hargaPoin * $qty;
                $subtotalDiskonNominal = $this->calculateNominalValue($merchandise, $qty);

                $detailRows[] = [
                    'merchandise' => $merchandise,
                    'qty' => $qty,
                    'subtotal_poin' => $subtotalPoin,
                    'subtotal_diskon_nominal' => $subtotalDiskonNominal,
                ];

                $totalPoin += $subtotalPoin;
                $totalDiskonNominal += $subtotalDiskonNominal;
            }

            if ($saldoSebelum < $totalPoin) {
                throw ValidationException::withMessages([
                    'items' => ["Saldo poin tidak cukup. Saldo: {$saldoSebelum}, dibutuhkan: {$totalPoin}."],
                ]);
            }

            $now = now();
            $saldoSetelah = $saldoSebelum - $totalPoin;
            $userName = $this->userName($user);
            $userId = $this->userId($user);

            $redeem = PasienPoinRedeem::create([
                'kode_redeem' => 'RDP-TMP-' . Str::uuid(),
                'pasien_id' => $pasien->id,
                'member_id' => $member?->id,
                'toko_id' => $pasien->toko_id,
                'tanggal' => $tanggal,
                'total_poin' => $totalPoin,
                'total_diskon_nominal' => $totalDiskonNominal,
                'status' => 'posted',
                'catatan' => $catatan !== '' ? $catatan : null,
                'created_by' => $userName,
                'updated_by' => $userName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $kodeRedeem = 'RDP-' . Carbon::parse($tanggal)->format('Ymd') . '-' . str_pad((string) $redeem->id, 6, '0', STR_PAD_LEFT);

            $redeem->forceFill([
                'kode_redeem' => $kodeRedeem,
                'updated_at' => $now,
            ])->save();

            foreach ($detailRows as $detail) {
                /** @var MasterMerchandise $merchandise */
                $merchandise = $detail['merchandise'];
                $qty = (int) $detail['qty'];

                $redeem->details()->create([
                    'merchandise_id' => $merchandise->id,
                    'kode_snapshot' => $merchandise->kode,
                    'nama_snapshot' => $merchandise->nama,
                    'jenis_reward_snapshot' => $merchandise->jenis_reward,
                    'harga_poin_snapshot' => (int) $merchandise->harga_poin,
                    'nilai_diskon_persen_snapshot' => $merchandise->nilai_diskon_persen,
                    'nilai_diskon_nominal_snapshot' => $merchandise->nilai_diskon_nominal,
                    'qty' => $qty,
                    'subtotal_poin' => $detail['subtotal_poin'],
                    'subtotal_diskon_nominal' => $detail['subtotal_diskon_nominal'],
                ]);

                $merchandise->stok = max(0, (int) $merchandise->stok - $qty);
                $merchandise->updated_by = $userName;
                $merchandise->updated_at = $now;
                $merchandise->save();
            }

            $ledger = PasienPoinLedger::create([
                'pasien_id' => $pasien->id,
                'toko_id' => $pasien->toko_id,
                'source_table' => 'pasien_poin_redeem',
                'source_id' => $redeem->id,
                'source_no' => $kodeRedeem,
                'tanggal' => $tanggal,
                'tipe_mutasi' => 'redeem',
                'poin_masuk' => 0,
                'poin_keluar' => $totalPoin,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_setelah' => $saldoSetelah,
                'nominal_transaksi' => $totalDiskonNominal,
                'rule_id' => null,
                'keterangan' => $catatan !== '' ? $catatan : "Penukaran poin {$kodeRedeem}",
                'is_void' => 0,
                'void_reference_id' => null,
                'created_by' => $userId,
                'created_at' => $now,
            ]);

            $memberLedger = null;

            if ($member) {
                $member->point_terpakai = (float) $member->point_terpakai + $totalPoin;
                $member->point_sisa = $saldoSetelah;
                $member->updated_by = $userName;
                $member->updated_at = $now;
                $member->save();

                $memberLedger = MemberPointLedger::create([
                    'member_id' => $member->id,
                    'pasien_id' => $pasien->id,
                    'pembayaran_id' => null,
                    'registrasi_id' => null,
                    'transaksi_type' => MemberPointLedger::TYPE_REDEEM,
                    'point_masuk' => 0,
                    'point_keluar' => $totalPoin,
                    'nominal_referensi' => $totalDiskonNominal,
                    'catatan' => "Penukaran poin {$kodeRedeem}",
                    'tanggal_transaksi' => $tanggal,
                    'is_void' => 0,
                    'created_by' => $userName,
                    'updated_by' => $userName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $redeem->forceFill([
                'ledger_id' => $ledger->id,
                'member_point_ledger_id' => $memberLedger?->id,
                'updated_at' => $now,
            ])->save();

            return [
                'redeem_id' => $redeem->id,
                'kode_redeem' => $kodeRedeem,
                'ledger_id' => $ledger->id,
                'saldo_poin' => $saldoSetelah,
                'total_poin' => $totalPoin,
                'total_diskon_nominal' => $totalDiskonNominal,
                'summary' => $this->show($pasien->id),
            ];
        });
    }

    public function voidRedeem(int $pasienId, int $ledgerId, array $payload, $user = null): array
    {
        $reason = trim((string) Arr::get($payload, 'reason', ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Alasan pembatalan wajib diisi.'],
            ]);
        }

        return DB::transaction(function () use ($pasienId, $ledgerId, $reason, $user) {
            $pasien = $this->findPasienForUpdate($pasienId);

            $ledger = PasienPoinLedger::query()
                ->byPasien($pasienId)
                ->whereKey($ledgerId)
                ->where('source_table', 'pasien_poin_redeem')
                ->where('tipe_mutasi', 'redeem')
                ->notVoid()
                ->lockForUpdate()
                ->first();

            if (!$ledger) {
                throw ValidationException::withMessages([
                    'ledger_id' => ['Data penukaran poin tidak ditemukan atau sudah dibatalkan.'],
                ]);
            }

            $redeem = PasienPoinRedeem::query()
                ->whereKey($ledger->source_id)
                ->where('pasien_id', $pasienId)
                ->posted()
                ->lockForUpdate()
                ->first();

            if (!$redeem) {
                throw ValidationException::withMessages([
                    'ledger_id' => ['Header penukaran poin tidak ditemukan atau sudah void.'],
                ]);
            }

            $redeem->load('details.merchandise');

            $saldoSebelum = $this->currentSaldoForUpdate($pasienId);
            $totalPoin = (int) $redeem->total_poin;
            $saldoSetelah = $saldoSebelum + $totalPoin;
            $now = now();
            $userName = $this->userName($user);
            $userId = $this->userId($user);

            foreach ($redeem->details as $detail) {
                if (!$detail->merchandise) {
                    continue;
                }

                $detail->merchandise->stok = (int) $detail->merchandise->stok + (int) $detail->qty;
                $detail->merchandise->updated_by = $userName;
                $detail->merchandise->updated_at = $now;
                $detail->merchandise->save();
            }

            $voidLedger = PasienPoinLedger::create([
                'pasien_id' => $pasien->id,
                'toko_id' => $pasien->toko_id,
                'source_table' => 'pasien_poin_redeem',
                'source_id' => $redeem->id,
                'source_no' => $redeem->kode_redeem,
                'tanggal' => $now,
                'tipe_mutasi' => 'void_redeem',
                'poin_masuk' => $totalPoin,
                'poin_keluar' => 0,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_setelah' => $saldoSetelah,
                'nominal_transaksi' => $redeem->total_diskon_nominal,
                'rule_id' => null,
                'keterangan' => "Void penukaran {$redeem->kode_redeem}: {$reason}",
                'is_void' => 0,
                'void_reference_id' => $ledger->id,
                'created_by' => $userId,
                'created_at' => $now,
            ]);

            $redeem->forceFill([
                'status' => 'void',
                'void_ledger_id' => $voidLedger->id,
                'void_reason' => $reason,
                'void_by' => $userName,
                'void_at' => $now,
                'updated_by' => $userName,
                'updated_at' => $now,
            ])->save();

            if ($redeem->member_id) {
                $member = PasienMember::query()
                    ->whereKey($redeem->member_id)
                    ->lockForUpdate()
                    ->first();

                if ($member) {
                    $member->point_terpakai = max(0, (float) $member->point_terpakai - $totalPoin);
                    $member->point_sisa = $saldoSetelah;
                    $member->updated_by = $userName;
                    $member->updated_at = $now;
                    $member->save();

                    MemberPointLedger::create([
                        'member_id' => $member->id,
                        'pasien_id' => $pasien->id,
                        'pembayaran_id' => null,
                        'registrasi_id' => null,
                        'transaksi_type' => 9,
                        'point_masuk' => $totalPoin,
                        'point_keluar' => 0,
                        'nominal_referensi' => $redeem->total_diskon_nominal,
                        'catatan' => "Void penukaran {$redeem->kode_redeem}: {$reason}",
                        'tanggal_transaksi' => $now,
                        'is_void' => 0,
                        'created_by' => $userName,
                        'updated_by' => $userName,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            return [
                'void_ledger_id' => $voidLedger->id,
                'saldo_poin' => $saldoSetelah,
                'summary' => $this->show($pasien->id),
            ];
        });
    }

    private function history(int $pasienId, int $limit = 25): array
    {
        $ledgers = PasienPoinLedger::query()
            ->byPasien($pasienId)
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $redeemIds = $ledgers
            ->where('source_table', 'pasien_poin_redeem')
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        $redeems = collect();

        if ($redeemIds->isNotEmpty()) {
            $redeems = PasienPoinRedeem::query()
                ->with('details')
                ->whereIn('id', $redeemIds)
                ->get()
                ->keyBy('id');
        }

        return $ledgers
            ->map(function (PasienPoinLedger $ledger) use ($redeems) {
                $redeem = $ledger->source_table === 'pasien_poin_redeem'
                    ? $redeems->get($ledger->source_id)
                    : null;

                return [
                    'id' => $ledger->id,
                    'tanggal' => optional($ledger->tanggal)->toDateTimeString(),
                    'tipe_mutasi' => $ledger->tipe_mutasi,
                    'poin_masuk' => (int) $ledger->poin_masuk,
                    'poin_keluar' => (int) $ledger->poin_keluar,
                    'saldo_sebelum' => (int) $ledger->saldo_sebelum,
                    'saldo_setelah' => (int) $ledger->saldo_setelah,
                    'source_table' => $ledger->source_table,
                    'source_id' => $ledger->source_id,
                    'source_no' => $ledger->source_no,
                    'keterangan' => $ledger->keterangan,
                    'is_void' => (int) $ledger->is_void,
                    'redeem' => $redeem ? [
                        'id' => $redeem->id,
                        'kode_redeem' => $redeem->kode_redeem,
                        'status' => $redeem->status,
                        'total_poin' => (int) $redeem->total_poin,
                        'total_diskon_nominal' => (float) $redeem->total_diskon_nominal,
                        'details' => $redeem->details
                            ->map(fn ($detail) => [
                                'id' => $detail->id,
                                'merchandise_id' => $detail->merchandise_id,
                                'kode' => $detail->kode_snapshot,
                                'nama' => $detail->nama_snapshot,
                                'jenis_reward' => $detail->jenis_reward_snapshot,
                                'qty' => (int) $detail->qty,
                                'subtotal_poin' => (int) $detail->subtotal_poin,
                                'subtotal_diskon_nominal' => (float) $detail->subtotal_diskon_nominal,
                            ])
                            ->values()
                            ->all(),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $merchandiseId = (int) Arr::get($item, 'merchandise_id');
            $qty = (int) Arr::get($item, 'qty', 0);

            if ($merchandiseId <= 0 || $qty <= 0) {
                continue;
            }

            if (!isset($normalized[$merchandiseId])) {
                $normalized[$merchandiseId] = [
                    'merchandise_id' => $merchandiseId,
                    'qty' => 0,
                ];
            }

            $normalized[$merchandiseId]['qty'] += $qty;
        }

        return array_values($normalized);
    }

    private function calculateNominalValue(MasterMerchandise $merchandise, int $qty): float
    {
        if ($merchandise->jenis_reward !== 'diskon_nominal') {
            return 0;
        }

        return (float) $merchandise->nilai_diskon_nominal * $qty;
    }

    private function currentSaldo(int $pasienId): int
    {
        $ledger = PasienPoinLedger::query()
            ->byPasien($pasienId)
            ->notVoid()
            ->orderByDesc('id')
            ->first();

        return (int) ($ledger->saldo_setelah ?? 0);
    }

    private function currentSaldoForUpdate(int $pasienId): int
    {
        $ledger = PasienPoinLedger::query()
            ->byPasien($pasienId)
            ->notVoid()
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        return (int) ($ledger->saldo_setelah ?? 0);
    }

    private function findPasien(int $pasienId): Pasien
    {
        $pasien = Pasien::query()
            ->active()
            ->whereKey($pasienId)
            ->first();

        if (!$pasien) {
            throw ValidationException::withMessages([
                'pasien_id' => ['Pasien tidak ditemukan.'],
            ]);
        }

        return $pasien;
    }

    private function findPasienForUpdate(int $pasienId): Pasien
    {
        $pasien = Pasien::query()
            ->active()
            ->whereKey($pasienId)
            ->lockForUpdate()
            ->first();

        if (!$pasien) {
            throw ValidationException::withMessages([
                'pasien_id' => ['Pasien tidak ditemukan.'],
            ]);
        }

        return $pasien;
    }

    private function findActiveMemberForUpdate(int $pasienId, Carbon $tanggal): ?PasienMember
    {
        return PasienMember::query()
            ->where('pasien_id', $pasienId)
            ->where('is_delete', 0)
            ->where('status', 1)
            ->where(function ($q) use ($tanggal) {
                $q->whereNull('tanggal_expired')
                    ->orWhereDate('tanggal_expired', '>=', $tanggal->toDateString());
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function parseTanggal($tanggal): Carbon
    {
        if (!$tanggal) {
            return now();
        }

        $date = Carbon::parse($tanggal);

        if (strlen((string) $tanggal) <= 10) {
            $now = now();

            return $date->setTime($now->hour, $now->minute, $now->second);
        }

        return $date;
    }

    private function userId($user): ?int
    {
        return $user?->id ? (int) $user->id : null;
    }

    private function userName($user): ?string
    {
        if (!$user) {
            return null;
        }

        return $user->username
            ?? $user->name
            ?? $user->email
            ?? ($user->id ? (string) $user->id : null);
    }

    private function formatPasien(Pasien $pasien): array
    {
        return [
            'id' => $pasien->id,
            'no_rm' => $pasien->no_rm,
            'nama' => $pasien->nama,
            'tipe_pasien' => $pasien->tipe_pasien,
            'toko_id' => $pasien->toko_id,
            'no_hp' => $pasien->no_hp,
            'no_wa' => $pasien->no_wa,
        ];
    }

    private function formatMember(PasienMember $member): array
    {
        return [
            'id' => $member->id,
            'no_member' => $member->no_member,
            'member_tier_id' => $member->member_tier_id,
            'total_point' => (int) $member->total_point,
            'point_terpakai' => (int) $member->point_terpakai,
            'point_sisa' => (int) $member->point_sisa,
            'status' => $member->status,
            'tanggal_expired' => optional($member->tanggal_expired)->toDateString(),
        ];
    }

    private function formatMerchandise(MasterMerchandise $item): array
    {
        return [
            'id' => $item->id,
            'kode' => $item->kode,
            'nama' => $item->nama,
            'jenis_reward' => $item->jenis_reward,
            'nilai_diskon_persen' => $item->nilai_diskon_persen !== null ? (float) $item->nilai_diskon_persen : null,
            'nilai_diskon_nominal' => $item->nilai_diskon_nominal !== null ? (float) $item->nilai_diskon_nominal : null,
            'harga_poin' => (int) $item->harga_poin,
            'stok' => (int) $item->stok,
            'deskripsi' => $item->deskripsi,
            'label' => $item->label,
        ];
    }
}