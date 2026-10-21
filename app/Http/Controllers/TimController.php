<?php

namespace App\Http\Controllers;

use App\Bot\KirimKeStaf;
use App\Bot\PengirimanGagal;
use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\SumberAktivitas;
use App\Http\Requests\SimpanKeamananRequest;
use App\Http\Requests\SimpanStafRequest;
use App\Jobs\KabariKonfigN8n;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\HakAkses;
use App\Support\Nama;
use App\Support\NomorWa;
use App\Support\PengaturanKeamanan;
use App\Support\PengaturanToko;
use App\Support\Perangkat;
use App\Support\Waktu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim & akses (Tim.dc.html): who can use the panel, which numbers Adiva treats as
 * admin (ADMIN_STAFF), what each role may do, and the open sessions.
 */
class TimController extends Controller
{
    public function index(Request $request): Response
    {
        $staf = User::query()->orderBy('id')->get();

        return Inertia::render('tim', [
            'staf' => $staf->map(fn (User $user) => [
                'id' => $user->id,
                'nama' => $user->name,
                'email' => $user->email,
                'inisial' => Nama::inisial($user->name),
                'nomor' => $this->nomor($user),
                'peran' => $user->peran->label(),
                'akses' => $this->akses($user),
                'terakhirMasuk' => $this->terakhirMasuk($user),
                // the "Ubah" form (only supervisors open this page)
                'isian' => [
                    'nama' => $user->name,
                    'email' => $user->email,
                    'nomor_wa' => (string) $user->nomor_wa,
                    'peran' => $user->peran->value,
                    'cabang' => (string) $user->cabang,
                ],
                'nonaktif' => $user->nonaktif_at !== null,
                'belumDaftar' => $user->password === null,
            ]),
            'peran' => collect(Peran::cases())->map(fn (Peran $peran) => ['kode' => $peran->value, 'label' => $peran->label()]),
            'hakAkses' => HakAkses::matriks(),
            'sesi' => $this->sesi($request, $staf),
            'keamanan' => [
                'kodePerangkatBaru' => PengaturanKeamanan::kodePerangkatBaru(),
                'keluarOtomatis' => PengaturanKeamanan::keluarOtomatisJam(),
                'pilihan' => PengaturanKeamanan::PILIHAN_JAM,
            ],
        ]);
    }

    /**
     * "Kode WhatsApp di perangkat baru" and "Keluar otomatis", saved (and logged) as
     * soon as they change.
     */
    public function simpanKeamanan(SimpanKeamananRequest $request): RedirectResponse
    {
        $oleh = $request->user();
        $perangkat = Perangkat::dari($request->userAgent());

        if ($oleh && $request->has('kode_perangkat_baru')) {
            PengaturanToko::ubah(PengaturanKeamanan::KODE_PERANGKAT_BARU, $request->boolean('kode_perangkat_baru') ? '1' : '0', $oleh, $perangkat);
        }

        if ($oleh && $request->has('keluar_otomatis')) {
            PengaturanToko::ubah(PengaturanKeamanan::KELUAR_OTOMATIS, (string) $request->integer('keluar_otomatis'), $oleh, $perangkat);
        }

        Inertia::flash('toast', 'Pengaturan keamanan disimpan.');

        return back();
    }

    /**
     * "Keluarkan": end a staff member's panel sessions on one device.
     */
    public function keluarkan(Request $request, User $user): RedirectResponse
    {
        $perangkat = $request->validate(['perangkat' => ['required', 'string']])['perangkat'];

        $id = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->get(['id', 'user_agent'])
            ->filter(fn (object $sesi) => Perangkat::dari($sesi->user_agent) === $perangkat)
            ->pluck('id');

        DB::table('sessions')->whereIn('id', $id)->delete();

        Inertia::flash('toast', "{$user->name} dikeluarkan dari {$perangkat}.");

        return back();
    }

    /**
     * "Undang staf": the staff member is on the list at once (Akses "Undangan terkirim")
     * and gets a WhatsApp message to make their account on /daftar.
     */
    public function undang(SimpanStafRequest $request, KirimKeStaf $kirimKeStaf): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);
        $staf = User::create([...$this->isian($request), 'diundang_at' => now()]);

        Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Undang staf', "{$staf->name} · {$staf->peran->label()}", $staf);
        $this->kirimUndangan($kirimKeStaf, $staf, 'Staf ditambahkan, tetapi undangan WhatsApp gagal');

        Inertia::flash('toast', "Undangan dikirim ke {$staf->name}.");

        return back();
    }

    /**
     * "Ubah": name, email, number, role and branch.
     */
    public function ubah(SimpanStafRequest $request, User $user): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);
        $user->update($this->isian($request));

        Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Ubah staf', "{$user->name} · {$user->peran->label()}", $user);

        Inertia::flash('toast', "Data {$user->name} disimpan.");

        return back();
    }

    /**
     * "Nonaktifkan": no panel, and n8n stops reading the number as an admin.
     */
    public function nonaktifkan(Request $request, User $user): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);

        if ($user->is($oleh)) {
            throw ValidationException::withMessages(['staf' => 'Anda tidak bisa menonaktifkan diri sendiri.']);
        }

        $user->forceFill(['nonaktif_at' => now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Nonaktifkan staf', $user->name, $user);

        Inertia::flash('toast', "{$user->name} dinonaktifkan.");

        return back();
    }

    public function aktifkan(Request $request, User $user): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);
        $user->forceFill(['nonaktif_at' => null])->save();

        Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Aktifkan staf', $user->name, $user);

        Inertia::flash('toast', "{$user->name} diaktifkan lagi.");

        return back();
    }

    /**
     * "Kirim ulang undangan", while the staff member has not made their account yet.
     */
    public function kirimUlangUndangan(Request $request, User $user, KirimKeStaf $kirimKeStaf): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);

        if ($user->password !== null) {
            throw ValidationException::withMessages(['staf' => "{$user->name} sudah membuat akun."]);
        }

        $user->forceFill(['diundang_at' => now()])->save();
        $this->kirimUndangan($kirimKeStaf, $user, 'Undangan WhatsApp gagal');
        Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Kirim ulang undangan', $user->name, $user);

        Inertia::flash('toast', "Undangan dikirim ulang ke {$user->name}.");

        return back();
    }

    /**
     * @return array{name: string, email: string, nomor_wa: string, peran: string, cabang: string|null}
     */
    private function isian(SimpanStafRequest $request): array
    {
        return [
            'name' => $request->string('nama')->trim()->value(),
            'email' => $request->string('email')->trim()->lower()->value(),
            'nomor_wa' => $request->string('nomor_wa')->value(),
            'peran' => $request->string('peran')->value(),
            'cabang' => $request->filled('cabang') ? $request->string('cabang')->trim()->value() : null,
        ];
    }

    private function kirimUndangan(KirimKeStaf $kirimKeStaf, User $staf, string $awalanGagal): void
    {
        try {
            $kirimKeStaf->kirim((string) $staf->nomor_wa, "Halo {$staf->name}, Anda diundang ke panel admin Adiva (Bang Bana) sebagai {$staf->peran->label()}. "
                .'Buat akun di '.url('/daftar')." dengan email {$staf->email} dan nomor ini.");
        } catch (PengirimanGagal $e) {
            throw ValidationException::withMessages(['staf' => "{$awalanGagal}: {$e->getMessage()}. Coba Kirim ulang undangan."]);
        }
    }

    /**
     * "Keluarkan" on a WhatsApp row: n8n refuses admin commands from this number until
     * the staff member signs in to the panel again (App\Listeners\CatatMasuk).
     */
    public function keluarkanWhatsapp(Request $request, User $user): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);
        $user->forceFill(['wa_dicabut_at' => now()])->save();

        Aktivitas::catat($oleh, KategoriAktivitas::Login, 'Keluarkan dari WhatsApp', trim("{$user->name} · WhatsApp ".$this->nomor($user)), $user);
        // n8n's "Cek Apakah Admin" reads users.wa_dicabut_at itself; this is only a notice
        KabariKonfigN8n::dispatch('AKSES_ADMIN_WA', ['user_id' => $user->id, 'nomor_wa' => $user->nomor_wa, 'dicabut' => true]);

        Inertia::flash('toast', "{$user->name} dikeluarkan dari WhatsApp.");

        return back();
    }

    /**
     * Staff numbers show two digits; the PJ karyawan number (SARAH_WA) shows four,
     * as on Karyawan and Settings.
     */
    private function nomor(User $user): ?string
    {
        if (! $user->nomor_wa) {
            return null;
        }

        return $user->peran === Peran::PjKaryawan
            ? NomorWa::samarkanTengah($user->nomor_wa)
            : NomorWa::samarkanLokal($user->nomor_wa);
    }

    /**
     * @return array{label: string, nada: string}
     */
    private function akses(User $user): array
    {
        return match (true) {
            $user->nonaktif_at !== null => ['label' => 'Nonaktif', 'nada' => 'gray'],
            $user->terakhir_masuk_at === null => ['label' => 'Undangan terkirim', 'nada' => 'amber'],
            default => ['label' => 'Aktif', 'nada' => 'green'],
        };
    }

    /**
     * "Hari ini 13:40 · Chrome, Windows", "12 Sep · Chrome, Android", "Belum pernah masuk".
     */
    private function terakhirMasuk(User $user): string
    {
        $waktu = $user->terakhir_masuk_at;

        if ($waktu === null) {
            return 'Belum pernah masuk';
        }

        $kapan = $waktu->isToday() ? 'Hari ini '.$waktu->format('H:i') : $waktu->translatedFormat('j M');
        $perangkat = str_replace(' · ', ', ', (string) $user->terakhir_masuk_perangkat);

        return $perangkat === '' ? $kapan : "{$kapan} · {$perangkat}";
    }

    /**
     * Sessions still inside "Keluar otomatis": panel logins (one row per staff member
     * and device) and admin commands sent from a staff WhatsApp number.
     * The signed-in staff member comes first, then whoever was active last.
     *
     * @param  Collection<int, User>  $staf
     * @return list<array<string, bool|int|string|null>>
     */
    private function sesi(Request $request, Collection $staf): array
    {
        $batas = now()->subHours(PengaturanKeamanan::keluarOtomatisJam());
        $sesiIni = $request->session()->getId();
        $staf = $staf->keyBy('id');

        $web = DB::table('sessions')
            ->whereIn('user_id', $staf->keys())
            ->where('last_activity', '>=', $batas->getTimestamp())
            ->get(['id', 'user_id', 'user_agent', 'last_activity'])
            ->groupBy(fn (object $sesi) => $sesi->user_id.'|'.Perangkat::dari($sesi->user_agent))
            ->map(function (Collection $grup) use ($staf, $sesiIni) {
                $terbaru = $grup->sortByDesc('last_activity')->first();
                $user = $staf[$terbaru->user_id];
                $perangkat = Perangkat::dari($terbaru->user_agent);
                $ini = $grup->contains('id', $sesiIni);
                $waktu = Date::createFromTimestamp($terbaru->last_activity);

                return [
                    'kunci' => "web-{$user->id}-{$perangkat}",
                    'jenis' => 'web',
                    'userId' => $user->id,
                    'perangkat' => $perangkat,
                    'judul' => collect([$user->name, $perangkat, $user->cabang])->filter()->implode(' · '),
                    'keterangan' => $ini ? 'Sesi ini' : Waktu::lalu($waktu),
                    'ini' => $ini,
                    'waktu' => $waktu->getTimestamp(),
                ];
            });

        $whatsapp = ActivityLog::query()
            ->where('sumber', SumberAktivitas::WhatsApp)
            ->whereIn('user_id', $staf->keys())
            ->where('waktu', '>=', $batas)
            ->toBase()
            ->selectRaw('user_id, max(waktu) as waktu')
            ->groupBy('user_id')
            ->get()
            // taken away with "Keluarkan" until they sign in again
            ->reject(fn (object $baris) => $staf[$baris->user_id]->wa_dicabut_at !== null)
            ->map(function (object $baris) use ($staf) {
                $user = $staf[$baris->user_id];

                return [
                    'kunci' => "whatsapp-{$user->id}",
                    'jenis' => 'whatsapp',
                    'userId' => $user->id,
                    'perangkat' => null,
                    'judul' => trim("{$user->name} · WhatsApp ".$this->nomor($user)),
                    'keterangan' => 'Perintah admin',
                    'ini' => false,
                    'waktu' => Date::parse($baris->waktu)->getTimestamp(),
                ];
            });

        $saya = $request->user()?->id;

        $urut = $web->values()
            ->concat($whatsapp)
            ->groupBy('userId')
            ->sortBy(fn (Collection $baris, int $userId) => [$userId === $saya ? 0 : 1, -$baris->max('waktu')])
            ->flatMap(fn (Collection $baris) => $baris->sortBy(fn (array $s) => [! $s['ini'], $s['jenis'] === 'whatsapp', -$s['waktu']]))
            ->map(fn (array $s) => collect($s)->except('waktu')->all());

        return array_values($urut->all());
    }
}
