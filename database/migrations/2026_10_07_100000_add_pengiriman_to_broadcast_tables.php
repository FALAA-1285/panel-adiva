<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The panel sends campaigns itself (KirimBroadcast): one chain of jobs per campaign.
     */
    public function up(): void
    {
        Schema::table('broadcast', function (Blueprint $table) {
            // the running chain of jobs; starting a new one stops the old
            $table->uuid('giliran')->nullable();
            // when the next message is due (broadcast:lanjutkan restarts a chain that is late)
            $table->timestampTz('berikutnya_at')->nullable();
        });

        Schema::table('broadcast_penerima', function (Blueprint $table) {
            // WhatsApp's id of the sent message, so n8n can mark it read
            $table->string('wa_message_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('broadcast_penerima', function (Blueprint $table) {
            $table->dropColumn('wa_message_id');
        });

        Schema::table('broadcast', function (Blueprint $table) {
            $table->dropColumn(['giliran', 'berikutnya_at']);
        });
    }
};
