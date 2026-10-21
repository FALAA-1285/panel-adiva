<?php

namespace App\Bot;

use Illuminate\Support\Facades\DB;

/**
 * The bot's reads and writes of the customer and order rows go through the database functions
 * and views of docs/kontrak-n8n.md §8 (`n8n_simpan_crm`, `n8n_catat_order`, `n8n_ubah_order`,
 * `n8n_log_order`): they already turn the bot's text values ("YA", "2026-09-28 13:42", "Rp35.000")
 * into the panel's columns and keep its extra ones right (tahap_sejak, the order's items, the
 * campaign a reply belongs to), and AdaptorN8nTest covers them. What the bot decides (what to
 * write, and whether) is PHP; how a row is written is not repeated here.
 */
class LapisanData
{
    /**
     * @param  array<string, mixed>  $j
     * @return array<string, mixed>|null the customer row as n8n saw it
     */
    public static function simpanCrm(array $j, bool $buat = true): ?array
    {
        return self::satu('SELECT * FROM n8n_simpan_crm(?::jsonb, '.($buat ? 'true' : 'false').')', [self::json($j)]);
    }

    /**
     * @param  array<string, mixed>  $j
     * @return array<string, mixed>|null
     */
    public static function catatOrder(array $j): ?array
    {
        return self::satu('SELECT * FROM n8n_catat_order(?::jsonb)', [self::json($j)]);
    }

    /**
     * @param  array<string, mixed>  $j
     * @return array<string, mixed>|null
     */
    public static function ubahOrder(array $j): ?array
    {
        return self::satu('SELECT * FROM n8n_ubah_order(?::jsonb)', [self::json($j)]);
    }

    /**
     * The customer's orders, oldest first, with the words and times the bot reads them in.
     *
     * @return list<array<string, mixed>>
     */
    public static function orderChat(string $chatId): array
    {
        return array_values(array_map(fn ($r) => (array) $r, DB::select('SELECT * FROM n8n_log_order WHERE chat_id = ? ORDER BY id', [$chatId])));
    }

    /**
     * @param  array<string, mixed>  $j
     */
    private static function json(array $j): string
    {
        return (string) json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<string>  $ikat
     * @return array<string, mixed>|null
     */
    private static function satu(string $sql, array $ikat): ?array
    {
        $baris = DB::select($sql, $ikat);

        return $baris === [] ? null : (array) $baris[0];
    }
}
