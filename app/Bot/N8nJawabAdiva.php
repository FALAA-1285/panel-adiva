<?php

namespace App\Bot;

use App\Models\CrmCustomer;
use App\Models\User;

/**
 * n8n's JAWAB branch: Adiva words the answer (numbers kept exactly), sends it from the
 * customer's bot number, writes the message and closes the question in the CRM.
 */
class N8nJawabAdiva implements JawabAdiva
{
    public function __construct(private N8nWebhook $n8n) {}

    public function sampaikan(CrmCustomer $customer, string $jawaban, User $oleh): void
    {
        // the agent writes the message first, so it may take as long as Uji tanya Adiva
        $this->n8n->kirim('JAWAB', [
            'chat_id' => $customer->chat_id,
            'jawaban' => trim($jawaban),
            'oleh' => ['id' => $oleh->id, 'nama' => $oleh->name, 'nomor_wa' => $oleh->nomor_wa],
        ], (int) config('services.n8n.timeout_uji', 60));
    }
}
