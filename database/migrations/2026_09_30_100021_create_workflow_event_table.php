<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Workflow runs and what they caught: executions, handled or open incidents,
     * WhatsApp connection checks, and reports sent to the supervisor.
     */
    public function up(): void
    {
        Schema::create('workflow_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->nullable()->constrained('workflow')->nullOnDelete();
            $table->timestampTz('waktu')->index();
            $table->string('jenis', 20);
            $table->string('status', 20);
            $table->string('judul');
            $table->text('detail')->nullable();
            $table->string('aksi')->nullable();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->foreignId('log_order_id')->nullable()->constrained('log_order')->nullOnDelete();
            $table->string('n8n_execution_id')->nullable();
            $table->unsignedInteger('durasi_ms')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampsTz();

            $table->index(['jenis', 'waktu']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_event');
    }
};
