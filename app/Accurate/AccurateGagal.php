<?php

namespace App\Accurate;

use RuntimeException;

/**
 * Accurate could not be read; the message is shown on Katalog → Barang toko.
 */
class AccurateGagal extends RuntimeException
{
    /**
     * @param  int|null  $status  the HTTP status Accurate answered with, when it answered
     */
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
