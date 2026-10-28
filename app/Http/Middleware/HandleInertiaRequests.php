<?php

namespace App\Http\Middleware;

use App\Enums\StatusOrder;
use App\Enums\StatusWorkflow;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\SosmedKontak;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Support\Demo;
use App\Support\HakAkses;
use App\Support\Nama;
use App\Support\NomorBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'panel' => fn () => $request->user() ? $this->panel($request->user()) : null,
        ];
    }

    /**
     * Data for the Sidebar and Topbar shown on every panel page.
     *
     * @return array<string, mixed>
     */
    protected function panel(User $user): array
    {
        $hitungan = Demo::aktif()
            ? $this->hitunganDemo()
            : Cache::remember('panel:hitungan', 10, fn () => $this->hitungan());

        $dijeda = $hitungan['jeda'];

        return [
            'waktu' => now()->translatedFormat('l, j M').' · '.now()->format('H:i T'),
            'hitungan' => $hitungan,
            'status' => [
                'nomorWaha' => count(NomorBot::daftar()),
                'workflowAktif' => Workflow::query()->whereIn('status', StatusWorkflow::berjalan())->count(),
                'dijeda' => $dijeda,
                'adivaAktif' => Workflow::query()->where('kode', 'chatbot')->where('status', StatusWorkflow::Aktif->value)->exists(),
            ],
            'pengguna' => [
                'nama' => $user->name,
                'inisial' => Nama::inisial($user->name),
                'peran' => $user->peran->label(),
            ],
            // menu items limited by role (Tim & akses: Hak akses per peran)
            'akses' => [
                'katalog' => HakAkses::boleh($user, 'katalog'),
                'tim' => HakAkses::boleh($user, 'tim'),
                'pengaturan' => HakAkses::boleh($user, 'pengaturan'),
                'balas' => HakAkses::boleh($user, 'balas'),
                'catat' => HakAkses::boleh($user, 'catat'),
                'broadcast' => HakAkses::boleh($user, 'broadcast'),
                'monitoring' => HakAkses::boleh($user, 'monitoring'),
                'aktivitas' => HakAkses::boleh($user, 'aktivitas'),
                // Instagram & Facebook: not in the design, so not in demo mode (the visual tests)
                'sosmed' => ! Demo::aktif(),
            ],
        ];
    }

    /**
     * The demo's clock is frozen, so a cache entry with a lifetime never runs out there.
     * The counts are kept in this process for 10 real seconds instead: a warm serverless
     * instance answers the 5-second polls without eight count queries each (Audit AUD-010).
     *
     * @return array<string, int>
     */
    protected function hitunganDemo(): array
    {
        // tests change the data between requests in one process
        if (app()->runningUnitTests()) {
            return $this->hitungan();
        }

        if (self::$simpananDemo === null || time() - self::$simpananDemo['pada'] >= 10) {
            self::$simpananDemo = ['pada' => time(), 'hitungan' => $this->hitungan()];
        }

        return self::$simpananDemo['hitungan'];
    }

    /** @var array{pada: int, hitungan: array<string, int>}|null */
    private static ?array $simpananDemo = null;

    /**
     * @return array<string, int>
     */
    protected function hitungan(): array
    {
        return [
            'percakapan' => CrmCustomer::query()->where('butuh_admin', true)->count(),
            'pesanan' => LogOrder::query()->where('status_order', StatusOrder::MenungguKonfirmasi)->count(),
            'jeda' => JedaBot::aktif()->count(),
            'monitoring' => WorkflowEvent::query()->where('jenis', 'INSIDEN')->where('status', 'PERLU_TINDAKAN')->count(),
            'notifikasi' => Notifikasi::query()->whereNull('dibaca_at')->count(),
            'sosmed' => SosmedKontak::query()->where('butuh_admin', 'YA')->count(),
        ];
    }
}
