<?php

namespace App\Bot;

use RuntimeException;

/**
 * A command the bot did not carry out. The message is written for the admin.
 */
class PerintahGagal extends RuntimeException
{
    public static function tidakMenjawab(): self
    {
        return new self('n8n tidak menjawab, perintah belum dijalankan. Coba lagi.');
    }
}
