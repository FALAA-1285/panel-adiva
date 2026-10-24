<?php

use App\Enums\Peran;
use App\Models\CrmCustomer;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('guests get no panel data', function () {
    $this->get('/masuk')
        ->assertInertia(fn (Assert $page) => $page->where('panel', null));
});

test('the sidebar and topbar numbers come from the database', function () {
    $user = User::factory()->create(['name' => 'Dina', 'peran' => Peran::StafCs]);
    CrmCustomer::create(['chat_id' => '1@c.us', 'butuh_admin' => true]);
    CrmCustomer::create(['chat_id' => '2@c.us', 'butuh_admin' => false]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.hitungan.percakapan', 1)
            ->where('panel.hitungan.pesanan', 0)
            ->where('panel.status.nomorWaha', 0)
            ->where('panel.pengguna', ['nama' => 'Dina', 'inisial' => 'D', 'peran' => 'Staf CS']));
});

test('demo data reproduces the numbers drawn in Sidebar.dc.html and Topbar.dc.html', function () {
    $this->seed(DemoSeeder::class);

    $this->get(route('demo.login'))->assertRedirect('/');

    $this->get(route('demo.komponen', 'sidebar'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('demo/komponen-sidebar')
            ->where('panel.waktu', 'Senin, 28 Sep · 13:42 WIB')
            ->where('panel.hitungan', [
                'percakapan' => 6,
                // the design says 4, but Pesanan (Orders.dc.html) lists only 2 waiting for
                // ACC and filler orders would show up there as rows
                'pesanan' => 2,
                'jeda' => 3,
                'monitoring' => 2,
                'notifikasi' => 4,
                'sosmed' => 0,
            ])
            ->where('panel.status', ['nomorWaha' => 2, 'workflowAktif' => 7, 'dijeda' => 3, 'adivaAktif' => true])
            ->where('panel.pengguna', ['nama' => 'Falah', 'inisial' => 'F', 'peran' => 'Supervisor']));
});
