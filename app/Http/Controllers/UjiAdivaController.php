<?php

namespace App\Http\Controllers;

use App\Bot\HasilUji;
use App\Bot\PerintahGagal;
use App\Bot\TanyaAdiva;
use App\Enums\SkorProspek;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\Produk;
use App\Models\PromptVersi;
use App\Models\UjiAdiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "Tanya Adiva" (Katalog, Kebijakan) and "Uji draf" (Prompt): Adiva answers in test
 * mode, nothing reaches WhatsApp or the CRM. The page then shows the saved test.
 */
class UjiAdivaController extends Controller
{
    /**
     * Katalog and Kebijakan: against the active prompt and the current data.
     */
    public function katalog(Request $request, TanyaAdiva $adiva): RedirectResponse
    {
        $data = $request->validate([
            'bagian' => ['required', Rule::in(['KATALOG', 'KEBIJAKAN'])],
            'pertanyaan' => ['required', 'string', 'max:1000'],
            'kebijakan_id' => ['nullable', 'integer', Rule::exists('kebijakan', 'id')],
        ], ['pertanyaan.required' => 'Tulis pertanyaan customer dulu.']);

        $kebijakan = $data['bagian'] === 'KEBIJAKAN' ? ($data['kebijakan_id'] ?? null) : null;
        $hasil = $this->tanya($adiva, $data['bagian'], $data['pertanyaan'], ['kebijakan_id' => $kebijakan]);

        $this->simpan($request, $data['bagian'], $data['pertanyaan'], $hasil, ['kebijakan_id' => $kebijakan]);

        Inertia::flash('toast', false);

        return back();
    }

    /**
     * Prompt: against the draft, before it is published.
     */
    public function prompt(Request $request, TanyaAdiva $adiva): RedirectResponse
    {
        $data = $request->validate(['pertanyaan' => ['required', 'string', 'max:1000']], ['pertanyaan.required' => 'Tulis pesan uji dulu.']);
        $draf = PromptVersi::query()->draf()->first();

        if (! $draf) {
            throw ValidationException::withMessages(['pertanyaan' => 'Belum ada draf untuk diuji.']);
        }

        $hasil = $this->tanya($adiva, 'PROMPT', $data['pertanyaan'], ['prompt' => ['versi' => $draf->versi, 'teks' => $draf->susunTeks()]]);

        $this->simpan($request, 'PROMPT', $data['pertanyaan'], $hasil, ['prompt_versi_id' => $draf->id]);

        Inertia::flash('toast', false);

        return back();
    }

    /**
     * @param  array{kebijakan_id?: int|null, prompt?: array{versi: int, teks: string}|null}  $konteks
     */
    private function tanya(TanyaAdiva $adiva, string $bagian, string $pertanyaan, array $konteks): HasilUji
    {
        try {
            return $adiva->tanya($bagian, $pertanyaan, $konteks);
        } catch (PerintahGagal $e) {
            throw ValidationException::withMessages(['pertanyaan' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, int|null>  $isi
     */
    private function simpan(Request $request, string $bagian, string $pertanyaan, HasilUji $hasil, array $isi): void
    {
        UjiAdiva::create([
            'bagian' => $bagian,
            'pertanyaan' => $pertanyaan,
            'jawaban' => $hasil->jawaban,
            'catatan' => $this->catatan($hasil),
            'hasil' => $hasil->hasil,
            'produk_id' => $hasil->produkId && Produk::query()->whereKey($hasil->produkId)->exists() ? $hasil->produkId : null,
            'user_id' => $request->user()?->id,
            ...$isi,
        ]);
    }

    /**
     * "Tool dipakai: KB_Kebijakan (Ongkir & ekspedisi) lalu Cek_Ongkir" and
     * "… · dinilai Reseller · Tanya harga · WARM" (Katalog.dc.html, Kebijakan.dc.html).
     */
    private function catatan(HasilUji $hasil): ?string
    {
        $nilai = $hasil->penilaian ?? [];
        $dinilai = collect([
            TipeCustomer::tryFrom((string) ($nilai['tipe'] ?? ''))?->label(),
            TahapFunnel::tryFrom((string) ($nilai['tahap'] ?? ''))?->label(),
            SkorProspek::tryFrom((string) ($nilai['skor'] ?? ''))?->value,
        ])->filter();

        return collect([
            $hasil->tool !== [] ? 'Tool dipakai: '.implode(' lalu ', $hasil->tool) : null,
            $dinilai->isNotEmpty() ? 'dinilai '.$dinilai->implode(' · ') : null,
        ])->filter()->implode(' · ') ?: null;
    }
}
