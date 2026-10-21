<?php

use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Jobs\KirimPesanWa;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use App\Models\Pesan;
use App\Models\TemplateBalasan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PengirimRekam;

beforeEach(function () {
    $this->falah = User::factory()->falah()->create();
});

test('a reply text is saved once as a template and can be deleted', function () {
    $this->actingAs($this->falah)->post('/percakapan/template', ['teks' => 'Halo kak {nama}, ada yang bisa dibantu?'])->assertRedirect();
    $this->actingAs($this->falah)->post('/percakapan/template', ['teks' => 'Halo kak {nama}, ada yang bisa dibantu?']);

    $template = TemplateBalasan::query()->sole();
    expect($template->user_id)->toBe($this->falah->id)
        ->and(ActivityLog::query()->where('aksi', 'Simpan template balasan')->count())->toBe(1);

    $this->actingAs($this->falah)->delete("/percakapan/template/{$template->id}")->assertRedirect();
    expect(TemplateBalasan::query()->count())->toBe(0);

    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))->post('/percakapan/template', ['teks' => 'x'])->assertForbidden();
});

test('the page lists templates, photos with a file, and the customer\'s first name', function () {
    TemplateBalasan::create(['teks' => 'Halo kak {nama}']);
    GambarProduk::create(['title' => 'Molreng Reguler', 'image_url' => 'https://cdn.test/molreng.jpg']);
    GambarProduk::create(['title' => 'Belum ada foto']);
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani']);

    $this->actingAs($this->falah)->get("/percakapan?pilih={$rina->id}")->assertInertia(fn (Assert $page) => $page
        ->where('template', [['id' => TemplateBalasan::query()->value('id'), 'teks' => 'Halo kak {nama}']])
        ->where('gambar', [['id' => GambarProduk::query()->where('title', 'Molreng Reguler')->value('id'), 'judul' => 'Molreng Reguler']])
        ->where('percakapan.panggilan', 'Rina'));
});

test('a reply can carry a product photo, with or without text', function () {
    Queue::fake();
    $foto = GambarProduk::create(['title' => 'Molreng Reguler', 'image_url' => 'https://cdn.test/molreng.jpg']);
    $tanpaFile = GambarProduk::create(['title' => 'Kosong']);
    $c = CrmCustomer::factory()->create(['nama' => 'Rina']);

    $this->actingAs($this->falah)->post("/percakapan/{$c->id}/balas", ['teks' => '', 'gambar' => $foto->id])->assertSessionHasNoErrors();

    expect(Pesan::query()->sole()->only(['pengirim', 'teks', 'gambar_produk_id', 'lampiran_keterangan']))->toBe([
        'pengirim' => PengirimPesan::Admin, 'teks' => null, 'gambar_produk_id' => $foto->id, 'lampiran_keterangan' => 'Foto produk · Molreng Reguler',
    ])->and(ActivityLog::query()->where('aksi', 'Balas manual')->value('detail'))->toBe('Rina · foto Molreng Reguler');

    $this->actingAs($this->falah)->post("/percakapan/{$c->id}/balas", ['teks' => '', 'gambar' => null])->assertSessionHasErrors(['teks' => 'Tulis balasannya dulu.']);
    $this->actingAs($this->falah)->post("/percakapan/{$c->id}/balas", ['teks' => 'x', 'gambar' => $tanpaFile->id])->assertSessionHasErrors(['gambar' => 'Foto ini belum punya file.']);
});

test('a reply with a photo goes to WhatsApp as the photo with a caption', function () {
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    $rekam = new PengirimRekam;
    $foto = GambarProduk::create(['title' => 'Molreng', 'image_url' => 'https://cdn.test/molreng.jpg', 'mime_type' => 'image/jpeg']);
    $c = CrmCustomer::factory()->create(['sesi_wa' => 'bangbana-2']);
    $denganFoto = Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => now(), 'pengirim' => PengirimPesan::Admin, 'teks' => 'Ini fotonya kak', 'gambar_produk_id' => $foto->id, 'status_kirim' => 'MENUNGGU']);
    $teksSaja = Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => now(), 'pengirim' => PengirimPesan::Admin, 'teks' => 'Halo', 'status_kirim' => 'MENUNGGU']);

    (new KirimPesanWa($denganFoto))->handle($rekam);
    (new KirimPesanWa($teksSaja))->handle($rekam);

    expect($rekam->terkirim)->toBe([
        ['sesi' => 'bangbana-2', 'chat_id' => $c->chat_id, 'teks' => 'Ini fotonya kak', 'gambar' => 'https://cdn.test/molreng.jpg'],
        ['sesi' => 'bangbana-2', 'chat_id' => $c->chat_id, 'teks' => 'Halo', 'gambar' => null],
    ]);
});
