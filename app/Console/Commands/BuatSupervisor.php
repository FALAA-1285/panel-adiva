<?php

namespace App\Console\Commands;

use App\Enums\Peran;
use App\Models\User;
use App\Support\NomorWa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates an initial or new supervisor user for panel administration.
 * Solves the bootstrap/onboarding problem on fresh non-demo production environments.
 */
#[Signature('buat:supervisor {nama : Nama lengkap supervisor} {email : Alamat email} {nomor_wa : Nomor WhatsApp (diawali 08 atau 62)} {--password= : Kata sandi awal (opsional, jika kosong dapat dibuat lewat /daftar)}')]
#[Description('Buat akun staf supervisor baru')]
class BuatSupervisor extends Command
{
    public function handle(): int
    {
        $nama = trim((string) $this->argument('nama'));
        $email = Str::lower(trim((string) $this->argument('email')));
        $nomor = NomorWa::normalisasi((string) $this->argument('nomor_wa'));
        $password = $this->option('password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Format email '{$email}' tidak valid.");

            return self::FAILURE;
        }

        if (blank($nomor)) {
            $this->error('Nomor WhatsApp tidak valid.');

            return self::FAILURE;
        }

        $ada = User::query()->where('email', $email)->orWhere('nomor_wa', $nomor)->first();
        if ($ada) {
            $this->error("User dengan email atau nomor WhatsApp tersebut sudah terdaftar ({$ada->name} - {$ada->email}).");

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $nama,
            'email' => $email,
            'nomor_wa' => $nomor,
            'peran' => Peran::Supervisor,
            'password' => filled($password) ? Hash::make((string) $password) : null,
            'diundang_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->info("Supervisor '{$user->name}' ({$user->email}) berhasil dibuat.");
        if (blank($password)) {
            $this->line('Pengguna dapat melengkapi kata sandi di halaman /daftar menggunakan email dan nomor WhatsApp tersebut.');
        }

        return self::SUCCESS;
    }
}
