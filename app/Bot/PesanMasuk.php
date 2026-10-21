<?php

namespace App\Bot;

/**
 * A WhatsApp event as WAHA's webhook sends it: `{ event, session, payload: { id, from, fromMe,
 * body, hasMedia, media, _data, … } }`. Only reads; nothing here changes the message.
 */
final readonly class PesanMasuk
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $sesi, public array $payload) {}

    /**
     * @param  array<string, mixed>  $body  the webhook's JSON
     */
    public static function dariWebhook(array $body): self
    {
        return new self((string) ($body['session'] ?? ''), is_array($body['payload'] ?? null) ? $body['payload'] : []);
    }

    /**
     * The chat id: "6281234567890@c.us", a group "…@g.us" or a hidden number "…@lid".
     */
    public function dari(): string
    {
        return (string) ($this->payload['from'] ?? '');
    }

    public function id(): string
    {
        return (string) ($this->payload['id'] ?? '');
    }

    public function body(): string
    {
        return (string) ($this->payload['body'] ?? '');
    }

    /**
     * Sent by the bot's own number (the echo of what it sent).
     */
    public function dariSaya(): bool
    {
        return ($this->payload['fromMe'] ?? false) === true;
    }

    public function grup(): bool
    {
        return str_ends_with($this->dari(), '@g.us');
    }

    public function status(): bool
    {
        return $this->dari() === 'status@broadcast';
    }

    public function lid(): bool
    {
        return str_ends_with($this->dari(), '@lid');
    }

    /**
     * The name the customer has on WhatsApp.
     */
    public function namaWa(): string
    {
        return (string) ($this->data()['notifyName'] ?? $this->payload['notifyName'] ?? '');
    }

    /**
     * WhatsApp's own message type ("chat", "image", "ptt", "location", …), lower case.
     */
    public function tipe(): string
    {
        return strtolower((string) ($this->data()['type'] ?? $this->payload['type'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return is_array($this->payload['_data'] ?? null) ? $this->payload['_data'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function media(): array
    {
        return is_array($this->payload['media'] ?? null) ? $this->payload['media'] : [];
    }

    /**
     * Carries a photo, file or voice note (or has no text at all).
     */
    public function adaLampiran(): bool
    {
        return ($this->payload['hasMedia'] ?? false) === true
            || ! empty($this->payload['media'])
            || ! empty($this->payload['mediaUrl'])
            || trim($this->body()) === '';
    }

    /**
     * @param  array<string, mixed>  $ubah
     */
    public function dengan(array $ubah): self
    {
        return new self($this->sesi, [...$this->payload, ...$ubah]);
    }

    /**
     * What a queue job keeps of it.
     *
     * @return array{session: string, payload: array<string, mixed>}
     */
    public function keArray(): array
    {
        return ['session' => $this->sesi, 'payload' => $this->payload];
    }
}
