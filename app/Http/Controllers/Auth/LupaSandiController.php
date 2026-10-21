<?php

namespace App\Http\Controllers\Auth;

use App\Bot\KirimKeStaf;
use App\Bot\PengirimanGagal;
use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CekKodeResetRequest;
use App\Http\Requests\Auth\KirimKodeResetRequest;
use App\Http\Requests\Auth\SimpanSandiRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Demo;
use App\Support\KodeVerifikasi;
use App\Support\NomorWa;
use App\Support\PengaturanToko;
use App\Support\Perangkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password reset through the registered WhatsApp number (LupaSandi.dc.html):
 * number → 6-digit code → new password.
 */
class LupaSandiController extends Controller
{
    /** "3 PERCOBAAN": wrong codes before the reset is locked. */
    private const MAKS_SALAH = 3;

    /** Code sends per reset (the first one plus two resends). */
    private const MAKS_KIRIM = 3;

    /** "Setelah tiga kali salah, reset dikunci 15 menit". */
    private const MENIT_KUNCI = 15;

    /** The code shown in LupaSandi.dc.html; used only in demo mode (and by the tests). */
    public const KODE_DEMO = '710593';

    /**
     * Step 1: send a reset code to the staff member's WhatsApp number.
     */
    public function kirimKode(KirimKodeResetRequest $request, KirimKeStaf $kirimKeStaf): JsonResponse
    {
        $staf = User::query()
            ->where('nomor_wa', NomorWa::normalisasi($request->string('nomor_wa')))
            ->whereNull('nonaktif_at')
            ->first();

        if (! $staf) {
            throw ValidationException::withMessages(['nomor_wa' => 'Nomor ini tidak ada di daftar staf.']);
        }

        $this->pastikanTidakTerkunci($staf);

        $sebelumnya = $request->session()->get('reset');
        $kirim = ($sebelumnya['user_id'] ?? null) === $staf->id ? $sebelumnya['kirim'] + 1 : 1;

        if ($kirim > self::MAKS_KIRIM) {
            throw ValidationException::withMessages(['nomor_wa' => 'Kode sudah dikirim tiga kali. Hubungi supervisor.']);
        }

        // "Kode lama otomatis hangus saat Anda minta yang baru": the session keeps only the latest.
        $kode = Demo::aktif() ? self::KODE_DEMO : KodeVerifikasi::buat();
        KodeVerifikasi::kirim($kirimKeStaf, (string) $staf->nomor_wa, "Kode reset kata sandi panel Adiva: {$kode}");

        $request->session()->put('reset', [
            'user_id' => $staf->id,
            'kode' => KodeVerifikasi::hash($kode),
            'berlaku_sampai' => now()->addMinutes(5)->getTimestamp(),
            'kirim' => $kirim,
            'terverifikasi' => false,
        ]);

        return response()->json([
            'nomor' => NomorWa::samarkan((string) $staf->nomor_wa),
            'sisaKirim' => self::MAKS_KIRIM - $kirim,
        ]);
    }

    /**
     * Step 2: check the code.
     */
    public function cekKode(CekKodeResetRequest $request, KirimKeStaf $kirimKeStaf): JsonResponse
    {
        $reset = $this->resetBerjalan($request);
        $staf = User::query()->findOrFail($reset['user_id']);

        $this->pastikanTidakTerkunci($staf);

        if (! KodeVerifikasi::cocok($request->string('kode'), $reset['kode'])) {
            Cache::add($this->kunciSalah($staf), 0, now()->addMinutes(self::MENIT_KUNCI));

            if (Cache::increment($this->kunciSalah($staf)) >= self::MAKS_SALAH) {
                Cache::put($this->kunciReset($staf), true, now()->addMinutes(self::MENIT_KUNCI));
                Cache::forget($this->kunciSalah($staf));
                $request->session()->forget('reset');
                $this->kabariSupervisor($kirimKeStaf, $staf);
            }

            throw ValidationException::withMessages(['kode' => 'Kode salah.']);
        }

        Cache::forget($this->kunciSalah($staf));
        $request->session()->put('reset.terverifikasi', true);
        // time to type the new password
        $request->session()->put('reset.berlaku_sampai', now()->addMinutes(10)->getTimestamp());

        return response()->json(['email' => $staf->email]);
    }

    /**
     * Step 3: save the new password, then back to Masuk ("Simpan & masuk lagi").
     */
    public function simpan(SimpanSandiRequest $request): RedirectResponse
    {
        $reset = $this->resetBerjalan($request);

        if (! $reset['terverifikasi']) {
            throw ValidationException::withMessages(['password' => 'Masukkan kode reset dulu.']);
        }

        $staf = User::query()->findOrFail($reset['user_id']);
        $keluarkan = $request->boolean('keluarkan');

        $staf->forceFill(['password' => $request->string('password')->value()]);

        if ($keluarkan) {
            // "Keluarkan akun ini dari semua perangkat lain"
            $staf->setRememberToken(Str::random(60));
            DB::table('sessions')->where('user_id', $staf->id)->delete();
        }

        $staf->save();

        ActivityLog::create([
            'waktu' => now(),
            'user_id' => $staf->id,
            'pelaku' => $staf->name,
            'sumber' => SumberAktivitas::Panel,
            'kategori' => KategoriAktivitas::Login->value,
            'aksi' => 'Reset kata sandi',
            'detail' => Perangkat::dari($request->userAgent()).($keluarkan ? ' · perangkat lain dikeluarkan' : ''),
            'subjek_type' => $staf->getMorphClass(),
            'subjek_id' => $staf->id,
            'data' => ['user_agent' => $request->userAgent()],
        ]);

        $request->session()->forget('reset');

        return redirect()->route('login');
    }

    /**
     * @return array{user_id: int, kode: string, berlaku_sampai: int, kirim: int, terverifikasi: bool}
     */
    private function resetBerjalan(Request $request): array
    {
        /** @var array{user_id: int, kode: string, berlaku_sampai: int, kirim: int, terverifikasi: bool}|null $reset */
        $reset = $request->session()->get('reset');

        if (! $reset || now()->getTimestamp() > $reset['berlaku_sampai']) {
            $request->session()->forget('reset');

            throw ValidationException::withMessages(['kode' => 'Kode sudah tidak berlaku. Kirim ulang kode.']);
        }

        return $reset;
    }

    private function pastikanTidakTerkunci(User $staf): void
    {
        if (Cache::has($this->kunciReset($staf))) {
            throw ValidationException::withMessages([
                'kode' => 'Reset dikunci '.self::MENIT_KUNCI.' menit setelah tiga kali salah.',
            ]);
        }
    }

    /**
     * "3 PERCOBAAN … dikunci 15 menit dan supervisor dikabari". A notice that does not
     * go out must not stop the reset page from answering.
     */
    private function kabariSupervisor(KirimKeStaf $kirimKeStaf, User $staf): void
    {
        $supervisor = PengaturanToko::nilai('ADMIN_WA');

        if (blank($supervisor)) {
            return;
        }

        try {
            $kirimKeStaf->kirim($supervisor, "Reset kata sandi {$staf->name} dikunci ".self::MENIT_KUNCI.' menit: kode salah tiga kali.');
        } catch (PengirimanGagal $e) {
            report($e);
        }
    }

    private function kunciReset(User $staf): string
    {
        return 'reset-sandi:kunci:'.$staf->id;
    }

    private function kunciSalah(User $staf): string
    {
        return 'reset-sandi:salah:'.$staf->id;
    }
}
