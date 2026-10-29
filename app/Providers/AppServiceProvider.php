<?php

namespace App\Providers;

use App\Bot\JawabAdiva;
use App\Bot\KabarKonfig;
use App\Bot\KirimUlangInvoice;
use App\Bot\LokalJawabAdiva;
use App\Bot\LokalKirimUlangInvoice;
use App\Bot\LokalPerintahOrder;
use App\Bot\MetaPengirim;
use App\Bot\N8nJawabAdiva;
use App\Bot\N8nKabarKonfig;
use App\Bot\N8nKirimUlangInvoice;
use App\Bot\N8nPerintahOrder;
use App\Bot\N8nTanyaAdiva;
use App\Bot\PengirimSosmed;
use App\Bot\PengirimWa;
use App\Bot\PerintahOrder;
use App\Bot\SesiWaha;
use App\Bot\TanyaAdiva;
use App\Bot\TiruanJawabAdiva;
use App\Bot\TiruanKabarKonfig;
use App\Bot\TiruanKirimUlangInvoice;
use App\Bot\TiruanPengirim;
use App\Bot\TiruanPengirimSosmed;
use App\Bot\TiruanPerintahOrder;
use App\Bot\TiruanSesi;
use App\Bot\TiruanTanyaAdiva;
use App\Bot\WahaPengirim;
use App\Bot\WahaSesi;
use App\Models\Broadcast;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use App\Models\HargaKaryawan;
use App\Models\JedaBot;
use App\Models\Karyawan;
use App\Models\Kebijakan;
use App\Models\LogOrder;
use App\Models\PaketReseller;
use App\Models\Produk;
use App\Models\PromptVersi;
use App\Models\TemplateFollowup;
use App\Models\User;
use App\Models\Workflow;
use App\Support\Demo;
use App\Support\HakAkses;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Adiva's bot. BOT_DRIVER decides who does the work:
        //   tiruan   - stand-ins that change the database and send nothing (always in demo mode)
        //   langsung - n8n runs it through the signed webhook
        //   lokal    - the panel runs it itself; a contract the panel has not taken over yet stays with n8n
        // [n8n, stand-in, panel]: the third is left out while the panel does not do it yet.
        $driver = [
            PerintahOrder::class => [N8nPerintahOrder::class, TiruanPerintahOrder::class, LokalPerintahOrder::class],
            PengirimWa::class => [WahaPengirim::class, TiruanPengirim::class],
            SesiWaha::class => [WahaSesi::class, TiruanSesi::class],
            TanyaAdiva::class => [N8nTanyaAdiva::class, TiruanTanyaAdiva::class],
            KabarKonfig::class => [N8nKabarKonfig::class, TiruanKabarKonfig::class],
            KirimUlangInvoice::class => [N8nKirimUlangInvoice::class, TiruanKirimUlangInvoice::class, LokalKirimUlangInvoice::class],
            JawabAdiva::class => [N8nJawabAdiva::class, TiruanJawabAdiva::class, LokalJawabAdiva::class],
            PengirimSosmed::class => [MetaPengirim::class, TiruanPengirimSosmed::class],
        ];

        foreach ($driver as $kontrak => $kelas) {
            [$langsung, $tiruan] = $kelas;
            $lokal = $kelas[2] ?? $langsung;

            $this->app->bind($kontrak, fn ($app) => $app->make(match (true) {
                Demo::aktif() => $tiruan,
                config('services.bot.driver') === 'lokal' => $lokal,
                config('services.bot.driver') === 'langsung' => $langsung,
                default => $tiruan,
            }));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDemo();
        $this->configureMorphMap();
        $this->configureAkses();
    }

    /**
     * One gate per row of the "Hak akses per peran di panel" table.
     */
    protected function configureAkses(): void
    {
        foreach (array_keys(HakAkses::AKSI) as $aksi) {
            Gate::define($aksi, fn (User $user) => HakAkses::boleh($user, $aksi));
        }
    }

    /**
     * Store table names instead of class names in *_type columns, so rows written
     * by n8n and by the panel use the same values.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'config_toko' => ConfigToko::class,
            'produk' => Produk::class,
            'paket_reseller' => PaketReseller::class,
            'gambar_produk' => GambarProduk::class,
            'kebijakan' => Kebijakan::class,
            'karyawan' => Karyawan::class,
            'harga_karyawan' => HargaKaryawan::class,
            'template_followup' => TemplateFollowup::class,
            'crm_customer' => CrmCustomer::class,
            'log_order' => LogOrder::class,
            'jeda_bot' => JedaBot::class,
            'broadcast' => Broadcast::class,
            'prompt_versi' => PromptVersi::class,
            'workflow' => Workflow::class,
        ]);
    }

    /**
     * Freeze the clock to the moment shown in design/source while demo mode is on.
     * HTTP requests are frozen by the FreezeDemoClock middleware instead, so that
     * session and CSRF cookies are still stamped with the real time.
     */
    protected function configureDemo(): void
    {
        if (! Demo::aktif()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            Demo::bekukanJam();
        }

        // Session garbage collection runs on the real clock and would remove the
        // demo sessions, which are stamped on 28 Sep (Tim & akses: Sesi & keamanan).
        config(['session.lottery' => [0, 100]]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        if (app()->isProduction() || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // one rule for every password a staff member sets (BCA CSA butir 23): at least 8
        // characters with upper and lower case, a number and a symbol; in production it is
        // also checked against known leaked passwords
        Password::defaults(function (): Password {
            $aturan = Password::min(8)->mixedCase()->numbers()->symbols();

            return app()->isProduction() ? $aturan->uncompromised() : $aturan;
        });
    }
}
