<?php

use App\Bot\N8nPerintahOrder;
use App\Bot\PerintahOrder;
use App\Bot\TiruanPerintahOrder;
use App\Enums\StatusOrder;
use App\Models\ActivityLog;
use App\Models\LogOrder;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia']);
    $this->app->bind(PerintahOrder::class, N8nPerintahOrder::class);
    $this->order = LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'total_produk' => 1245000, 'ongkir' => 38000]);
});

test('demo mode and the tests use the stand-in', function () {
    $this->app->forgetInstance(PerintahOrder::class);
    $this->app->offsetUnset(PerintahOrder::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(PerintahOrder::class))->toBeInstanceOf(TiruanPerintahOrder::class);
});

test('a command goes to n8n signed, and the panel logs it once n8n has run it', function () {
    Http::fake(['n8n.test/*' => function () {
        // n8n runs ACC: the order changes in the shared database before it answers
        LogOrder::query()->where('order_id', 'ORD-260928-014')->update([
            'status_order' => StatusOrder::Diacc, 'invoice_number' => 'INV/2026/09/142', 'diacc_at' => now(),
        ]);

        return Http::response(['ok' => true, 'pesan' => 'Invoice INV/2026/09/142 terkirim']);
    }]);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (Request $r) {
        $waktu = $r->header('X-Adiva-Timestamp')[0];

        return $r->url() === 'https://n8n.test/webhook/panel'
            && $r->header('X-Adiva-Signature')[0] === 'sha256='.hash_hmac('sha256', $waktu.'.'.$r->body(), 'rahasia')
            && $r['perintah'] === 'ACC'
            && $r['order_id'] === 'ORD-260928-014'
            && $r['catatan'] === null
            && $r['oleh']['nama'] === 'Falah'
            && $r['sumber'] === 'panel'
            && Str::isUuid($r['request_id']);
    });

    expect(ActivityLog::query()->sole()->only(['aksi', 'detail']))->toBe([
        'aksi' => 'ACC order',
        'detail' => 'ORD-260928-014 · invoice INV/2026/09/142 terkirim',
    ]);
});

test('the panel does not change the order itself', function () {
    Http::fake(['n8n.test/*' => Http::response(['ok' => true])]);

    $this->actingAs(User::factory()->falah()->create())->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC']);

    expect($this->order->refresh()->status_order)->toBe(StatusOrder::MenungguKonfirmasi)
        ->and($this->order->invoice_number)->toBeNull();
});

test('n8n refusing a command shows its reason', function () {
    Http::fake(['n8n.test/*' => Http::response(['ok' => false, 'alasan' => 'Order sudah di-ACC dari WhatsApp'], 409)]);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors(['aksi' => 'Order sudah di-ACC dari WhatsApp']);

    expect(ActivityLog::query()->count())->toBe(0);
});

test('n8n not answering leaves everything as it was', function (Closure $jawaban) {
    Http::fake(['n8n.test/*' => $jawaban]);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors(['aksi' => 'n8n tidak menjawab, perintah belum dijalankan. Coba lagi.']);

    expect(ActivityLog::query()->count())->toBe(0);
})->with([
    'timeout' => fn () => fn () => throw new ConnectionException('timed out'),
    'server error' => fn () => fn () => Http::response('Bad gateway', 502),
    'not JSON' => fn () => fn () => Http::response('Workflow was started'),
]);

test('without an address nothing is sent', function () {
    config(['services.n8n.webhook_url' => null]);
    Http::fake();

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors('aksi');

    Http::assertNothingSent();
});
