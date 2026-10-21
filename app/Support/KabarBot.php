<?php

namespace App\Support;

use App\Enums\JenisNotifikasi;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\Workflow;
use App\Models\WorkflowEvent;

/**
 * What the bot writes so the panel's pages stay alive: the notice to the supervisor
 * (Notifikasi, sent as a short WhatsApp by KabarWa) and the run or incident lines on
 * Monitoring (WorkflowEvent). It replaces n8n's `n8n_notifikasi()` and `n8n_event()`.
 */
class KabarBot
{
    /**
     * @param  string  $penerima  ADMIN_WA or SARAH_WA, the CONFIG_TOKO key of who is told
     */
    public static function notifikasi(
        JenisNotifikasi $jenis,
        string $judul,
        ?string $detail = null,
        ?CrmCustomer $customer = null,
        ?LogOrder $order = null,
        string $penerima = 'ADMIN_WA',
    ): Notifikasi {
        return Notifikasi::create([
            'waktu' => now(),
            'jenis' => $jenis->value,
            'kategori' => $jenis->kategori()->value,
            'penerima' => $penerima,
            'judul' => mb_substr($judul, 0, 255),
            'detail' => filled($detail) ? $detail : null,
            'crm_customer_id' => $customer?->id,
            'log_order_id' => $order?->id,
        ]);
    }

    /**
     * A line on Monitoring. An EKSEKUSI with the same $eksekusi is one run: later calls
     * add to it instead of writing a second row.
     *
     * @param  string  $workflow  the workflow's kode ("chatbot")
     * @param  array<string, mixed>  $data
     */
    public static function event(
        string $workflow,
        string $jenis,
        string $status,
        string $judul,
        array $data = [],
        ?string $detail = null,
        ?CrmCustomer $customer = null,
        ?LogOrder $order = null,
        ?string $eksekusi = null,
    ): WorkflowEvent {
        $jenis = strtoupper($jenis);

        if ($jenis === 'EKSEKUSI' && $eksekusi !== null) {
            $ada = WorkflowEvent::query()->where('jenis', 'EKSEKUSI')->where('n8n_execution_id', $eksekusi)->first();

            if ($ada !== null) {
                $ada->update([
                    'status' => strtoupper($status),
                    'judul' => $judul,
                    'detail' => $detail ?? $ada->detail,
                    'data' => [...($ada->data ?? []), ...$data],
                    'crm_customer_id' => $customer->id ?? $ada->crm_customer_id,
                ]);

                return $ada;
            }
        }

        return WorkflowEvent::create([
            'workflow_id' => Workflow::query()->where('kode', $workflow)->value('id'),
            'waktu' => now(),
            'jenis' => $jenis,
            'status' => strtoupper($status),
            'judul' => mb_substr($judul, 0, 255),
            'detail' => $detail,
            'crm_customer_id' => $customer?->id,
            'log_order_id' => $order?->id,
            'n8n_execution_id' => $eksekusi,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
