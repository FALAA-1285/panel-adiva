<?php

namespace App\Bot;

use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use App\Models\Pesan;
use App\Models\User;

/**
 * Stands in for n8n (demo mode, the tests): the answer goes into the chat as it was
 * typed and the question is closed; nothing is sent.
 */
class TiruanJawabAdiva implements JawabAdiva
{
    public function sampaikan(CrmCustomer $customer, string $jawaban, User $oleh): void
    {
        Pesan::create([
            'crm_customer_id' => $customer->id,
            'chat_id' => $customer->chat_id,
            'sesi_wa' => $customer->sesi_wa,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Adiva,
            'teks' => trim($jawaban),
            'status_kirim' => 'TERKIRIM',
            'meta' => ['jawaban_supervisor' => $oleh->name],
        ]);

        $customer->forceFill([
            'eskalasi_terbuka' => null,
            'jawaban_supervisor' => json_encode(['waktu' => now()->format('Y-m-d H:i'), 'jawaban' => trim($jawaban)]),
        ])->save();
    }
}
