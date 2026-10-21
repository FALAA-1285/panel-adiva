<?php

use App\Bot\PengirimanGagal;
use App\Bot\PengirimWa;
use App\Bot\WahaPengirim;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Jobs\KirimPesanWa;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\Pesan;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $isi
 */
function balasanAdmin(CrmCustomer $c, array $isi = []): Pesan
{
    return Pesan::create([
        'crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'sesi_wa' => $c->sesi_wa, 'waktu' => now(),
        'pengirim' => PengirimPesan::Admin, 'teks' => 'Harga khusus Rp17.000', 'status_kirim' => 'MENUNGGU', ...$isi,
    ]);
}

function pakaiWaha(): void
{
    config(['services.waha.url' => 'https://waha.test/', 'services.waha.key' => 'kunci']);
    app()->bind(PengirimWa::class, WahaPengirim::class);
}

test('a reply is queued to be sent', function () {
    Queue::fake();
    $c = CrmCustomer::factory()->create();

    $this->actingAs(User::factory()->falah()->create())->post("/percakapan/{$c->id}/balas", ['teks' => 'Halo kak']);

    Queue::assertPushed(KirimPesanWa::class, fn (KirimPesanWa $job) => $job->pesan->teks === 'Halo kak' && $job->pesan->status_kirim === 'MENUNGGU');
});

test('WAHA sends it from the customer\'s bot number', function () {
    pakaiWaha();
    Http::fake(['waha.test/api/sendText' => Http::response(['id' => ['_serialized' => 'true_6281355660042@c.us_3EB0A1']])]);
    $c = CrmCustomer::factory()->create(['chat_id' => '6281355660042@c.us', 'sesi_wa' => 'bangbana-2']);
    $pesan = balasanAdmin($c);

    (new KirimPesanWa($pesan))->handle(app(PengirimWa::class));

    expect($pesan->refresh()->status_kirim)->toBe('TERKIRIM')
        ->and($pesan->wa_message_id)->toBe('true_6281355660042@c.us_3EB0A1');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/sendText'
        && $r->header('X-Api-Key')[0] === 'kunci'
        && $r->data() === ['session' => 'bangbana-2', 'chatId' => '6281355660042@c.us', 'text' => 'Harga khusus Rp17.000']);
});

test('a customer without a known number uses SESI_UTAMA', function () {
    pakaiWaha();
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    Http::fake(['waha.test/*' => Http::response(['id' => 'true_x_1'])]);
    $pesan = balasanAdmin(CrmCustomer::factory()->create(['sesi_wa' => null]));

    (new KirimPesanWa($pesan))->handle(app(PengirimWa::class));

    expect($pesan->refresh()->sesi_wa)->toBe('bangbana-waha');
    Http::assertSent(fn (Request $r) => $r['session'] === 'bangbana-waha');
});

test('a reply WAHA refuses is marked to be sent again', function () {
    pakaiWaha();
    Http::fake(['waha.test/*' => Http::response('session STOPPED', 422)]);
    $pesan = balasanAdmin(CrmCustomer::factory()->create(['sesi_wa' => 'bangbana-2']));
    $job = new KirimPesanWa($pesan);

    expect(fn () => $job->handle(app(PengirimWa::class)))->toThrow(PengirimanGagal::class);

    $job->failed(new PengirimanGagal('WAHA menolak (422): session STOPPED'));
    expect($pesan->refresh()->status_kirim)->toBe('GAGAL')
        ->and($pesan->meta)->toBe(['galat' => 'WAHA menolak (422): session STOPPED']);
});

test('without any bot number the reply fails at once, without retrying', function () {
    Http::fake();
    $pesan = balasanAdmin(CrmCustomer::factory()->create(['sesi_wa' => null]));

    KirimPesanWa::dispatchSync($pesan);

    expect($pesan->refresh()->status_kirim)->toBe('GAGAL')
        ->and($pesan->meta)->toBe(['galat' => 'Nomor bot untuk customer ini tidak diketahui (SESI_UTAMA kosong)']);
    Http::assertNothingSent();
});

test('the stand-in sends nothing and marks it sent', function () {
    Http::fake();
    $pesan = balasanAdmin(CrmCustomer::factory()->create(['sesi_wa' => 'bangbana-waha']));

    (new KirimPesanWa($pesan))->handle(app(PengirimWa::class));

    expect($pesan->refresh()->status_kirim)->toBe('TERKIRIM')
        ->and($pesan->wa_message_id)->toStartWith('tiruan-');
    Http::assertNothingSent();
});

test('a failed line can be sent again', function () {
    Queue::fake();
    $c = CrmCustomer::factory()->create(['nama' => 'Nisa Aulia']);
    $gagal = balasanAdmin($c, ['status_kirim' => 'GAGAL']);
    $terkirim = balasanAdmin($c, ['status_kirim' => 'TERKIRIM']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->post("/percakapan/pesan/{$gagal->id}/kirim-ulang")->assertRedirect();
    $this->actingAs($falah)->post("/percakapan/pesan/{$terkirim->id}/kirim-ulang")->assertSessionHasErrors('pesan');
    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))->post("/percakapan/pesan/{$gagal->id}/kirim-ulang")->assertForbidden();

    expect($gagal->refresh()->status_kirim)->toBe('MENUNGGU');
    Queue::assertPushed(KirimPesanWa::class, 1);
});
