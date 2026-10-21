<?php

namespace App\Http\Requests;

use App\Support\AturanBroadcast;
use App\Support\NomorBot;
use App\Support\PenerimaBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buat kampanye (BroadcastNew.dc.html): "Simpan draf", "Kirim sekarang" or
 * "Jadwalkan kampanye".
 *
 * @phpstan-import-type Filter from PenerimaBroadcast
 */
class SimpanBroadcastRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'aksi' => ['required', Rule::in(['draf', 'kirim', 'jadwal'])],
            'nama' => ['required', 'string', 'max:255'],
            'pesan' => ['required', 'string', 'max:4096'],
            'filter' => ['array'],
            'gambar' => ['nullable', 'integer', 'exists:gambar_produk,id'],
            'tombol' => ['array', 'max:3'],
            'tombol.*' => ['string', 'max:20'],
            'stop' => ['boolean'],
            'konteks' => ['boolean'],
            'kecepatan' => ['required', Rule::in(array_keys(AturanBroadcast::KECEPATAN))],
            'nomor_bot' => ['nullable', Rule::in(array_keys(NomorBot::daftar()))],
            'tanggal' => ['exclude_unless:aksi,jadwal', 'required', 'date_format:Y-m-d'],
            'jam' => ['exclude_unless:aksi,jadwal', 'required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Isi nama kampanye.',
            'pesan.required' => 'Isi pesan kampanye.',
            'tanggal.*' => 'Pilih tanggal kirim.',
            'jam.*' => 'Pilih jam kirim.',
        ];
    }

    /**
     * A campaign to send needs recipients, and a scheduled one a time still to come.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->input('aksi') === 'draf') {
                    return;
                }

                if (! PenerimaBroadcast::query($this->filter())->exists()) {
                    $validator->errors()->add('filter', 'Belum ada customer yang cocok dengan filter ini.');
                }

                if ($this->input('aksi') === 'jadwal' && $this->jadwal()?->isFuture() !== true) {
                    $validator->errors()->add('tanggal', 'Pilih waktu yang belum lewat.');
                }
            },
        ];
    }

    /**
     * @return Filter
     */
    public function filter(): array
    {
        return PenerimaBroadcast::normalisasi((array) $this->input('filter', []));
    }

    public function jadwal(): ?CarbonImmutable
    {
        return rescue(
            fn () => CarbonImmutable::createFromFormat('Y-m-d H:i', $this->string('tanggal').' '.$this->string('jam'), (string) config('app.timezone')) ?: null,
            null,
            false,
        );
    }
}
