<?php

namespace App\Bot;

/**
 * What n8n answers when the panel hands it a customer's message (PESAN_MASUK): Adiva's structured
 * answer and what she read while making it, or the reason both tries failed.
 */
final readonly class JawabanAdiva
{
    /**
     * @param  array<string, mixed>  $agen  the agent's structured output (Format Output)
     * @param  array<string, mixed>  $src  what Siapkan Konteks gave the agent
     * @param  list<array<string, mixed>>  $orders  the customer's orders
     * @param  array<string, string|null>  $config  CONFIG_TOKO, key => value
     * @param  string  $galat  why the AI failed; empty when it did not
     */
    public function __construct(
        public array $agen,
        public array $src,
        public array $orders = [],
        public array $config = [],
        public bool $gagal = false,
        public string $galat = '',
    ) {}

    /**
     * @param  array<string, mixed>  $jawaban  what the webhook answered
     */
    public static function dariN8n(array $jawaban): self
    {
        $daftar = fn (mixed $v) => is_array($v) && array_is_list($v) ? $v : [];
        $config = [];

        foreach ($daftar($jawaban['config'] ?? null) as $r) {
            if (is_array($r) && filled($r['kunci'] ?? null)) {
                $config[(string) $r['kunci']] = $r['nilai'] ?? null;
            }
        }

        return new self(
            agen: is_array($jawaban['agen'] ?? null) ? $jawaban['agen'] : [],
            src: is_array($jawaban['src'] ?? null) ? $jawaban['src'] : [],
            orders: $daftar($jawaban['orders'] ?? null),
            config: $config,
            gagal: ($jawaban['gagal'] ?? false) === true,
            galat: (string) ($jawaban['galat'] ?? ''),
        );
    }
}
