<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the staff member last set their password: after 90 days the panel asks for a
     * new one (BCA CSA butir 23). Accounts that already have a password start counting today.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('sandi_diubah_at')->nullable()->after('password');
        });

        DB::table('users')->whereNotNull('password')->update(['sandi_diubah_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sandi_diubah_at');
        });
    }
};
