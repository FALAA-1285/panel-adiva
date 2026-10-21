<?php

namespace App\Http\Controllers;

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DemoController extends Controller
{
    /**
     * Sign in as Falah (Supervisor), the user shown in design/source.
     */
    public function login(Request $request): RedirectResponse
    {
        $user = User::firstOrCreate(
            ['email' => 'falah@bangbana.id'],
            [
                'name' => 'Falah',
                'peran' => Peran::Supervisor,
                'nomor_wa' => '6281234560007',
                'password' => Str::random(32),
            ],
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    /**
     * Preview a shared component on its own, sized like design/screens/<Kode>.png.
     */
    public function komponen(string $komponen): Response
    {
        return Inertia::render('demo/komponen-'.$komponen);
    }
}
