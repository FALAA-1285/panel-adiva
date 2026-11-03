<?php

namespace App\Console\Commands;

use App\Bot\SinkronChatN8n as Sinkron;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('n8n:sinkron-chat')]
#[Description('Salin chat chatbot utama (Data Table RIWAYAT_CHAT dan CRM_CUSTOMER di n8n) ke Percakapan, hanya membaca n8n')]
class SinkronChatN8n extends Command
{
    public function handle(Sinkron $sinkron): int
    {
        try {
            $hasil = $sinkron->jalankan();
        } catch (Throwable $e) {
            $this->error('Sinkron chat gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sinkron chat selesai: {$hasil['customer']} customer diperbarui, {$hasil['pesan']} pesan baru.");

        return self::SUCCESS;
    }
}
